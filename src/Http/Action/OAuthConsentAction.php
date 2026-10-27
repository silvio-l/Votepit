<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\OAuthAuthorizationCodeRepository;
use Votepit\Security\ApiTokenScope;
use Votepit\Security\OAuthServer\AuthorizationError;
use Votepit\Security\OAuthServer\AuthorizationRequestValidator;
use Votepit\Security\OAuthServer\OAuthGrantEligibility;
use Votepit\Security\OAuthServer\OAuthScope;
use Votepit\Security\OAuthServer\OAuthTokens;
use Votepit\Security\TokenVault;
use Votepit\Support\Clock;

/**
 * The MCP OAuth consent screen's two backend calls (session-authenticated,
 * AuthZ user; the SPA page /oauth/authorize renders them):
 *
 *  GET  /api/oauth/authorize — validates the authorization request and
 *       returns what the screen shows: the client (plaintext name + the
 *       exact redirect URI), the highest grant level the client asked for
 *       and the accounts the user may delegate (owner/admin) with their
 *       boards.
 *  POST /api/oauth/authorize — the decision (CSRF-protected, rate-limited).
 *       `deny` → redirect with error=access_denied; `approve` with one
 *       account slug + per-board levels → single-use authorization code,
 *       redirect with code + state + iss.
 *
 * Both re-run the full request validation (AuthorizationRequestValidator)
 * on the parameters they receive, so the decision cannot be submitted for a
 * different client/redirect URI than the one displayed. The response is
 * always JSON with `redirect_to`; the SPA performs the navigation.
 *
 * Account scoping (the critical path): the account comes from the body by
 * slug and must pass OAuthGrantEligibility for THIS user (owner/admin,
 * unlocked, Agent API plan) — an account the user cannot delegate answers
 * the same 403 whether it exists or not. Every board slug resolves only
 * within that account (BoardRepository::findBySlugForAccount()), and every
 * level must be a known grant level not above the requested scope.
 */
