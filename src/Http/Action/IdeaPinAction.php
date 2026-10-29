<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;

/**
 * POST /{board}/ideas/{id}/pin — pin/unpin an idea (admin-only).
 *
 * AuthZ: accountAdmin (via AuthZMiddleware::accountAdmin() in AppFactory;
 * anon → 401, missing account role → 403).
 * CSRF: globally enforced (CsrfMiddleware in the POST path).
 * BlockCheck: global (blocked user → 403, before the action runs).
 * RateLimit: perAction('idea:pin') in AppFactory.
 *
 * Structurally board-scoped: the idea is loaded board-scoped via
 * findInBoard() — unknown slug or idea outside the board → 404 (no
 * cross-board leak, no mutation).
 *
 * Input `pinned` (boolean target state); missing or not boolean → 422,
 * idea unchanged.
 *
 * Target state equal to the current state is an idempotent no-op: 200, no
 * DB write, no audit entry.
 *
 * Always responds JSON { ok: true, pinned: bool } (status 200).
 */
final readonly class IdeaPinAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
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

        // --- Read and validate the target state from the body ---
        $target = $this->parsePinned($request);
        if ($target === null) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'invalid_pinned', 'message' => 'Invalid pinned value.'],
            ]));

            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $current = (bool) ($idea['is_pinned'] ?? false);

        // --- Target state == current state: idempotent no-op (no DB write, no audit) ---
        if ($current === $target) {
            $response->getBody()->write((string) json_encode(['ok' => true, 'pinned' => $target]));

            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        // --- Persist pin state (board-scoped prepared statement, ADR-5 invariant) ---
        $this->ideaRepo->setPinned($boardId, $ideaId, $target);

        // --- Masked audit: board, idea, new pin state, actor ID — no PII ---
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $this->audit->log('idea.pin.changed', [
            'board_id' => $boardId,
            'idea_id'  => $ideaId,
            'pinned'   => $target,
            'actor_id' => is_array($user) ? (int) ($user['id'] ?? 0) : 0,
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'pinned' => $target]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Strictly validates the `pinned` field: bool, or the strings/ints
     * '1'/1/'true' or '0'/0/'false'. Anything else → null (action responds with 422).
     */
    private function parsePinned(ServerRequestInterface $request): ?bool
    {
        $parsed = $request->getParsedBody();
        $raw    = is_array($parsed) ? ($parsed['pinned'] ?? null) : null;

        if (is_bool($raw)) {
            return $raw;
        }

        return match (true) {
            in_array($raw, ['1', 1, 'true'], true)   => true,
            in_array($raw, ['0', 0, 'false'], true)  => false,
            default                                          => null,
        };
    }
}
