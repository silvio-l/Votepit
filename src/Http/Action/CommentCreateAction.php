<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Votepit\Domain\ContentModerationService;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\AccountMailLinks;
use Votepit\Mail\CommentMailTexts;
use Votepit\Mail\CommentNotificationMailer;
use Votepit\Mail\MailLanguage;
use Votepit\Participant\ParticipantMailCategory;
use Votepit\Participant\ParticipantMailRouter;
use Votepit\Persistence\AccountMemberStandingRepository;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\Turnstile\TurnstileGuard;

/**
 * POST /{board}/ideas/{id}/comments — create a comment.
 *
 * AuthZ: user (via AuthZMiddleware::user() in AppFactory).
 * CSRF: globally enforced (CsrfMiddleware in the POST path).
 * RateLimit `comment:user`: per-action rate limit (attached in AppFactory).
 *
 * Structurally board-scoped: the idea is loaded board-scoped via
 * IdeaRepository::findInBoard() — unknown slug or idea outside the board
 * → 404, no row is created. CommentRepository itself knows no board_id
 * (see the class doc there).
 *
 * Validation: body 1..2000 characters (Symfony Validator). On error → 422
 * + the unified JSON error contract. Success → 201 JSON `{"ok": true, "id": N}`.
 *
 * Stored verbatim as plain text; the frontend renders a small safe
 * Markdown-lite subset of it (see MarkdownLite.tsx) — never raw HTML.
 * Runs through the same word-filter moderation as ideas (board toggle +
 * custom word list), reusing ContentModerationService.
 *
 * Anti-spam: rejects a comment if the SAME user's comment is already the
 * most recent one on this idea (nobody else has replied in between) —
 * "you can't comment twice in a row" — via
 * CommentRepository::findLastForIdea(), independent of elapsed time.
 *
 * Board-scoped user-block guard directly here (no central middleware),
 * because the board is already loaded at this point — the accountwide
 * check additionally and unchangedly runs via BlockCheckMiddleware.
 *
 * Repeat-offender suspension (board-operator-compliance issue 10): same
 * `member_suspended` dedicated-error-key gate as IdeaCreateAction — see
 * that class's doc comment.
 *
 * Anti-automation challenge (OWASP/BSI audit remediation issue 20):
 * TurnstileGuard — invisible to ordinary users, only engaged once the
 * requester's IP has repeatedly tripped the existing rate limits. See
 * TurnstileGuard's class doc.
 */
