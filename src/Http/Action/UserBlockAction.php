<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\UserRepository;

/**
 * POST /admin/boards/{slug}/block — block/unblock a user account-wide (admin-only).
 *
 * AuthZ: accountAdmin (via AuthZMiddleware::accountAdmin() in AppFactory;
 * anon → 401, missing account role → 403).
 * CSRF: globally enforced (CsrfMiddleware on the POST path).
 * BlockCheck: global (blocked user → 403, before the action runs).
 * RateLimit: perAction('user:block') in AppFactory.
 *
 * Board-scoping is structural: `{slug}` serves the account/AuthZ resolution
 * (findBySlugForAccount(), foreign board → 404, no cross-tenant leak).
 * Additionally, `scope` selects the block extent:
 * `scope=account` (default, board_id NULL) blocks account-wide, `scope=board`
 * restricts the block to the board resolved via `{slug}` (board_id
 * set). Enforcement of the board-scoped case runs as a thin inline guard
 * in the affected board actions (idea create/edit/withdraw, vote).
 *
 * Input `user_id` (int) + `blocked` (bool target state) + optional `scope`
 * (`account`|`board`, default `account`). Unknown `user_id` → 404.
 * Missing/invalid fields → 422, no mutation.
 *
 * Target state equal to current state is an idempotent no-op: 200, no
 * DB write, no audit entry.
 *
 * Always responds with JSON { ok: true, blocked: bool } (status 200).
 */
final readonly class UserBlockAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private BlockRepository $blockRepo,
        private UserRepository $userRepo,
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
        // --- Board lookup (account-scoped, unknown slug → 404) ---
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Board not found.']]);
        }

        // --- Read and validate input ---
        $parsed       = $request->getParsedBody();
        $targetUserId = is_array($parsed) ? (int) ($parsed['user_id'] ?? 0) : 0;
        $target       = $this->parseBlocked($request);
        $scope        = $this->parseScope($request);

        if ($targetUserId <= 0 || $target === null || $scope === null) {
            return $this->json($response, 422, ['error' => ['key' => 'invalid_input', 'message' => 'Invalid user_id, blocked or scope value.']]);
        }

        // --- Target user must exist, otherwise 404 (not a foreign-key error) ---
        if ($this->userRepo->findById($targetUserId) === null) {
            return $this->json($response, 404, ['error' => ['key' => 'user_not_found', 'message' => 'User not found.']]);
        }

        $targetBoardId = $scope === 'board' ? (int) $board['id'] : null;

        $current = $this->blockRepo->isBlockedInScope($accountId, $targetUserId, $targetBoardId);

        // --- Target state == current state: idempotent no-op (no DB write, no audit) ---
        if ($current === $target) {
            return $this->json($response, 200, ['ok' => true, 'blocked' => $target]);
        }

        /** @var array<string, mixed>|null $actor */
        $actor   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $actorId = is_array($actor) ? (int) ($actor['id'] ?? 0) : 0;

        if ($target) {
            $this->blockRepo->block($accountId, $targetUserId, $targetBoardId, $actorId);
        } else {
            $this->blockRepo->unblock($accountId, $targetUserId, $targetBoardId);
        }

        // --- Masked audit: account, board, target user, new state, actor — no PII ---
        $this->audit->log('user.block.changed', [
            'account_id'     => $accountId,
            'board_id'       => $targetBoardId,
            'target_user_id' => $targetUserId,
            'blocked'        => $target,
            'actor_id'       => $actorId,
        ]);

        return $this->json($response, 200, ['ok' => true, 'blocked' => $target]);
    }

    /**
     * Strictly validates the `blocked` field: bool, or the strings/ints '1'/1/'true'
     * resp. '0'/0/'false'. Anything else → null (action responds with 422).
     */
    private function parseBlocked(ServerRequestInterface $request): ?bool
    {
        $parsed = $request->getParsedBody();
        $raw    = is_array($parsed) ? ($parsed['blocked'] ?? null) : null;

        if (is_bool($raw)) {
            return $raw;
        }

        return match (true) {
            in_array($raw, ['1', 1, 'true'], true)   => true,
            in_array($raw, ['0', 0, 'false'], true)  => false,
            default                                          => null,
        };
    }

    /**
     * Validates the optional `scope` field: `account` or `board`. If missing,
     * `account` is the default (backward-compatible with clients that don't
     * yet send a scope field). Any other value → null (action responds
     * with 422).
     */
    private function parseScope(ServerRequestInterface $request): ?string
    {
        $parsed = $request->getParsedBody();
        $raw    = is_array($parsed) ? ($parsed['scope'] ?? 'account') : 'account';

        return match ($raw) {
            'account', 'board' => $raw,
            default             => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
