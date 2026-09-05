<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\StatusService;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\CommentNotificationMailer;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\VoteRepository;

/**
 * POST /{board}/ideas/{id}/status — set idea status (admin-only).
 *
 * AuthZ: admin (via AuthZMiddleware::admin() in AppFactory; anon → 401, non-admin → 403).
 * CSRF: globally enforced (CsrfMiddleware in the POST path).
 * BlockCheck: global (blocked user → 403, before the action runs).
 * RateLimit: perAction('idea:status') in AppFactory.
 *
 * Structurally board-scoped: the idea is loaded board-scoped via
 * findInBoard() — unknown slug or idea outside the board → 404 (no
 * cross-board leak, no status row is created).
 *
 * Input `status` ∈ StatusService::VALID_STATUSES; invalid values or a
 * disallowed transition → 422, idea unchanged.
 *
 * Self→self is an idempotent no-op: 200, no DB write, no audit entry.
 *
 * Always responds JSON { ok: true, status: string } (status 200).
 *
 * idea-status-follow-notification: a REAL status change (from !==
 * to, valid transition) fans out an 'idea_status_changed' notification to
 * the idea's author + every DISTINCT voter + every DISTINCT commenter,
 * deduplicated, excluding the triggering admin — same "no central event
 * bus" fan-out pattern already used by CommentCreateAction for
 * idea_comment/thread_reply (status update + notification inserts share
 * ONE transaction; email sending happens afterwards, once committed, via
 * the same shared global mail rate-limit bucket). A no-op or an invalid
 * transition returns before ever reaching this fan-out, so neither creates
 * any notification (matches the pre-existing "no DB write" guarantee).
 */
final readonly class IdeaStatusAction
{
    public function __construct(
        private Connection $conn,
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private StatusService $statusService,
        private AuditLogger $audit,
        private NotificationRepository $notifications,
        private UserRepository $userRepo,
        private VoteRepository $voteRepo,
        private CommentRepository $commentRepo,
        private CommentNotificationMailer $notificationMailer,
        private ?BlockRepository $blockRepo = null,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        // --- Board lookup (account- + board-scoped, unknown slug → 404) ---
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

        // --- Load idea board-scoped (foreign idea → 404, no cross-board leak) ---
        $idea = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Idea not found.'],
            ]));

            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // --- Read and validate target status from the body ---
        $parsed = $request->getParsedBody();
        $to     = is_array($parsed) ? (string) ($parsed['status'] ?? '') : '';

        if (!$this->statusService->isValidStatus($to)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'invalid_status', 'message' => 'Invalid status.'],
            ]));

            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $from = (string) ($idea['status'] ?? 'open');

        // --- Self→self: idempotent no-op (no DB write, no audit, no notification) ---
        if ($from === $to) {
            $response->getBody()->write((string) json_encode(['ok' => true, 'status' => $to]));

            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        // --- Check the transition (invalid transition → 422, no DB write, no notification) ---
        if (!$this->statusService->canTransition($from, $to)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'invalid_transition', 'message' => 'Invalid transition.'],
            ]));

            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        /** @var array<string, mixed>|null $user */
        $user      = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $triggeredBy = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

        // --- Persist status + notification fan-out share ONE transaction
        // (both-or-nothing, same "reuse first" no-central-event-bus decision
        // as CommentCreateAction). Actual email sending is deliberately NOT
        // part of this transaction (network I/O) — eligible recipients are
        // collected here and mailed afterwards, once durably committed.
        /** @var list<array{email: string, title: string, body: string, link_path: string}> $emailCandidates */
        $emailCandidates = [];
        $this->conn->transactional(function () use (
            $boardId,
            $ideaId,
            $idea,
            $to,
            $accountId,
            $triggeredBy,
            $slug,
            &$emailCandidates,
        ): void {
            $this->ideaRepo->updateStatus($boardId, $ideaId, $to);

            $ideaTitle = (string) $idea['title'];
            $linkPath  = "/{$slug}/idea/{$ideaId}";

            // Recipients: idea author + every DISTINCT voter + every
            // DISTINCT commenter, deduplicated via array_unique (a user in
            // multiple roles gets exactly one notification row).
            $recipientIds   = [(int) $idea['author_id']];
            $recipientIds   = array_merge($recipientIds, $this->voteRepo->distinctVoterIds($ideaId));
            $recipientIds   = array_merge($recipientIds, $this->commentRepo->distinctPriorAuthorIds($ideaId, []));
            $recipientIds   = array_unique($recipientIds);

            foreach ($recipientIds as $recipientId) {
                // Never notify the admin who triggered the change themselves.
                if ($recipientId === $triggeredBy) {
                    continue;
                }

                if ($this->blockRepo instanceof BlockRepository && $this->blockRepo->isBlocked($accountId, $recipientId, $boardId)) {
                    continue;
                }

                $this->notifyRecipient(
                    $accountId,
                    $recipientId,
                    "\"{$ideaTitle}\" is now {$to}.",
                    $linkPath,
                    $emailCandidates,
                );
            }
        });

        foreach ($emailCandidates as $candidate) {
            $this->notificationMailer->send($candidate['email'], $candidate['title'], $candidate['body'], $candidate['link_path'], $boardId);
        }

        // --- Masked audit: board, idea, from→to, actor ID — no PII ---
        $this->audit->log('idea.status.changed', [
            'board_id'    => $boardId,
            'idea_id'     => $ideaId,
            'status_from' => $from,
            'status_to'   => $to,
            'actor_id'    => $triggeredBy,
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'status' => $to]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Creates the in-app notification row (only if the recipient's
     * notify_idea_status_inapp flag is on) and, independently, queues an
     * email candidate (only if notify_idea_status_email is on AND they have
     * a confirmed notification_email) — same independent-channel pattern as
     * CommentCreateAction::notifyRecipient().
     *
     * @param list<array{email: string, title: string, body: string, link_path: string}> $emailCandidates
     */
    private function notifyRecipient(
        int $accountId,
        int $recipientId,
        string $body,
        string $linkPath,
        array &$emailCandidates,
    ): void {
        $settings = $this->userRepo->findNotificationSettings($recipientId);
        if (!is_array($settings)) {
            return;
        }

        $title = 'Idea status updated';

        if ((bool) ($settings['notify_idea_status_inapp'] ?? false)) {
            $this->notifications->createForUser($accountId, $recipientId, 'idea_status_changed', $title, $body, $linkPath);
        }

        $notificationEmail = is_string($settings['notification_email'] ?? null) ? $settings['notification_email'] : null;
        if ($notificationEmail !== null && (bool) ($settings['notify_idea_status_email'] ?? false)) {
            $emailCandidates[] = ['email' => $notificationEmail, 'title' => $title, 'body' => $body, 'link_path' => $linkPath];
        }
    }
}
