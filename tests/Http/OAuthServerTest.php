<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\OAuth\PkceGenerator;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\FixedClock;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * MCP OAuth 2.1 authorization server (issue 21), end to end through the
 * real route table: discovery → registration → consent → code exchange →
 * MCP call → refresh rotation → revocation.
 *
 * Security acceptance covered here:
 *  - PKCE S256 only, exact redirect_uri match, single-use codes (replay
 *    revokes the grant), wrong verifier burns the code;
 *  - refresh-token rotation; reuse of a rotated token revokes the family;
 *  - OAuth access tokens resolve through the same grant path as static
 *    tokens (board scoping, revocation on the token page), static tokens
 *    keep working and an OAuth grant row is never usable as a static token;
 *  - cross-tenant: the consent cannot target an account the user does not
 *    administer, nor a board of another account; the token only reaches the
 *    consented boards;
 *  - CSRF on the consent POST; consent requires a session; deny → access_denied;
 *  - plan gate (agent_api), role re-check on refresh;
 *  - WWW-Authenticate with resource_metadata on MCP 401s.
 */
final class OAuthServerTest extends IntegrationTestCase
{
    private const REDIRECT = 'http://127.0.0.1:33418/callback';
    private const VERIFIER = 'verifier-verifier-verifier-verifier-verifier-0001';

    /** @var App<null>|null */
    private ?App $app = null;

    /** @return App<null> */
    private function app(): App
    {
        return $this->app ??= $this->createApp();
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function registerRequest(array $body): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/oauth/register')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json');
        $request->getBody()->write((string) json_encode($body));
        $request->getBody()->rewind();

