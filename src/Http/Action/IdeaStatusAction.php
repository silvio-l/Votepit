<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\StatusService;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
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
 * Input `status` ∈ StatusService::VALID_STATUSES; invalid values or a
 * disallowed transition → 422, idea unchanged.
 *
 * Self→self is an idempotent no-op: 200, no DB write, no audit entry.
 *
 * Always responds JSON { ok: true, status: string } (status 200).
 */
final readonly class IdeaStatusAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private StatusService $statusService,
        private AuditLogger $audit,
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

        // --- Self→self: idempotent no-op (no DB write, no audit) ---
        if ($from === $to) {
            $response->getBody()->write((string) json_encode(['ok' => true, 'status' => $to]));

            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        // --- Check the transition ---
        if (!$this->statusService->canTransition($from, $to)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'invalid_transition', 'message' => 'Invalid transition.'],
            ]));

            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // --- Persist status (board-scoped prepared statement, ADR-5 invariant) ---
        $this->ideaRepo->updateStatus($boardId, $ideaId, $to);

        // --- Masked audit: board, idea, from→to, actor ID — no PII ---
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $this->audit->log('idea.status.changed', [
            'board_id'    => $boardId,
            'idea_id'     => $ideaId,
            'status_from' => $from,
            'status_to'   => $to,
            'actor_id'    => is_array($user) ? (int) ($user['id'] ?? 0) : 0,
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'status' => $to]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