final readonly class OAuthConsentAction
{
    /** Upper bound on grants in one consent — far above any real account's board count. */
    private const MAX_BOARDS = 100;

    public function __construct(
        private AuthorizationRequestValidator $validator,
        private OAuthGrantEligibility $eligibility,
        private AccountRepository $accounts,
        private BoardRepository $boards,
        private OAuthAuthorizationCodeRepository $codes,
        private TokenVault $vault,
        private Clock $clock,
        private AuditLogger $audit,
    ) {}

    public function context(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $authRequest = $this->validator->validate($request->getQueryParams());
        if ($authRequest instanceof AuthorizationError) {
            return $this->authorizationError($response, $authRequest);
        }

        $user   = $this->user($request);
        $userId = (int) ($user['id'] ?? 0);

        $accounts = [];
        foreach ($this->eligibility->candidateAccounts($userId) as $account) {
            $reason = $this->eligibility->denialReason($account['id'], $userId, $user);
            $accounts[] = [
                'slug'   => $account['slug'],
                'name'   => $account['name'],
                'reason' => $reason,
                'boards' => $reason === null
                    ? array_map(
                        static fn (array $b): array => ['slug' => $b['slug'], 'name' => $b['name']],
                        $this->boards->listForAccount($account['id']),
                    )
                    : [],
            ];
        }

        return $this->json($response, 200, [
            'client' => [
                'client_id'    => $authRequest->client['client_id'],
                'name'         => $authRequest->client['client_name'],
                'redirect_uri' => $authRequest->redirectUri,
            ],
            'max_scope' => $authRequest->maxScope,
            'accounts'  => $accounts,
        ]);
    }

    public function decide(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body        = $request->getParsedBody();
        $params      = is_array($body) ? $body : [];
        $authRequest = $this->validator->validate($params);
        if ($authRequest instanceof AuthorizationError) {
            return $this->authorizationError($response, $authRequest);
        }

        $user     = $this->user($request);
        $userId   = (int) ($user['id'] ?? 0);
        $clientId = $authRequest->client['id'];
        $decision = $params['decision'] ?? null;

        if ($decision === 'deny') {
            $this->audit->log('oauth.consent_denied', ['actor_id' => $userId, 'oauth_client_id' => $clientId]);

            return $this->json($response, 200, [
                'redirect_to' => $authRequest->errorRedirect('access_denied', 'The user denied the request.'),
            ]);
        }

        if ($decision !== 'approve') {
            return $this->validationError($response, 'decision', 'decision must be "approve" or "deny".');
        }

        $accountSlug = is_string($params['account'] ?? null) ? $params['account'] : '';
        $account     = $accountSlug !== '' ? $this->accounts->findBySlug($accountSlug) : null;
        $accountId   = $account !== null ? (int) $account['id'] : 0;
        $reason      = $accountId > 0 ? $this->eligibility->denialReason($accountId, $userId, $user) : OAuthGrantEligibility::DENY_ROLE;

        if ($reason === OAuthGrantEligibility::DENY_PLAN) {
            return JsonError::respond($response, 422, 'plan_limit_agent_api', 'The Agent API is not available on this account\'s plan.');
        }
        if ($reason !== null) {
            return JsonError::respond($response, 403, 'account_not_allowed', 'You cannot grant access to this account.');
        }

        $boardScopes = $this->boardScopes($params['boards'] ?? null, $accountId, $authRequest->maxScope);
        if (is_string($boardScopes)) {
            return $this->validationError($response, 'boards', $boardScopes);
        }

        $now  = $this->clock->now();
        $code = OAuthTokens::generate(OAuthTokens::CODE_PREFIX, $this->vault);

        // Housekeeping: codes are only kept a day past expiry (replay detection window).
        $this->codes->purgeExpired($now->modify('-1 day'));
        $this->codes->create(
            $code['hash'],
            $clientId,
            $accountId,
            $userId,
            $boardScopes,
            $authRequest->redirectUri,
            $authRequest->codeChallenge,
            $now->modify('+' . OAuthTokens::CODE_TTL_SECONDS . ' seconds'),
            $now,
        );

        $this->audit->log('oauth.consent_granted', [
            'account_id'      => $accountId,
            'actor_id'        => $userId,
            'oauth_client_id' => $clientId,
            'board_scopes'    => $boardScopes,
        ]);

        return $this->json($response, 200, ['redirect_to' => $authRequest->successRedirect($code['token'])]);
    }

    /**
     * @return non-empty-array<int, string>|string board_id => level, or a validation message
     */
    private function boardScopes(mixed $rawBoards, int $accountId, string $maxScope): array|string
    {
        if (!is_array($rawBoards) || $rawBoards === []) {
            return 'At least one board must be granted.';
        }
        if (count($rawBoards) > self::MAX_BOARDS) {
            return 'Too many boards.';
        }

        $boardScopes = [];
        foreach ($rawBoards as $rawBoard) {
            $slug  = is_array($rawBoard) && is_string($rawBoard['slug'] ?? null) ? $rawBoard['slug'] : '';
            $scope = is_array($rawBoard) && is_string($rawBoard['scope'] ?? null) ? $rawBoard['scope'] : '';
            $board = $slug !== '' ? $this->boards->findBySlugForAccount($slug, $accountId) : null;

            if ($board === null) {
                return 'Unknown board: ' . $slug;
            }
            if (!in_array($scope, ApiTokenScope::ALL, true) || !OAuthScope::within($scope, $maxScope)) {
                return 'The access level for ' . $slug . ' is not allowed for this request.';
            }
            $boardScopes[(int) $board['id']] = $scope;
        }

        return $boardScopes;
    }

    private function authorizationError(ResponseInterface $response, AuthorizationError $error): ResponseInterface
    {
        return JsonError::respond(
            $response,
            400,
            $error->error,
            $error->description,
            $error->redirectTo !== null ? ['redirect_to' => $error->redirectTo] : [],
        );
    }

    private function validationError(ResponseInterface $response, string $field, string $message): ResponseInterface
    {
        return JsonError::respond($response, 422, 'validation_error', 'Validation failed.', ['fields' => [$field => $message]]);
    }

    /** @return array<string, mixed> */
    private function user(ServerRequestInterface $request): array
    {
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        return is_array($user) ? $user : [];
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
