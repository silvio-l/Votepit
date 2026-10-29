<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Config;
use Votepit\Domain\ModerationStandingService;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\CommentNotificationMailer;
use Votepit\Persistence\AccountMemberStandingRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationRepository;
use Votepit\Persistence\UserRepository;

/**
 * POST /{board}/ideas/{id}/moderate — remove an idea (moderation,
 * board-operator-compliance issue 07). Idea-side counterpart of
 * CommentModerationAction — same request/response contract, same
 * reason-code enum, same content_moderation_actions table
 * (entity_type = 'idea').
 *
 * AuthZ: accountModerate (via AuthZMiddleware::accountModerate() in
 * AppFactory — owner/admin/moderator; anon → 401, missing account role →
 * 403).
 * CSRF: globally enforced (CsrfMiddleware in the POST path).
 *
 * Structurally board-scoped: the idea is loaded board-scoped via
 * IdeaRepository::findInBoard() — unknown slug, idea outside the board, OR
 * one already soft-deleted by an earlier moderation action, is never
 * addressable (404, same "invisible = not found" convention as
 * CommentModerationAction / IdeaPinAction / IdeaStatusAction /
 * IdeaWithdrawAction).
 *
 * Route is deliberately NOT /{board}/ideas/{id}/withdraw — that path
 * already exists (IdeaWithdrawAction, author-initiated hard delete of
 * their own idea); this is a distinct, moderator-initiated, reason-coded
 * soft delete.
 *
 * Reason-coded, soft delete: the request MUST supply reason_code (one of
 * the 7 values the frontend moderation form and
 * content_moderation_actions.reason_code share), optionally reason_note
 * (free text, capped — see MAX_REASON_NOTE_LENGTH). A
 * content_moderation_actions row is written FIRST (content_snapshot = the
 * idea's title and body combined — an idea has both, unlike a comment
 * which only has a body — so an appeal reviewer sees the full removed
 * content), then the idea is soft-deleted and linked to that row via
 * moderation_action_id.
 *
 * actor_tier is always 'board_moderator' here — same reasoning as
 * CommentModerationAction.
 *
 * Author notification: best-effort email to the idea author's confirmed
 * notification_email, reusing CommentNotificationMailer (already fully
 * generic — title/body/link parameters, no comment-specific coupling
 * despite its name) exactly like CommentModerationAction does, including a
 * link to the appeal-submission page (board-operator-compliance issue 09).
 * A missing/unconfirmed address, or any mail-send failure, is swallowed
 * (audit-logged) — the moderation action itself has already succeeded.
 *
 * Repeat-offender escalation (board-operator-compliance issue 10): same
 * unconditional, synchronous call as CommentModerationAction — see that
 * class's doc comment.
 *
 * Always responds JSON { ok: true, moderation_action_id: int } (status 200).
 */