        return $request;
    }

    private function registerClient(string $name = 'Claude', string $redirect = self::REDIRECT): string
    {
        $response = $this->app()->handle($this->registerRequest(['client_name' => $name, 'redirect_uris' => [$redirect]]));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (string) $this->json($response)['client_id'];
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function authParams(string $clientId, array $overrides = []): array
    {
        return array_merge([
            'response_type'         => 'code',
            'client_id'             => $clientId,
            'redirect_uri'          => self::REDIRECT,
            'code_challenge'        => PkceGenerator::challengeFor(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'state'                 => 'st-123',
            'scope'                 => 'read write',
        ], $overrides);
    }

    /** @param array<string, string> $params */
    private function contextRequest(?int $userId, array $params): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/oauth/authorize')
            ->withQueryParams($params);

        return $userId !== null ? $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]) : $request;
    }

    /** @param array<string, mixed> $body */
    private function consentRequest(?int $userId, array $body, bool $withCsrf = true): ServerRequestInterface
    {
        $csrf    = $this->csrf();
        $token   = $csrf->generate();
        $cookies = [$csrf->cookieName() => $csrf->sign($token)];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/oauth/authorize')
            ->withCookieParams($cookies)
            ->withParsedBody($withCsrf ? ['_csrf' => $token] + $body : $body);
    }

    /** @param array<string, string> $params */
    private function formRequest(string $path, array $params): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody($params);
    }

    /** @param array<string, mixed> $params */
    private function mcpRequest(string $bearer, string $method = 'tools/call', array $params = ['name' => 'get_board'], string $query = ''): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/mcp' . $query)
            ->withHeader('Authorization', 'Bearer ' . $bearer)
            ->withHeader('Content-Type', 'application/json');
        parse_str(ltrim($query, '?'), $queryParams);
        $request = $request->withQueryParams($queryParams);
        $request->getBody()->write((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]));
        $request->getBody()->rewind();

        return $request;
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data, (string) $response->getBody());

        return $data;
    }

    /** @return array<string, string> query of a redirect_to URL */
    private function redirectQuery(string $url): array
    {
        self::assertStringStartsWith(self::REDIRECT . '?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $strings = [];
        foreach ($query as $key => $value) {
            if (is_string($value)) {
                $strings[(string) $key] = $value;
            }
        }

        return $strings;
    }

    /** @return array{0: int, 1: string} owner user id + default account slug, with one board */
    private function owner(string $email = 'owner@example.com', string $role = 'owner'): array
    {
        $userId = $this->insertUser($email);
        $this->insertAccountMember($this->defaultAccountId(), $userId, $role);

        return [$userId, $this->defaultAccountSlug()];
    }

    /**
     * Runs registration + consent and returns the authorization code.
     *
     * @param list<array{slug: string, scope: string}> $boards
     * @param array<string, string> $paramOverrides
     */
    private function authorize(int $userId, string $clientId, string $accountSlug, array $boards, array $paramOverrides = []): string
    {
        $response = $this->app()->handle($this->consentRequest($userId, $this->authParams($clientId, $paramOverrides) + [
            'decision' => 'approve',
            'account'  => $accountSlug,
            'boards'   => $boards,
        ]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $query = $this->redirectQuery((string) $this->json($response)['redirect_to']);
        self::assertSame('st-123', $query['state']);
        self::assertSame('http://localhost:8000', $query['iss']);

        return $query['code'];
    }

    /** @return array<string, mixed> token response */
    private function exchange(string $clientId, string $code, string $verifier = self::VERIFIER, string $redirect = self::REDIRECT): array
    {
        $response = $this->app()->handle($this->formRequest('/oauth/token', [
            'grant_type'    => 'authorization_code',
            'client_id'     => $clientId,
            'code'          => $code,
            'code_verifier' => $verifier,
            'redirect_uri'  => $redirect,
        ]));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        return ['status' => $response->getStatusCode()] + $this->json($response);
    }

    /** @return array<string, mixed> */
    private function refresh(string $clientId, string $refreshToken): array
    {
        $response = $this->app()->handle($this->formRequest('/oauth/token', [
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'refresh_token' => $refreshToken,
        ]));

        return ['status' => $response->getStatusCode()] + $this->json($response);
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: int} client id, tokens, board id */
    private function fullFlow(string $scope = 'write'): array
    {
        [$userId, $slug] = $this->owner();
        $boardId         = $this->insertBoard('oauth-board', ['name' => 'OAuth Board']);
        $clientId        = $this->registerClient();
        $code            = $this->authorize($userId, $clientId, $slug, [['slug' => 'oauth-board', 'scope' => $scope]]);
        $tokens          = $this->exchange($clientId, $code);
        self::assertSame(200, $tokens['status'], (string) json_encode($tokens));

        return [$clientId, $tokens, $boardId];
    }

    // ── Discovery ────────────────────────────────────────────────────────

    public function test_metadata_documents_are_public(): void
    {
        $as = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/.well-known/oauth-authorization-server'));
        self::assertSame(200, $as->getStatusCode());
        $doc = $this->json($as);
        self::assertSame('http://localhost:8000', $doc['issuer']);
        self::assertSame('http://localhost:8000/oauth/token', $doc['token_endpoint']);
        self::assertSame(['S256'], $doc['code_challenge_methods_supported']);

        foreach (['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/api/v1/mcp'] as $path) {
            $pr = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
            self::assertSame(200, $pr->getStatusCode());
            self::assertSame('http://localhost:8000/api/v1/mcp', $this->json($pr)['resource']);
            self::assertSame([], $pr->getHeader('Set-Cookie'));
        }
    }

    public function test_mcp_401_points_to_resource_metadata(): void
    {
        $noToken = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/mcp')->withHeader('Content-Type', 'application/json'),
        );
        self::assertSame(401, $noToken->getStatusCode());
        self::assertSame(
            'Bearer resource_metadata="http://localhost:8000/.well-known/oauth-protected-resource/api/v1/mcp"',
            $noToken->getHeaderLine('WWW-Authenticate'),
        );

        $badToken = $this->app()->handle($this->mcpRequest('vpat_' . str_repeat('0', 64)));
        self::assertSame(401, $badToken->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', $badToken->getHeaderLine('WWW-Authenticate'));

        $rest = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/board'));
        self::assertSame(401, $rest->getStatusCode());
        self::assertSame('Bearer', $rest->getHeaderLine('WWW-Authenticate'));
    }

    // ── Registration ─────────────────────────────────────────────────────

    public function test_registration_issues_a_public_client_without_csrf_or_cookie(): void
    {
        $response = $this->app()->handle($this->registerRequest(['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]]));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'));
        $data = $this->json($response);
        self::assertStringStartsWith('vpc_', $data['client_id']);
        self::assertSame('none', $data['token_endpoint_auth_method']);
        self::assertArrayNotHasKey('client_secret', $data);
        self::assertStringContainsString('oauth.client_registered', $this->readAuditLog());
    }

    public function test_registration_rejects_unsafe_redirects_and_oversized_bodies(): void
    {
        $bad = $this->app()->handle($this->registerRequest(['redirect_uris' => ['http://evil.example/cb']]));
        self::assertSame(400, $bad->getStatusCode());
        self::assertSame('invalid_redirect_uri', $this->json($bad)['error']);

        $huge = $this->app()->handle($this->registerRequest(['redirect_uris' => [self::REDIRECT], 'pad' => str_repeat('x', 9000)]));
        self::assertSame(400, $huge->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM oauth_clients'));
    }

    public function test_registration_is_rate_limited_per_ip(): void
    {
        $app = $this->createApp(config: \Votepit\Config::fromArray($this->testConfigArray([
            'rate_limits' => ['oauth:register' => ['limit' => 2, 'window' => 3600]],
        ])));
        $request = fn (): ServerRequestInterface => (new ServerRequestFactory())
            ->createServerRequest('POST', '/oauth/register', ['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['redirect_uris' => [self::REDIRECT]]);

        self::assertSame(201, $app->handle($request())->getStatusCode());
        self::assertSame(201, $app->handle($request())->getStatusCode());
        self::assertSame(429, $app->handle($request())->getStatusCode());
    }

    // ── Consent ──────────────────────────────────────────────────────────

    public function test_consent_context_requires_a_session(): void
    {
        $clientId = $this->registerClient();

        self::assertSame(401, $this->app()->handle($this->contextRequest(null, $this->authParams($clientId)))->getStatusCode());
    }

    public function test_consent_context_lists_only_delegable_accounts(): void
    {
        [$userId, $slug] = $this->owner();
        $this->insertBoard('ctx-board', ['name' => 'Ctx']);

        // A foreign account where the user is only a moderator — not offered.
        $foreign = $this->insertAccount(['slug' => 'foreign-acct']);
        $this->insertAccountMember($foreign, $userId, 'moderator');
        $this->insertBoard('foreign-board', ['account_id' => $foreign, 'is_default' => 0]);

        // A business-less account the user owns — offered, but disabled by plan.
        $starter = $this->insertAccount(['slug' => 'starter-acct', 'plan' => 'free']);
        $this->insertAccountMember($starter, $userId, 'owner');

        $clientId = $this->registerClient('<b>Claude</b>');
        $response = $this->app()->handle($this->contextRequest($userId, $this->authParams($clientId)));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response);
        self::assertSame('<b>Claude</b>', $data['client']['name']);
        self::assertSame(self::REDIRECT, $data['client']['redirect_uri']);
        self::assertSame('write', $data['max_scope']);

        $bySlug = array_column($data['accounts'], null, 'slug');
        self::assertArrayNotHasKey('foreign-acct', $bySlug);
        self::assertNull($bySlug[$slug]['reason']);
        self::assertContains('ctx-board', array_column($bySlug[$slug]['boards'], 'slug'));
        self::assertSame('plan_limit_agent_api', $bySlug['starter-acct']['reason']);
        self::assertSame([], $bySlug['starter-acct']['boards']);
    }

    public function test_unknown_client_or_redirect_mismatch_is_never_redirected(): void
    {
        [$userId] = $this->owner();
        $clientId = $this->registerClient();

        $unknown = $this->app()->handle($this->contextRequest($userId, $this->authParams('vpc_unknown')));
        self::assertSame(400, $unknown->getStatusCode());
        self::assertSame('invalid_client', $this->json($unknown)['error']['key']);
        self::assertArrayNotHasKey('redirect_to', $this->json($unknown)['error']);

        $mismatch = $this->app()->handle($this->contextRequest($userId, $this->authParams($clientId, ['redirect_uri' => 'http://127.0.0.1:33418/other'])));
        self::assertSame(400, $mismatch->getStatusCode());
        self::assertSame('invalid_redirect_uri', $this->json($mismatch)['error']['key']);
        self::assertArrayNotHasKey('redirect_to', $this->json($mismatch)['error']);
    }

    public function test_pkce_is_mandatory_and_s256_only(): void
    {
        [$userId] = $this->owner();
        $clientId = $this->registerClient();

        foreach ([['code_challenge_method' => 'plain'], ['code_challenge' => ''], ['code_challenge_method' => '']] as $override) {
            $response = $this->app()->handle($this->contextRequest($userId, $this->authParams($clientId, $override)));
            self::assertSame(400, $response->getStatusCode());
            $error = $this->json($response)['error'];
            self::assertSame('invalid_request', $error['key']);
            $query = $this->redirectQuery($error['redirect_to']);
            self::assertSame('invalid_request', $query['error']);
            self::assertSame('st-123', $query['state']);
        }

        $scope = $this->app()->handle($this->contextRequest($userId, $this->authParams($clientId, ['scope' => 'admin'])));
        self::assertSame('invalid_scope', $this->json($scope)['error']['key']);

        $target = $this->app()->handle($this->contextRequest($userId, $this->authParams($clientId, ['resource' => 'https://evil.example/api/v1/mcp'])));
        self::assertSame('invalid_target', $this->json($target)['error']['key']);
    }

    public function test_consent_post_requires_csrf(): void
    {
        [$userId, $slug] = $this->owner();
        $this->insertBoard('csrf-board');
        $clientId = $this->registerClient();

        $response = $this->app()->handle($this->consentRequest($userId, $this->authParams($clientId) + [
            'decision' => 'approve',
            'account'  => $slug,
            'boards'   => [['slug' => 'csrf-board', 'scope' => 'read']],
        ], withCsrf: false));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM oauth_authorization_codes'));
    }

    public function test_deny_redirects_with_access_denied(): void
    {
        [$userId] = $this->owner();
        $clientId = $this->registerClient();

        $response = $this->app()->handle($this->consentRequest($userId, $this->authParams($clientId) + ['decision' => 'deny']));

        self::assertSame(200, $response->getStatusCode());
        $query = $this->redirectQuery((string) $this->json($response)['redirect_to']);
        self::assertSame('access_denied', $query['error']);
        self::assertSame('st-123', $query['state']);
        self::assertSame('http://localhost:8000', $query['iss']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM oauth_authorization_codes'));
    }

    public function test_consent_rejects_foreign_accounts_and_foreign_boards(): void
    {
        [$userId, $slug] = $this->owner();
        $this->insertBoard('own-board');
        $clientId = $this->registerClient();

        $foreign = $this->insertAccount(['slug' => 'victim-acct']);
        $this->insertBoard('victim-board', ['account_id' => $foreign, 'is_default' => 0]);

        // Cross-tenant: an account the user is not a member of.
        $notMember = $this->app()->handle($this->consentRequest($userId, $this->authParams($clientId) + [
            'decision' => 'approve',
            'account'  => 'victim-acct',
            'boards'   => [['slug' => 'victim-board', 'scope' => 'read']],
        ]));
        self::assertSame(403, $notMember->getStatusCode());

        // Same 403 for a non-existent account (no existence oracle).
        $missing = $this->app()->handle($this->consentRequest($userId, $this->authParams($clientId) + [
            'decision' => 'approve',
            'account'  => 'does-not-exist',
            'boards'   => [['slug' => 'own-board', 'scope' => 'read']],
        ]));
        self::assertSame(403, $missing->getStatusCode());

        // Cross-tenant: a board of another account under the user's own account.
        $foreignBoard = $this->app()->handle($this->consentRequest($userId, $this->authParams($clientId) + [
            'decision' => 'approve',
            'account'  => $slug,
            'boards'   => [['slug' => 'victim-board', 'scope' => 'read']],
        ]));
        self::assertSame(422, $foreignBoard->getStatusCode());

        // Moderators cannot delegate even their own account.
        $moderatorId = $this->insertUser('mod@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $moderatorId, 'moderator');
        $moderator = $this->app()->handle($this->consentRequest($moderatorId, $this->authParams($clientId) + [
            'decision' => 'approve',
            'account'  => $slug,
            'boards'   => [['slug' => 'own-board', 'scope' => 'read']],
        ]));
        self::assertSame(403, $moderator->getStatusCode());

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM oauth_authorization_codes'));
    }

    public function test_consent_enforces_scope_ceiling_and_plan(): void
    {
        [$userId, $slug] = $this->owner();
        $this->insertBoard('cap-board');
        $clientId = $this->registerClient();

        $tooHigh = $this->app()->handle($this->consentRequest($userId, $this->authParams($clientId, ['scope' => 'read']) + [
            'decision' => 'approve',
            'account'  => $slug,
            'boards'   => [['slug' => 'cap-board', 'scope' => 'write']],
        ]));
        self::assertSame(422, $tooHigh->getStatusCode());

        $none = $this->app()->handle($this->consentRequest($userId, $this->authParams($clientId) + [
            'decision' => 'approve',
            'account'  => $slug,
            'boards'   => [],
        ]));
        self::assertSame(422, $none->getStatusCode());

        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $plan = $this->app()->handle($this->consentRequest($userId, $this->authParams($clientId) + [
            'decision' => 'approve',
            'account'  => $slug,
            'boards'   => [['slug' => 'cap-board', 'scope' => 'read']],
        ]));
        self::assertSame(422, $plan->getStatusCode());
        self::assertSame('plan_limit_agent_api', $this->json($plan)['error']['key']);
    }

    // ── Token exchange ───────────────────────────────────────────────────

    public function test_full_flow_issues_tokens_that_work_on_mcp(): void
    {
        [, $tokens] = $this->fullFlow();

        self::assertSame('Bearer', $tokens['token_type']);
        self::assertSame(3600, $tokens['expires_in']);
        self::assertSame('write', $tokens['scope']);
        self::assertStringStartsWith('vpat_', $tokens['access_token']);
        self::assertStringStartsWith('vprt_', $tokens['refresh_token']);

        $response = $this->app()->handle($this->mcpRequest($tokens['access_token']));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('OAuth Board', (string) $response->getBody());

        // Only hashes are stored.
        $vault = new TokenVault();
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM oauth_tokens WHERE token_hash = ?', [$vault->hash($tokens['access_token'])]));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM oauth_tokens WHERE token_hash = ?', [$tokens['access_token']]));

        $grant = $this->conn->fetchAssociative('SELECT label, oauth_client_id FROM api_tokens');
        self::assertIsArray($grant);
        self::assertSame('Claude', $grant['label']);
        self::assertNotNull($grant['oauth_client_id']);
        self::assertStringContainsString('oauth.grant_issued', $this->readAuditLog());
    }

    public function test_access_token_is_audience_bound_to_the_mcp_resource(): void
    {
        [, $tokens] = $this->fullFlow();

        // The same valid access token is an unknown token on the REST routes.
        $rest = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/board')
                ->withHeader('Authorization', 'Bearer ' . $tokens['access_token']),
        );
        self::assertSame(401, $rest->getStatusCode());
        self::assertSame('Bearer error="invalid_token"', $rest->getHeaderLine('WWW-Authenticate'));

        self::assertSame(200, $this->app()->handle($this->mcpRequest($tokens['access_token']))->getStatusCode());
    }

    public function test_access_token_is_scoped_to_consented_boards(): void
    {
        [$userId, $slug] = $this->owner();
        $this->insertBoard('granted-board', ['name' => 'Granted']);
        $this->insertBoard('other-board', ['name' => 'Other', 'is_default' => 0]);
        $foreign = $this->insertAccount(['slug' => 'leak-acct']);
        $this->insertBoard('granted-board', ['account_id' => $foreign, 'is_default' => 0, 'name' => 'Foreign']);

        $clientId = $this->registerClient();
        $code     = $this->authorize($userId, $clientId, $slug, [['slug' => 'granted-board', 'scope' => 'read']]);
        $tokens   = $this->exchange($clientId, $code);

        $other = $this->app()->handle($this->mcpRequest($tokens['access_token'], query: '?board=other-board'));
        self::assertSame(403, $other->getStatusCode());

        $own = $this->app()->handle($this->mcpRequest($tokens['access_token']));
        self::assertStringContainsString('Granted', (string) $own->getBody());
        self::assertStringNotContainsString('Foreign', (string) $own->getBody());

        // A read grant cannot write.
        $write = $this->app()->handle($this->mcpRequest($tokens['access_token'], params: ['name' => 'create_idea', 'arguments' => ['title' => 'Nope nope', 'body' => 'Body text here']]));
        self::assertStringContainsString('This token is read-only', (string) $write->getBody());
    }

    public function test_code_is_single_use_and_replay_revokes_the_grant(): void
    {
        [$userId, $slug] = $this->owner();
        $this->insertBoard('replay-board');
        $clientId = $this->registerClient();
        $code     = $this->authorize($userId, $clientId, $slug, [['slug' => 'replay-board', 'scope' => 'read']]);

        $first = $this->exchange($clientId, $code);
        self::assertSame(200, $first['status']);

        $second = $this->exchange($clientId, $code);
        self::assertSame(400, $second['status']);
        self::assertSame('invalid_grant', $second['error']);

        self::assertSame(401, $this->app()->handle($this->mcpRequest($first['access_token']))->getStatusCode());
        self::assertSame(400, $this->refresh($clientId, $first['refresh_token'])['status']);
        self::assertStringContainsString('oauth.code_reuse_detected', $this->readAuditLog());
    }

    public function test_wrong_verifier_or_redirect_burns_the_code(): void
    {
        [$userId, $slug] = $this->owner();
        $this->insertBoard('burn-board');
        $clientId = $this->registerClient();

        $code = $this->authorize($userId, $clientId, $slug, [['slug' => 'burn-board', 'scope' => 'read']]);
        self::assertSame('invalid_grant', $this->exchange($clientId, $code, verifier: str_repeat('x', 43))['error']);
        self::assertSame('invalid_grant', $this->exchange($clientId, $code)['error'], 'a burned code stays dead');

        $code2 = $this->authorize($userId, $clientId, $slug, [['slug' => 'burn-board', 'scope' => 'read']]);
        self::assertSame('invalid_grant', $this->exchange($clientId, $code2, redirect: 'http://127.0.0.1:33418/other')['error']);

        // Another (registered) client cannot redeem someone else's code.
        $code3 = $this->authorize($userId, $clientId, $slug, [['slug' => 'burn-board', 'scope' => 'read']]);
        $other = $this->registerClient('Other');
        self::assertSame('invalid_grant', $this->exchange($other, $code3)['error']);

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM api_tokens'));
    }

    public function test_expired_code_is_rejected(): void
    {
        $clock = new FixedClock('2026-10-01 10:00:00');
        $this->app = $this->createApp(clock: $clock);
        [$userId, $slug] = $this->owner();
        $this->insertBoard('exp-board');
        $clientId = $this->registerClient();
        $code     = $this->authorize($userId, $clientId, $slug, [['slug' => 'exp-board', 'scope' => 'read']]);

        $clock->set('2026-10-01 10:02:01');

        self::assertSame('invalid_grant', $this->exchange($clientId, $code)['error']);
    }

    public function test_token_endpoint_rejects_unknown_client_and_json_bodies(): void
    {
        $unknown = $this->app()->handle($this->formRequest('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => 'vpc_nope', 'refresh_token' => 'x']));
        self::assertSame(401, $unknown->getStatusCode());
        self::assertSame('invalid_client', $this->json($unknown)['error']);

        $json = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/oauth/token')
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody(['grant_type' => 'authorization_code']),
        );
        self::assertSame(400, $json->getStatusCode());

        $clientId = $this->registerClient();
        $grant    = $this->app()->handle($this->formRequest('/oauth/token', ['grant_type' => 'password', 'client_id' => $clientId]));
        self::assertSame('unsupported_grant_type', $this->json($grant)['error']);
    }

    // ── Refresh ──────────────────────────────────────────────────────────

    public function test_refresh_rotates_and_reuse_revokes_the_family(): void
    {
        [$clientId, $tokens] = $this->fullFlow();

        $rotated = $this->refresh($clientId, $tokens['refresh_token']);
        self::assertSame(200, $rotated['status']);
        self::assertNotSame($tokens['refresh_token'], $rotated['refresh_token']);
        self::assertSame(200, $this->app()->handle($this->mcpRequest($rotated['access_token']))->getStatusCode());

        // Replaying the old refresh token: the family dies.
        $reuse = $this->refresh($clientId, $tokens['refresh_token']);
        self::assertSame('invalid_grant', $reuse['error']);
        self::assertSame(401, $this->app()->handle($this->mcpRequest($rotated['access_token']))->getStatusCode());
        self::assertSame('invalid_grant', $this->refresh($clientId, $rotated['refresh_token'])['error']);
        self::assertStringContainsString('oauth.refresh_reuse_detected', $this->readAuditLog());
    }

    public function test_refresh_rechecks_the_consenting_users_role(): void
    {
        [$clientId, $tokens] = $this->fullFlow();
        $this->conn->executeStatement("UPDATE account_members SET role = 'moderator'");

        self::assertSame('invalid_grant', $this->refresh($clientId, $tokens['refresh_token'])['error']);
        self::assertNotNull($this->conn->fetchOne('SELECT revoked_at FROM api_tokens'));
    }

    public function test_access_token_expires(): void
    {
        $clock = new FixedClock('2026-10-01 10:00:00');
        $this->app = $this->createApp(clock: $clock);
        [, $tokens] = $this->fullFlow();

        $clock->set('2026-10-01 11:00:01');

        self::assertSame(401, $this->app()->handle($this->mcpRequest($tokens['access_token']))->getStatusCode());
    }

    // ── Revocation + same path as static tokens ──────────────────────────

    public function test_revocation_endpoint(): void
    {
        [$clientId, $tokens] = $this->fullFlow();

        // Unknown token: 200, nothing happens.
        $unknown = $this->app()->handle($this->formRequest('/oauth/revoke', ['client_id' => $clientId, 'token' => 'vprt_nope']));
        self::assertSame(200, $unknown->getStatusCode());

        // Another client cannot revoke this client's tokens.
        $other = $this->registerClient('Other');
        $this->app()->handle($this->formRequest('/oauth/revoke', ['client_id' => $other, 'token' => $tokens['refresh_token']]));
        self::assertSame(200, $this->app()->handle($this->mcpRequest($tokens['access_token']))->getStatusCode());

        // Revoking the access token only kills it.
        $this->app()->handle($this->formRequest('/oauth/revoke', ['client_id' => $clientId, 'token' => $tokens['access_token']]));
        self::assertSame(401, $this->app()->handle($this->mcpRequest($tokens['access_token']))->getStatusCode());
        $refreshed = $this->refresh($clientId, $tokens['refresh_token']);
        self::assertSame(200, $refreshed['status']);

        // Revoking the refresh token kills the grant.
        $this->app()->handle($this->formRequest('/oauth/revoke', ['client_id' => $clientId, 'token' => $refreshed['refresh_token']]));
        self::assertSame(401, $this->app()->handle($this->mcpRequest($refreshed['access_token']))->getStatusCode());
    }

    public function test_grant_is_listed_and_revocable_on_the_token_page(): void
    {
        [$clientId, $tokens] = $this->fullFlow();
        $ownerId = (int) $this->conn->fetchOne('SELECT created_by_user_id FROM api_tokens');

        $list = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/tokens')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($ownerId)]),
        );
        $entry = $this->json($list)['tokens'][0];
        self::assertSame('Claude', $entry['oauth_client_name']);
        self::assertTrue($entry['is_oauth']);

        $csrf  = $this->csrf();
        $token = $csrf->generate();
        $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/admin/tokens/' . $entry['id'] . '/revoke')
                ->withCookieParams([$csrf->cookieName() => $csrf->sign($token), 'votepit_sess' => $this->sessionCookie($ownerId)])
                ->withParsedBody(['_csrf' => $token]),
        );

        self::assertSame(401, $this->app()->handle($this->mcpRequest($tokens['access_token']))->getStatusCode());
        self::assertSame('invalid_grant', $this->refresh($clientId, $tokens['refresh_token'])['error']);
    }

    public function test_static_tokens_keep_working_and_grant_rows_are_not_static_tokens(): void
    {
        [, $tokens] = $this->fullFlow();
        $boardId = (int) $this->conn->fetchOne("SELECT id FROM boards WHERE slug = 'oauth-board'");
        $userId  = (int) $this->conn->fetchOne('SELECT created_by_user_id FROM api_tokens');

        $vault  = new TokenVault();
        $static = $vault->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $userId, $static['hash'], 'Static');
        self::assertSame(200, $this->app()->handle($this->mcpRequest($static['token']))->getStatusCode());

        // The refresh token is never accepted as a bearer credential.
        self::assertSame(401, $this->app()->handle($this->mcpRequest($tokens['refresh_token']))->getStatusCode());
    }

    public function test_plan_downgrade_revokes_oauth_grants_like_static_tokens(): void
    {
        [, $tokens] = $this->fullFlow();

        (new \Votepit\Persistence\ApiTokenRepository($this->conn))->revokeAllForAccount($this->defaultAccountId());

        self::assertSame(401, $this->app()->handle($this->mcpRequest($tokens['access_token']))->getStatusCode());
    }

    public function test_user_erasure_revokes_their_oauth_grants(): void
    {
        [, $tokens] = $this->fullFlow();
        $userId      = (int) $this->conn->fetchOne('SELECT created_by_user_id FROM api_tokens');
        $placeholder = $this->insertUser('placeholder@example.com');

        (new \Votepit\Persistence\UserErasureRepository($this->conn))->erase($userId, $placeholder);

        self::assertSame(401, $this->app()->handle($this->mcpRequest($tokens['access_token']))->getStatusCode());
    }
}