final readonly class CommentCreateAction
{
    /** Turnstile response-token form field name — see IdeaCreateAction::TURNSTILE_FIELD. */
    public const TURNSTILE_FIELD = 'turnstile_token';

    public function __construct(
        private Connection $conn,
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private CommentRepository $commentRepo,
        private AuditLogger $audit,
        private ContentModerationService $moderation,
        private BlockRepository $blockRepo,
        private NotificationRepository $notifications,
        private UserRepository $userRepo,
        private CommentNotificationMailer $notificationMailer,
        private TurnstileGuard $turnstileGuard,
        private BoardStatusRepository $statusRepo,
        private ParticipantMailRouter $participantMail,
        private AccountMailLinks $mailLinks,
        private ?ModerationConfigRepository $moderationConfigRepo = null,
        private ?AccountMemberStandingRepository $standingRepo = null,
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
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $boardId = (int) $board['id'];
        $ideaId  = (int) ($args['id'] ?? 0);

        $idea = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Idea not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Status-driven phase (broaden-use-cases issue 03): a status with
        // allow_comments = false closes the discussion; existing comments stay.
        if ($this->statusRepo->registryFor($board)->find((string) $idea['status_key'])?->allowComments === false) {
            return JsonError::respond($response, 423, 'status_closed_for_comments', 'Comments are closed for ideas in this status.');
        }

        /** @var array<string, mixed> $user */
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = (int) ($user['id'] ?? 0);

        if ($this->blockRepo->isBlocked($accountId, $userId, $boardId)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'blocked', 'message' => 'You are blocked from this board.'],
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $standing = $this->standingRepo?->findFor($accountId, $userId);
        if (is_array($standing) && ($standing['state'] ?? null) === 'suspended') {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'member_suspended', 'message' => 'You are currently suspended from submitting content in this account.'],
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $lastComment = $this->commentRepo->findLastForIdea($ideaId);
        if ($lastComment !== null && $lastComment['author_id'] === $userId) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'consecutive_comment',
                    'message' => 'You already have the latest comment here. Edit it or wait for a reply before commenting again.',
                ],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // Anti-automation challenge (OWASP/BSI audit remediation issue 20)
        // — see TurnstileGuard's class doc. Only engaged once this IP has
        // repeatedly tripped the existing rate limits.
        if ($this->turnstileGuard->requiresChallengeFor($request)) {
            $parsedForToken = $request->getParsedBody();
            $turnstileToken = is_array($parsedForToken) ? (string) ($parsedForToken[self::TURNSTILE_FIELD] ?? '') : '';
            if (!$this->turnstileGuard->verifyFor($request, $turnstileToken)) {
                $response->getBody()->write((string) json_encode([
                    'error' => [
                        'key'      => 'turnstile_required',
                        'message'  => 'Please complete the verification challenge to continue.',
                        'site_key' => $this->turnstileGuard->siteKey(),
                    ],
                ]));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }
        }

        $parsed  = $request->getParsedBody();
        $rawBody = is_array($parsed) ? trim((string) ($parsed['body'] ?? '')) : '';

        $validator  = Validation::createValidator();
        $bodyErrors = $validator->validate($rawBody, [
            new Assert\NotBlank(message: 'The comment must not be empty.'),
            new Assert\Length(
                min: 1,
                max: 500,
                maxMessage: 'The comment must be at most {{ limit }} characters long.',
            ),
        ]);

        /** @var array<string, string> $fields */
        $fields = [];
        foreach ($bodyErrors as $e) {
            $fields['body'] = (string) $e->getMessage();
            break;
        }

        if ($fields !== []) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => $fields,
                    'values'  => ['body' => $rawBody],
                ],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // Moderation hard block: the same board word-filter policy as for ideas.
        $moderationEnabled = !$this->moderationConfigRepo instanceof ModerationConfigRepository
            || $this->moderationConfigRepo->isModerationEnabled($boardId);

        $effectiveModeration = $this->moderation;
        if ($moderationEnabled && $this->moderationConfigRepo instanceof ModerationConfigRepository) {
            $customWords = $this->moderationConfigRepo->wordList($boardId);
            if ($customWords !== []) {
                $effectiveModeration = $this->moderation->withAdditionalWords($customWords);
            }
        }

        $modResult = $moderationEnabled ? $effectiveModeration->check($rawBody) : ['clean' => true, 'hits' => []];
        if (!$modResult['clean']) {
            $this->audit->log('comment.moderation_blocked', [
                'board_id'  => $boardId,
                'idea_id'   => $ideaId,
                'hit_count' => count($modResult['hits']),
            ]);

            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'moderation_blocked',
                    'message' => 'Your text contains disallowed terms. Please rephrase it.',
                    'fields'  => [],
                ],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // Comment insert + notification fan-out share one transaction (both-or-
        // nothing, matches the "reuse first" no-central-event-bus decision:
        // CommentCreateAction calls NotificationRepository directly, no
        // dispatcher). Actual email
        // sending is deliberately NOT part of this transaction (network I/O)
        // — eligible recipients are collected here and mailed afterwards,
        // once the comment/notification rows are durably committed.
        /** @var list<array{email: string, title: string, body: string, cta: string, button_fallback: string, link_path: string, headers: array<string, string>, unsubscribe: array{label: string, url: string}}> $emailCandidates */
        $emailCandidates = [];
        $commentId = $this->conn->transactional(function () use ($accountId, $boardId, $ideaId, $idea, $userId, $rawBody, $slug, &$emailCandidates): int {
            $commentId = $this->commentRepo->create($ideaId, $userId, $rawBody);

            $ideaAuthorId = (int) $idea['author_id'];
            $ideaTitle    = (string) $idea['title'];
            $linkPath     = "/{$slug}/idea/{$ideaId}#comment-{$commentId}";
            $now          = new \DateTimeImmutable();

            // idea_comment: the idea's author, unless they're the commenter
            // themselves (Story 8) or blocked in this account/board.
            if ($ideaAuthorId !== $userId && !$this->blockRepo->isBlocked($accountId, $ideaAuthorId, $boardId)) {
                $notice = self::ideaCommentNotice($ideaTitle);
                $this->notifyRecipient(
                    $accountId,
                    $boardId,
                    $ideaId,
                    $ideaAuthorId,
                    'idea_comment',
                    $notice['title'],
                    $notice['body'],
                    $linkPath,
                    $ideaTitle,
                    $now,
                    $emailCandidates,
                );
            }

            // thread_reply: every distinct prior commenter, excluding the new
            // commenter (Story 9) and the idea author (already covered
            // exclusively above — deduplication, Story 10).
            $priorCommenterIds = $this->commentRepo->distinctPriorAuthorIds($ideaId, [$userId, $ideaAuthorId]);
            $replyNotice       = self::threadReplyNotice($ideaTitle);
            foreach ($priorCommenterIds as $recipientId) {
                if ($this->blockRepo->isBlocked($accountId, $recipientId, $boardId)) {
                    continue;
                }
                $this->notifyRecipient(
                    $accountId,
                    $boardId,
                    $ideaId,
                    $recipientId,
                    'thread_reply',
                    $replyNotice['title'],
                    $replyNotice['body'],
                    $linkPath,
                    $ideaTitle,
                    $now,
                    $emailCandidates,
                );
            }

            return $commentId;
        });

        foreach ($emailCandidates as $candidate) {
            $this->notificationMailer->send(
                $candidate['email'],
                $candidate['title'],
                $candidate['body'],
                $candidate['link_path'],
                $boardId,
                $candidate['headers'],
                $candidate['unsubscribe'],
                $candidate['cta'],
                $candidate['button_fallback'],
            );
        }

        $this->audit->log('comment.created', ['board_id' => $boardId, 'idea_id' => $ideaId, 'comment_id' => $commentId]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'id' => $commentId]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Title and body of the idea_comment notification (shared with
     * bin/send-mail-previews.php).
     *
     * @return array{title: string, body: string}
     */
    public static function ideaCommentNotice(string $ideaTitle): array
    {
        return CommentMailTexts::ideaComment(MailLanguage::DEFAULT, $ideaTitle);
    }

    /**
     * Title and body of the thread_reply notification (shared with
     * bin/send-mail-previews.php).
     *
     * @return array{title: string, body: string}
     */
    public static function threadReplyNotice(string $ideaTitle): array
    {
        return CommentMailTexts::threadReply(MailLanguage::DEFAULT, $ideaTitle);
    }

    /**
     * Creates the in-app notification row (only if the recipient's
     * `*_inapp` flag for $type is on) — unchanged. The e-mail channel is
     * decided by ParticipantMailRouter (close-the-loop slice 03): the author's
     * comment mails default to instant, thread replies to the batched digest,
     * and a category set to off sends nothing. An e-mail needs a confirmed
     * notification_email (UserRepository::findNotificationSettings()). The two
     * channels stay independent (PRD Story 3).
     *
     * @param list<array{email: string, title: string, body: string, cta: string, button_fallback: string, link_path: string, headers: array<string, string>, unsubscribe: array{label: string, url: string}}> $emailCandidates
     */
    private function notifyRecipient(
        int $accountId,
        int $boardId,
        int $ideaId,
        int $recipientId,
        string $type,
        string $title,
        string $body,
        string $linkPath,
        string $ideaTitle,
        \DateTimeImmutable $now,
        array &$emailCandidates,
    ): void {
        $settings = $this->userRepo->findNotificationSettings($recipientId);
        if (!is_array($settings)) {
            return;
        }

        $inAppFlag = $type === 'idea_comment' ? 'notify_idea_comment_inapp' : 'notify_thread_reply_inapp';

        if ((bool) ($settings[$inAppFlag] ?? false)) {
            $this->notifications->createForUser($accountId, $recipientId, $type, $title, $body, $linkPath);
        }

        $notificationEmail = is_string($settings['notification_email'] ?? null) ? $settings['notification_email'] : null;
        if ($notificationEmail === null) {
            return;
        }

        $category = $type === 'idea_comment' ? ParticipantMailCategory::MY_IDEAS : ParticipantMailCategory::PARTICIPATED;
        $eventKind = $type === 'idea_comment' ? ParticipantMailRouter::EVENT_COMMENT : ParticipantMailRouter::EVENT_THREAD_REPLY;
        // The in-app link above stays relative; the mail needs the account
        // segment in cloud path routing (see AccountMailLinks).
        $mailLinkPath = $this->mailLinks->path($accountId, $linkPath);
        $result = $this->participantMail->routeUser($recipientId, $category, $boardId, $ideaId, $eventKind, ['title' => $ideaTitle], $mailLinkPath, $now);
        if ($result !== ParticipantMailRouter::RESULT_INSTANT) {
            return;
        }

        // The mail copy follows the recipient's stored language; the in-app
        // notification above keeps the $title/$body it was given.
        $language = MailLanguage::resolve($settings['digest_language'] ?? null);
        $mailCopy = $type === 'idea_comment'
            ? CommentMailTexts::ideaComment($language, $ideaTitle)
            : CommentMailTexts::threadReply($language, $ideaTitle);

        $emailCandidates[] = [
            'email'           => $notificationEmail,
            'title'           => $mailCopy['title'],
            'body'            => $mailCopy['body'],
            'cta'             => CommentMailTexts::cta($language),
            'button_fallback' => CommentMailTexts::buttonFallback($language),
            'link_path'       => $mailLinkPath,
            'headers'         => $this->participantMail->unsubscribeHeaders($recipientId, null, $category),
            'unsubscribe'     => ['label' => CommentMailTexts::unsubscribeLabel($language), 'url' => $this->participantMail->unsubscribePageUrl($recipientId, null, $category)],
        ];
    }
}