final readonly class IdeaModerationAction
{
    private const ALLOWED_REASON_CODES = [
        'community_rule',
        'illegal_copyright',
        'illegal_privacy',
        'illegal_threat',
        'illegal_fraud',
        'illegal_other',
        'other',
    ];

    private const MAX_REASON_NOTE_LENGTH = 2000;

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private ModerationRepository $moderationRepo,
        private UserRepository $userRepo,
        private CommentNotificationMailer $notificationMailer,
        private AuditLogger $audit,
        private AccountRepository $accountRepo,
        private Config $config,
        private AccountMemberStandingRepository $standingRepo,
        private ModerationStandingService $standingService,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            return $this->json($response, 404, [
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]);
        }

        $boardId = (int) $board['id'];
        $ideaId  = (int) ($args['id'] ?? 0);

        // includePending: rejecting a pending idea (pre-moderation issue 05)
        // IS this moderation soft-delete — same reason code, same author
        // mail + appeal path, so the queue's "reject" reuses this route.
        $idea = $this->ideaRepo->findInBoard($boardId, $ideaId, null, includePending: true);
        if (!is_array($idea)) {
            return $this->json($response, 404, [
                'error' => ['key' => 'not_found', 'message' => 'Idea not found.'],
            ]);
        }

        $parsed = $request->getParsedBody();
        $body   = is_array($parsed) ? $parsed : [];

        $reasonCode = trim((string) ($body['reason_code'] ?? ''));
        $reasonNote = isset($body['reason_note']) && is_string($body['reason_note']) ? trim($body['reason_note']) : null;
        if ($reasonNote === '') {
            $reasonNote = null;
        }

        /** @var array<string, string> $fields */
        $fields = [];
        if (!in_array($reasonCode, self::ALLOWED_REASON_CODES, true)) {
            $fields['reason_code'] = 'Please choose a valid reason.';
        }
        if ($reasonNote !== null && mb_strlen($reasonNote) > self::MAX_REASON_NOTE_LENGTH) {
            $fields['reason_note'] = "The note must be at most " . self::MAX_REASON_NOTE_LENGTH . ' characters long.';
        }

        if ($fields !== []) {
            return $this->json($response, 422, [
                'error' => ['key' => 'validation_error', 'message' => 'Validation failed.', 'fields' => $fields],
            ]);
        }

        /** @var array<string, mixed>|null $user */
        $user    = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $actorId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

        // content_snapshot: title + body combined — an idea has both,
        // unlike a comment (body only), so an appeal reviewer sees the
        // full removed content.
        $contentSnapshot = $idea['title'] . "\n\n" . $idea['body'];

        $moderationActionId = $this->moderationRepo->recordAction(
            'idea',
            $ideaId,
            $boardId,
            $accountId,
            $actorId,
            'board_moderator',
            $reasonCode,
            $reasonNote,
            $contentSnapshot,
        );

        $this->ideaRepo->softDelete($boardId, $ideaId, $moderationActionId);

        $authorId = (int) $idea['author_id'];
        // Guest ideas (ADR 0006 §1): the shared placeholder stands for many
        // unrelated people — no strike/suspension, no mail. The reason code
        // still reaches the submitting device via guest-mine.
        $isGuest  = $this->userRepo->isGuestPlaceholder($authorId);
        if (!$isGuest) {
            $standing = $this->standingRepo->findFor($accountId, $authorId);
            $next     = $this->standingService->computeNextState($standing, $this->config->moderationEscalation());
            if ($this->standingRepo->upsertAutomatic($accountId, $authorId, $next['violation_count'], $next['state'])) {
                // Losing good standing clears a trusted-author mark (issue 11).
                $this->audit->log('member.trust_revoked', ['account_id' => $accountId, 'user_id' => $authorId, 'actor_id' => $actorId, 'reason' => 'standing_' . $next['state']]);
            }
        }

        $this->audit->log('idea.moderated_delete', [
            'board_id'             => $boardId,
            'idea_id'              => $ideaId,
            'moderation_action_id' => $moderationActionId,
            'reason_code'          => $reasonCode,
            'actor_id'             => $actorId,
        ]);

        if (!$isGuest) {
            $this->notifyAuthor($authorId, $reasonCode, $moderationActionId, $accountId);
        }

        return $this->json($response, 200, ['ok' => true, 'moderation_action_id' => $moderationActionId]);
    }

    /**
     * Best-effort author notification — never lets a mail failure turn an
     * already-successful moderation action into a 500 (same reasoning as
     * CommentModerationAction::notifyAuthor()).
     */
    private function notifyAuthor(int $authorId, string $reasonCode, int $moderationActionId, int $accountId): void
    {
        try {
            $settings = $this->userRepo->findNotificationSettings($authorId);
            if (!is_array($settings)) {
                return;
            }

            $notificationEmail = is_string($settings['notification_email'] ?? null) ? $settings['notification_email'] : null;
            if ($notificationEmail === null) {
                return;
            }

            $notice = self::removedNotice($reasonCode);
            $this->notificationMailer->send(
                $notificationEmail,
                $notice['title'],
                $notice['body'],
                $this->appealLinkPath($moderationActionId, $accountId),
                null,
            );
        } catch (\Throwable $e) {
            $this->audit->log('idea.moderation_notification_mail_failed', [
                'moderation_action_id' => $moderationActionId,
                'error'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * Builds the appeal-submission link path (board-operator-compliance
     * issue 09) — same reasoning as CommentModerationAction::appealLinkPath().
     */
    private function appealLinkPath(int $moderationActionId, int $accountId): string
    {
        if ($this->config->routingMode !== 'cloud') {
            return '/appeal/' . $moderationActionId;
        }

        $account = $this->accountRepo->findById($accountId);
        $slug    = is_array($account) ? (string) $account['slug'] : null;

        return $slug !== null ? '/' . $slug . '/appeal/' . $moderationActionId : '/appeal/' . $moderationActionId;
    }

    /**
     * Title and body of the "your idea was removed" mail (shared with
     * bin/send-mail-previews.php).
     *
     * @return array{title: string, body: string}
     */
    public static function removedNotice(string $reasonCode): array
    {
        return [
            'title' => 'Your idea was removed',
            'body'  => 'A moderator removed one of your ideas. Reason: ' . self::reasonLabel($reasonCode)
                . '. If you believe this was a mistake, you can appeal this removal.',
        ];
    }

    private static function reasonLabel(string $reasonCode): string
    {
        return match ($reasonCode) {
            'community_rule'     => 'violates the board\'s community rules',
            'illegal_copyright'  => 'infringes copyright',
            'illegal_privacy'    => 'violates someone\'s privacy',
            'illegal_threat'     => 'contains a threat',
            'illegal_fraud'      => 'is fraudulent',
            'illegal_other'      => 'is illegal content',
            default              => 'other',
        };
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
