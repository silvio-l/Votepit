<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\EffectivePlan;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\ApiTokenRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\ApiTokenAuthenticator;

/**
 * GET  /admin/tokens              — list the account's tokens (all boards).
 * POST /admin/tokens              — create a new token, granting access to
 *                                    one or more of the account's boards,
 *                                    each with its own 'read'|'write' scope.
 * POST /admin/tokens/{id}/revoke  — revoke a token.
 *
 * Agent API / Votepit MCP. Account-scoped since migration 0044 (a token used
 * to authorize exactly one board — now it grants a settable SET of the
 * account's boards); per-board scope since migration 0047 (one token can
 * write on board A and only read board B — there is no single token-wide
 * scope anymore). Wire format: `boards: [{slug, scope}, ...]` on create,
 * `boards: [{board_id, scope}, ...]` on list — this is the SPA's own
 * management endpoint (session-authenticated, not part of the documented
 * bearer-token Agent API contract), so its shape is free to evolve.
 *
 * AuthZ: accountAdmin (owner AND admin may manage tokens) — mirrors the
 * AuthZ level used for other account-level admin actions (branding,
 * moderation, board-SMTP), NOT accountOwner: token management is not one of
 * the actions designated owner-only (invite/member mutation).
 *
 * The plaintext token is returned ONLY in the create() response — after
 * that it's unrecoverable (standard practice, mirrors invite/login-token
 * handling: only the hash survives in the DB). list()/revoke() never see it.
 *
 * Plan gate: create() consults PlanPolicy::agentApiAllowed($plan). With a
 * tiered policy an unknown/missing plan value yields false and blocks token
 * creation instead of silently allowing it (the Community default policy
 * allows every plan). list()/revoke()
 * remain ungated (a token already issued stays manageable/
 * revocable even if an account has since been downgraded).
 */
final readonly class ApiTokenAction
{
    /** Label length limit — consistent with the DB column VARCHAR(100). */
    private const LABEL_MAX_LENGTH = 100;

    /** @var list<string> */
    private const ALLOWED_SCOPES = ['read', 'write'];

    public function __construct(
        private BoardRepository $boardRepo,
        private ApiTokenRepository $tokens,
        private ApiTokenAuthenticator $authenticator,
        private AccountRepository $accountRepo,
        private PlanPolicy $planPolicy,
        private AuditLogger $audit,
    ) {}

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $rows      = $this->tokens->listForAccount($accountId);

        return $this->json($response, 200, [
            'tokens' => array_map(static fn (array $row): array => [
                'id'                 => (int) $row['id'],
                'label'              => (string) $row['label'],
                'boards'             => self::boardGrantList($row['board_scopes']),
                'created_by_user_id' => (int) $row['created_by_user_id'],
                'last_used_at'       => $row['last_used_at'],
                'revoked_at'         => $row['revoked_at'],
                'created_at'         => $row['created_at'],
            ], $rows),
        ]);
    }

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId  = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $account    = $this->accountRepo->findById($accountId);
        $rawPlan    = is_array($account) ? (string) ($account['plan'] ?? '') : '';
        $actingUser = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $plan       = EffectivePlan::resolve($rawPlan, is_array($actingUser) ? $actingUser : null, $this->planPolicy);

        if (!$this->planPolicy->agentApiAllowed($plan)) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'plan_limit_agent_api',
                    'message' => 'The Agent API is not available on your current plan.',
                ],
            ]);
        }

        $parsed    = $request->getParsedBody();
        $label     = is_array($parsed) ? trim((string) ($parsed['label'] ?? '')) : '';
        $rawBoards = is_array($parsed) && is_array($parsed['boards'] ?? null) ? $parsed['boards'] : [];

        /** @var array<string, string> $fields */
        $fields = [];
        if ($label === '' || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
            $fields['label'] = $label === ''
                ? 'A label is required.'
                : 'The label must be at most ' . self::LABEL_MAX_LENGTH . ' characters long.';
        }
        if ($rawBoards === []) {
            $fields['boards'] = 'At least one board must be granted.';
        }

        if ($fields !== []) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => $fields,
                ],
            ]);
        }

        /** @var array<int, string> $boardScopes board_id => scope */
        $boardScopes = [];
        foreach ($rawBoards as $rawBoard) {
            $slug  = is_array($rawBoard) && is_string($rawBoard['slug'] ?? null) ? $rawBoard['slug'] : '';
            $scope = is_array($rawBoard) && is_string($rawBoard['scope'] ?? null) ? $rawBoard['scope'] : '';
            $board = $slug !== '' ? $this->boardRepo->findBySlugForAccount($slug, $accountId) : null;

            if ($board === null) {
                return $this->json($response, 422, [
                    'error' => [
                        'key'     => 'validation_error',
                        'message' => 'Validation failed.',
                        'fields'  => ['boards' => 'Unknown board: ' . $slug],
                    ],
                ]);
            }
            if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
                return $this->json($response, 422, [
                    'error' => [
                        'key'     => 'validation_error',
                        'message' => 'Validation failed.',
                        'fields'  => ['boards' => 'Scope for ' . $slug . ' must be one of: ' . implode(', ', self::ALLOWED_SCOPES) . '.'],
                    ],
                ]);
            }
            $boardScopes[(int) $board['id']] = $scope;
        }

        if ($boardScopes === []) {
            // Unreachable: $rawBoards is non-empty (validated above) and
            // every iteration either appends or returns early — narrows the
            // type for PHPStan (create() requires a non-empty-array<int, string>).
            throw new \LogicException('boardScopes must not be empty here.');
        }

        $actorId = $this->actorId($request);
        $pair    = $this->authenticator->generate();
        $tokenId = $this->tokens->create($accountId, $boardScopes, $actorId, $label, $pair['hash']);

        $this->audit->log('api_token.created', [
            'account_id'   => $accountId,
            'board_scopes' => $boardScopes,
            'token_id'     => $tokenId,
            'actor_id'     => $actorId,
        ]);

        // The plaintext token appears HERE, the first and only time.
        return $this->json($response, 201, [
            'ok'     => true,
            'id'     => $tokenId,
            'label'  => $label,
            'boards' => self::boardGrantList($boardScopes),
            'token'  => $pair['token'],
        ]);
    }

    /**
     * @param array<int, string> $boardScopes board_id => scope
     * @return list<array{board_id: int, scope: string}>
     */
    private static function boardGrantList(array $boardScopes): array
    {
        $grants = [];
        foreach ($boardScopes as $boardId => $scope) {
            $grants[] = ['board_id' => $boardId, 'scope' => $scope];
        }
        return $grants;
    }

    /** @param array<string, mixed> $args */
    public function revoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $tokenId   = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;

        $existing = $tokenId > 0 ? $this->tokens->findForAccount($accountId, $tokenId) : null;
        if ($existing === null) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Token not found.']]);
        }

        $this->tokens->revoke($accountId, $tokenId); // idempotent: already revoked → no-op, still 200

        $this->audit->log('api_token.revoked', [
            'account_id' => $accountId,
            'token_id'   => $tokenId,
            'actor_id'   => $this->actorId($request),
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    private function actorId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $actor */
        $actor = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($actor) ? (int) ($actor['id'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
