<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\IdeaStatusChanger;
use Votepit\Domain\InvalidStatusDecision;
use Votepit\Domain\StatusDecision;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\IdeaRepository;

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
 * Input `status` = a status KEY of this board (BoardStatuses, migration
 * 0073 — on a default board exactly the six canonical slugs); an unknown
 * key, a hidden target or a disallowed (category-graph) transition → 422,
 * idea unchanged.
 *
 * Optional `reason` (plaintext, max 1000), `next_step` (plaintext, max 280)
 * and `responsible_role` ('team' | 'moderation') — the reasoned decision
 * (P-01, migration 0091). Absent/blank = no reasoning; the change is recorded
 * in the idea's decision history either way. A malformed or over-long value
 * → 422 `invalid_decision` (naming the field, never echoing the value) and the
 * idea stays unchanged. The soft "please give a reason" prompt for declined
 * statuses is a UI concern only.
 *
 * Board switch `status_reason_required` (migration 0115, default off): when on,
 * a real status change without a non-blank `reason` → 422
 * `status_reason_required` and the idea stays unchanged. The check runs after
 * the transition check and only for a real change (self→self needs no reason).
 * Agent API (ApiIdeaWriteAction) and MCP (set_idea_status) delegate here, so the
 * rule holds on every human/agent path. IssueStatusSync never reaches this
 * action and is exempt by design (automated, no decision text).
 *
 * Self→self is an idempotent no-op: 200, no DB write, no audit entry.
 *
 * Always responds JSON { ok: true, status: <canonical category slug>,
 * status_key: <key>, allowed_next: <keys> } (status 200). Webhook/audit keep `status_from/to` =
 * canonical slugs and add `status_key_from/to`. Notification text uses the
 * board's label for renamed/custom statuses (BoardStatuses::notificationLabel()).
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
 *
 * ADR 0004 extension: ALSO mails every anonymous voter on this idea who
 * left an optional notification email (VoteRepository::
 * distinctAnonymousVoterEmailsEnc(), decrypted here via EncryptionService
 * context 'anon_vote_email') — same title/body/link as registered voters
 * get. This is the entire reason that email is stored reversibly encrypted
 * rather than hashed (see migrations/
 * 0069_replace_voter_email_hash_with_encryption.sql): an anonymous voter
 * has no account, so there is no notification-settings row to gate on and
 * no identity to exclude-if-triggering-admin — every address left on this
 * idea is notified, through the SAME mailer instance and therefore the
 * SAME global send-rate-limit bucket as everything else in this fan-out
 * (no separate rate limiting is built for this path).
 */
final readonly class IdeaStatusAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private BoardStatusRepository $statusRepo,
        private IdeaStatusChanger $changer,
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
        $parsed   = $request->getParsedBody();
        $to       = is_array($parsed) ? (string) ($parsed['status'] ?? '') : '';
        $statuses = $this->statusRepo->registryFor($board);

        if (!$statuses->find($to) instanceof \Votepit\Domain\BoardStatus) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'invalid_status', 'message' => 'Invalid status.'],
            ]));

            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // --- Optional reasoned decision (P-01): validated before any write ---
        $bodyFields = is_array($parsed) ? $parsed : [];
        try {
            $decision = StatusDecision::fromInput(
                $bodyFields['reason'] ?? null,
                $bodyFields['next_step'] ?? null,
                $bodyFields['responsible_role'] ?? null,
            );
        } catch (InvalidStatusDecision $e) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'invalid_decision', 'message' => 'Invalid status decision.', 'field' => $e->field],
            ]));

            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $from         = (string) ($idea['status_key'] ?? 'open');
        $toCategory = (string) $statuses->categoryOf($to);

        // --- Self→self: idempotent no-op (no DB write, no audit, no notification) ---
        if ($from === $to) {
            $response->getBody()->write((string) json_encode(['ok' => true, 'status' => $toCategory, 'status_key' => $to, 'allowed_next' => $statuses->allowedNext($to)]));

            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        // --- Check the transition (invalid transition → 422, no DB write, no notification) ---
        if (!$statuses->canTransition($from, $to)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'invalid_transition', 'message' => 'Invalid transition.'],
            ]));

            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // --- Board switch (migration 0115): a real change needs a reason. Blank counts as
        // absent (StatusDecision trims it to null). The no-op above never reaches this, and
        // automated issue-tracker sync does not go through this action at all (IssueStatusSync). ---
        if ((bool) ($board['status_reason_required'] ?? false) && $decision->reason === null) {
            return JsonError::respond($response, 422, 'status_reason_required', 'This board requires a reason when the status of an idea changes.');
        }

        /** @var array<string, mixed>|null $user */
        $user        = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $triggeredBy = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

        // Persist + notification fan-out + audit + board webhook (see
        // Domain\IdeaStatusChanger, shared with the issue-tracker sync).
        $this->changer->apply($accountId, $boardId, $slug, $idea, $statuses, $to, $triggeredBy, [], $decision);

        $response->getBody()->write((string) json_encode(['ok' => true, 'status' => $toCategory, 'status_key' => $to, 'allowed_next' => $statuses->allowedNext($to)]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
