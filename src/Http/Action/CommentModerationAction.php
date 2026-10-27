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
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationRepository;
use Votepit\Persistence\UserRepository;

/**
 * POST /{board}/ideas/{id}/comments/{commentId}/delete — remove a comment
 * (moderation, board-operator-compliance issue 06).
 *
 * AuthZ: accountModerate (via AuthZMiddleware::accountModerate() in
 * AppFactory — owner/admin/moderator; anon → 401, missing account role →
 * 403).
 * CSRF: globally enforced (CsrfMiddleware in the POST path).
 *
 * Structurally board-scoped: the idea is loaded board-scoped via
 * IdeaRepository::findInBoard() — unknown slug or idea outside the board
 * → 404 (no cross-board leak, no mutation). The comment is additionally
 * loaded idea-scoped via CommentRepository::findForIdea() — a comment of
 * a foreign idea, OR one already soft-deleted by an earlier moderation
 * action, is never addressable (404, analogous to IdeaPinAction/
 * IdeaStatusAction and to every other "invisible = not found" surface in
 * this codebase).
 *
 * Reason-coded, soft delete (replaces the previous unconditional hard
 * delete): the request MUST supply reason_code (one of the 7 values the
 * frontend moderation form and content_moderation_actions.reason_code
 * share), optionally reason_note (free text, capped — see
 * MAX_REASON_NOTE_LENGTH). A content_moderation_actions row is written
 * FIRST (content_snapshot = the comment's current body, so an appeal
 * reviewer — issue 07 — can see what was removed), then the comment is
 * soft-deleted and linked to that row via moderation_action_id.
 *
 * actor_tier is always 'board_moderator' here — every role that reaches
 * this action (owner/admin/moderator, gated by accountModerate) acts in
 * the capacity of "this account's board operator", not as Votepit itself.
 * 'operator' is reserved for a possible later platform-wide Trust & Safety
 * moderation action (not built as part of this ticket — see
 * ModerationRepository's doc comment).
 *
 * Author notification: best-effort email to the comment author's
 * confirmed notification_email (UserRepository::findNotificationSettings()
 * — the same opt-in, HMAC-identity-independent channel CommentCreateAction/
 * IdeaStatusAction already use for other transactional mail), stating the
 * reason code and linking to the appeal-submission page
 * (board-operator-compliance issue 09, ModerationAppealAction::submit()'s
 * frontend counterpart). A missing/unconfirmed address, or any mail-send
 * failure, is swallowed (audit-logged) — the moderation action itself has
 * already succeeded and must not 500 because notifying the author failed
 * (same pattern as AbuseReportAction).
 *
 * Repeat-offender escalation (board-operator-compliance issue 10): after
 * recordAction() below, the comment AUTHOR's (not the moderator's)
 * account_member_standing is advanced one violation via
 * ModerationStandingService::computeNextState() +
 * AccountMemberStandingRepository::upsertAutomatic() — see the call site
 * for why this happens unconditionally, synchronously, in the same
 * request (no queue/best-effort wrapping, unlike the notification mail
 * below: a missed escalation would silently defeat the suspension gate).
 *
 * Always responds JSON { ok: true, moderation_action_id: int } (status 200).
 */
final readonly class CommentModerationAction
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
        private CommentRepository $commentRepo,
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

        $boardId   = (int) $board['id'];
        $ideaId    = (int) ($args['id'] ?? 0);
        $commentId = (int) ($args['commentId'] ?? 0);

        $idea = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            return $this->json($response, 404, [
                'error' => ['key' => 'not_found', 'message' => 'Idea not found.'],
            ]);
        }

        $comment = $this->commentRepo->findForIdea($ideaId, $commentId);
        if (!is_array($comment)) {
            return $this->json($response, 404, [
                'error' => ['key' => 'not_found', 'message' => 'Comment not found.'],
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

        $moderationActionId = $this->moderationRepo->recordAction(
            'comment',
            $commentId,
            $boardId,
            $accountId,
            $actorId,
            'board_moderator',
            $reasonCode,
            $reasonNote,
            (string) $comment['body'],
        );

        $this->commentRepo->softDelete($ideaId, $commentId, $moderationActionId);

        $authorId = (int) $comment['author_id'];
        $standing = $this->standingRepo->findFor($accountId, $authorId);
        $next     = $this->standingService->computeNextState($standing, $this->config->moderationEscalation());
        if ($this->standingRepo->upsertAutomatic($accountId, $authorId, $next['violation_count'], $next['state'])) {
            // Losing good standing clears a trusted-author mark (issue 11).
            $this->audit->log('member.trust_revoked', ['account_id' => $accountId, 'user_id' => $authorId, 'actor_id' => $actorId, 'reason' => 'standing_' . $next['state']]);
        }

        $this->audit->log('comment.moderated_delete', [
            'board_id'             => $boardId,
            'idea_id'              => $ideaId,
            'comment_id'           => $commentId,
            'moderation_action_id' => $moderationActionId,
            'reason_code'          => $reasonCode,
            'actor_id'             => $actorId,
        ]);

        $this->notifyAuthor((int) $comment['author_id'], $reasonCode, $moderationActionId, $accountId);

        return $this->json($response, 200, ['ok' => true, 'moderation_action_id' => $moderationActionId]);
    }

    /**
     * Best-effort author notification — never lets a mail failure turn an
     * already-successful moderation action into a 500 (same reasoning as
     * AbuseReportAction::submit()'s operator-mail try/catch).
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
            $this->audit->log('comment.moderation_notification_mail_failed', [
                'moderation_action_id' => $moderationActionId,
                'error'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * Builds the appeal-submission link path (board-operator-compliance
     * issue 09) — mirrors the backend's own $accountPrefix convention
     * (AppFactory.php): self-host links to the unprefixed frontend route
     * `/appeal/{id}`; cloud mode needs the account slug ahead of it
     * (`/{accountSlug}/appeal/{id}`, App.tsx's scoped route), otherwise the
     * SPA would resolve the link in whatever account context the clicking
     * browser happens to already be in (or none) instead of the one this
     * moderation action actually belongs to. Falls back to the unprefixed
     * path if the account has since been deleted (defensive — should be
     * unreachable, this account_id was valid moments ago when the
     * moderation action was recorded).
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
     * Title and body of the "your comment was removed" mail (shared with
     * bin/send-mail-previews.php).
     *
     * @return array{title: string, body: string}
     */
    public static function removedNotice(string $reasonCode): array
    {
        return [
            'title' => 'Your comment was removed',
            'body'  => 'A moderator removed one of your comments. Reason: ' . self::reasonLabel($reasonCode)
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
