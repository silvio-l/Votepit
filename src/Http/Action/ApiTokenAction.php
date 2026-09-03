<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\ApiTokenRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\ApiTokenAuthenticator;

/**
 * GET  /admin/boards/{slug}/tokens              — list a board's tokens.
 * POST /admin/boards/{slug}/tokens              — create a new token.
 * POST /admin/boards/{slug}/tokens/{id}/revoke  — revoke a token.
 *
 * Agent API / Votepit MCP. AuthZ: accountAdmin (both owner AND
 * moderator may manage tokens) — mirrors the AuthZ level used for other
 * board-scoped admin actions (branding, moderation, board-SMTP), NOT
 * accountOwner: token management is not one of the actions
 * designated owner-only (invite/member mutation).
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

    public function __construct(
        private BoardRepository $boardRepo,
        private ApiTokenRepository $tokens,
        private ApiTokenAuthenticator $authenticator,
        private AccountRepository $accountRepo,
        private PlanPolicy $planPolicy,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $args */
    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $board = $this->resolveBoard($request, $args);
        if (!is_array($board)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Board not found.']]);
        }

        $rows = $this->tokens->listForBoard((int) $board['id']);

        return $this->json($response, 200, [
            'tokens' => array_map(static fn (array $row): array => [
                'id'                 => (int) $row['id'],
                'label'              => (string) $row['label'],
                'created_by_user_id' => (int) $row['created_by_user_id'],
                'last_used_at'       => $row['last_used_at'],
                'revoked_at'         => $row['revoked_at'],
                'created_at'         => $row['created_at'],
            ], $rows),
        ]);
    }

    /** @param array<string, mixed> $args */
    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $board = $this->resolveBoard($request, $args);
        if (!is_array($board)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Board not found.']]);
        }

        $accountIdForPlan = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $account          = $this->accountRepo->findById($accountIdForPlan);
        $plan             = is_array($account) ? (string) ($account['plan'] ?? '') : '';

        if (!$this->planPolicy->agentApiAllowed($plan)) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'plan_limit_agent_api',
                    'message' => 'The Agent API is not available on your current plan.',
                ],
            ]);
        }

        $parsed = $request->getParsedBody();
        $label  = is_array($parsed) ? trim((string) ($parsed['label'] ?? '')) : '';

        if ($label === '' || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => [
                        'label' => $label === ''
                            ? 'A label is required.'
                            : 'The label must be at most ' . self::LABEL_MAX_LENGTH . ' characters long.',
                    ],
                ],
            ]);
        }

        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $actorId   = $this->actorId($request);

        $pair    = $this->authenticator->generate();
        $tokenId = $this->tokens->create($accountId, (int) $board['id'], $actorId, $label, $pair['hash']);

        $this->audit->log('api_token.created', [
            'account_id' => $accountId,
            'board_id'   => (int) $board['id'],
            'token_id'   => $tokenId,
            'actor_id'   => $actorId,
        ]);

        // The plaintext token appears HERE, the first and only time.
        return $this->json($response, 201, [
            'ok'    => true,
            'id'    => $tokenId,
            'label' => $label,
            'token' => $pair['token'],
        ]);
    }

    /** @param array<string, mixed> $args */
    public function revoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $board = $this->resolveBoard($request, $args);
        if (!is_array($board)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Board not found.']]);
        }

        $tokenId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $existing = $tokenId > 0 ? $this->tokens->findForBoard((int) $board['id'], $tokenId) : null;
        if ($existing === null) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Token not found.']]);
        }

        $this->tokens->revoke((int) $board['id'], $tokenId); // idempotent: already revoked → no-op, still 200

        $this->audit->log('api_token.revoked', [
            'board_id' => (int) $board['id'],
            'token_id' => $tokenId,
            'actor_id' => $this->actorId($request),
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    private function resolveBoard(ServerRequestInterface $request, array $args): ?array
    {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        return $this->boardRepo->findBySlugForAccount($slug, $accountId);
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
