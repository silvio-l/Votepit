<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\EncryptionService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\OAuth\InMemoryOAuthHttpClient;
use Votepit\Security\Totp;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for OAuth2 login (Google/GitHub) — GET /login/oauth/
 * {provider}/start + GET /login/oauth/{provider}/callback. Additive to
 * magic-link/password/TOTP — lands on the SAME LoginSessionIssuer as those
 * flows (see LoginPasswordAndTwoFaActionTest for the equivalent coverage of
 * the other login paths).
 *
 * AppFactory defaults to a REAL CurlOAuthHttpClient in production, but
 * exposes an $oauthHttpClient test seam (mirrored from the existing
 * $mailer/$planPolicy pattern) — see IntegrationTestCase::createApp().
 * Tests here drive OAuthStartAction/OAuthCallbackAction's HTTP-level
 * contract against the real app, using a real state row inserted the same
 * way OAuthStartAction would and an InMemoryOAuthHttpClient standing in
 * for the provider's token/profile endpoints.
 */
final class OAuthLoginActionTest extends IntegrationTestCase
{
    protected function testConfig(): Config
    {
        return Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'oauth_providers'     => [
                'google' => ['client_id' => 'google-client-id', 'client_secret' => 'google-client-secret'],
                'github' => ['client_id' => 'github-client-id', 'client_secret' => 'github-client-secret'],
            ],
            'rate_limits'         => [
                'login:oauth' => ['limit' => 100, 'window' => 900],
            ],
        ]);
    }

    private function get(string $path, string $remoteAddr = '127.0.0.1'): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path, ['REMOTE_ADDR' => $remoteAddr]);
    }

    // ── GET /login/oauth/{provider}/start ───────────────────────────────

    public function test_start_with_unknown_provider_is_404(): void
    {
        $response = $this->createApp()->handle($this->get('/login/oauth/nope/start'));
        self::assertSame(404, $response->getStatusCode());

        // No side effect: nothing was inserted.
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM oauth_states'));
    }

    public function test_start_with_unconfigured_provider_is_404(): void
    {
        // testConfig() below is a fresh app whose config has NEITHER provider set.
        $app = \Votepit\Http\AppFactory::create(
            Config::fromArray([
                'env' => 'dev', 'app_url' => 'http://localhost:8000',
                'app_key' => str_repeat('a', 64), 'identity_server_key' => self::identityServerKey(),
                'db' => ['name' => ':memory:'], 'smtp' => ['from_email' => 'noreply@example.com'],
            ]),
            $this->conn,
            new \Votepit\Mail\InMemoryMailer(),
            new \Votepit\Logging\AuditLogger($this->logFile),
            avatarDirOverride: $this->avatarDir,
            planPolicy: self::syntheticPlanPolicy(),
            extensions: [],
        );

        $response = $app->handle($this->get('/login/oauth/google/start'));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_start_redirects_to_the_provider_with_a_state_param_and_stores_it(): void
    {
        $response = $this->createApp()->handle($this->get('/login/oauth/google/start'));

        self::assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        self::assertStringContainsString('client_id=google-client-id', $location);
        self::assertStringContainsString('redirect_uri=' . rawurlencode('http://localhost:8000/login/oauth/google/callback'), $location);
        self::assertStringContainsString('code_challenge=', $location); // Google: PKCE
        self::assertStringContainsString('code_challenge_method=S256', $location);
        self::assertStringContainsString('state=', $location);

        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM oauth_states'));
        $row = $this->conn->fetchAssociative('SELECT provider, code_verifier, used_at FROM oauth_states LIMIT 1');
        self::assertIsArray($row);
        self::assertSame('google', $row['provider']);
        self::assertNotNull($row['code_verifier']);
        self::assertNull($row['used_at']);
    }

    public function test_start_for_github_has_no_pkce_params(): void
    {
        $response = $this->createApp()->handle($this->get('/login/oauth/github/start'));
        self::assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);
        self::assertStringNotContainsString('code_challenge', $location);

        $row = $this->conn->fetchAssociative('SELECT code_verifier FROM oauth_states LIMIT 1');
        self::assertIsArray($row);
        self::assertNull($row['code_verifier']);
    }

    // ── GET /login/oauth/{provider}/callback — validation failures ─────

    public function test_callback_with_unknown_provider_is_404(): void
    {
        $response = $this->createApp()->handle($this->get('/login/oauth/nope/callback?state=x&code=y'));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_callback_with_missing_state_is_rejected(): void
    {
        $response = $this->createApp()->handle($this->get('/login/oauth/google/callback?code=abc'));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_callback_with_unknown_state_is_rejected(): void
    {
        $response = $this->createApp()->handle($this->get('/login/oauth/google/callback?state=not-a-real-state&code=abc'));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_callback_with_an_expired_state_is_rejected(): void
    {
        $state = $this->seedState('google', expiresAt: '-1 second');

        $response = $this->createApp()->handle($this->get("/login/oauth/google/callback?state={$state}&code=abc"));
        self::assertSame(400, $response->getStatusCode());
    }

    public function test_callback_replaying_the_same_state_twice_fails_the_second_time(): void
    {
        $state = $this->seedState('google');
        $app   = $this->appWithGoogleHappyPath();

        $first = $app->handle($this->get("/login/oauth/google/callback?state={$state}&code=abc"));
        self::assertSame(200, $first->getStatusCode());

        $second = $app->handle($this->get("/login/oauth/google/callback?state={$state}&code=abc"));
        self::assertSame(400, $second->getStatusCode());

        // Only one user/identity was created, despite two callback hits.
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users'));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM oauth_identities'));
    }

    public function test_callback_with_a_provider_error_param_fails_without_creating_a_user(): void
    {
        $state    = $this->seedState('google');
        $response = $this->createApp()->handle($this->get("/login/oauth/google/callback?state={$state}&error=access_denied"));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    // ── Happy path ───────────────────────────────────────────────────────

    public function test_google_happy_path_creates_a_user_links_identity_and_issues_a_session(): void
    {
        $state = $this->seedState('google');
        $app   = $this->appWithGoogleHappyPath();

        $response = $app->handle($this->get("/login/oauth/google/callback?state={$state}&code=abc"));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok']);
        self::assertStringContainsString('votepit_sess=', implode(';', $response->getHeader('Set-Cookie')));

        $expectedHmac = (new IdentityHasher(self::identityServerKey()))->hash('google-user@example.com');
        $user = $this->conn->fetchAssociative('SELECT id, email_hmac, verified_at FROM users');
        self::assertIsArray($user);
        self::assertSame($expectedHmac, $user['email_hmac']);
        self::assertNotNull($user['verified_at']);

        $identity = $this->conn->fetchAssociative('SELECT provider, provider_user_id, user_id FROM oauth_identities');
        self::assertIsArray($identity);
        self::assertSame('google', $identity['provider']);
        self::assertSame('google-sub-123', $identity['provider_user_id']);
        self::assertSame((int) $user['id'], (int) $identity['user_id']);
    }

    public function test_github_happy_path_fetches_the_primary_verified_email_and_issues_a_session(): void
    {
        $state = $this->seedState('github');
        $http  = new InMemoryOAuthHttpClient();
        $http->stub('https://github.com/login/oauth/access_token', [
            'status' => 200,
            'body'   => ['access_token' => 'gh-token', 'token_type' => 'bearer'],
        ]);
        $http->stub('https://api.github.com/user', [
            'status' => 200,
            'body'   => ['id' => 987654, 'login' => 'octocat', 'email' => null],
        ]);
        $http->stub('https://api.github.com/user/emails', [
            'status' => 200,
            'body'   => [
                ['email' => 'secondary@example.com', 'primary' => false, 'verified' => true],
                ['email' => 'github-user@example.com', 'primary' => true, 'verified' => true],
            ],
        ]);
        $app = $this->appWithHttpClient($http);

        $response = $app->handle($this->get("/login/oauth/github/callback?state={$state}&code=abc"));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('votepit_sess=', implode(';', $response->getHeader('Set-Cookie')));

        $expectedHmac = (new IdentityHasher(self::identityServerKey()))->hash('github-user@example.com');
        $user = $this->conn->fetchAssociative('SELECT email_hmac FROM users');
        self::assertIsArray($user);
        self::assertSame($expectedHmac, $user['email_hmac']);

        $identity = $this->conn->fetchAssociative('SELECT provider, provider_user_id FROM oauth_identities');
        self::assertIsArray($identity);
        self::assertSame('github', $identity['provider']);
        self::assertSame('987654', $identity['provider_user_id']);
    }

    public function test_github_without_a_verified_primary_email_fails_and_creates_no_user(): void
    {
        $state = $this->seedState('github');
        $http  = new InMemoryOAuthHttpClient();
        $http->stub('https://github.com/login/oauth/access_token', ['status' => 200, 'body' => ['access_token' => 'gh-token']]);
        $http->stub('https://api.github.com/user', ['status' => 200, 'body' => ['id' => 1, 'email' => null]]);
        $http->stub('https://api.github.com/user/emails', [
            'status' => 200,
            'body'   => [['email' => 'unverified@example.com', 'primary' => true, 'verified' => false]],
        ]);
        $app = $this->appWithHttpClient($http);

        $response = $app->handle($this->get("/login/oauth/github/callback?state={$state}&code=abc"));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_google_without_a_verified_email_fails_and_creates_no_user(): void
    {
        $state = $this->seedState('google');
        $http  = new InMemoryOAuthHttpClient();
        $http->stub('https://oauth2.googleapis.com/token', ['status' => 200, 'body' => ['access_token' => 'gtok']]);
        $http->stub('https://www.googleapis.com/oauth2/v3/userinfo', [
            'status' => 200,
            'body'   => ['sub' => 'sub-1', 'email' => 'unverified@example.com', 'email_verified' => false],
        ]);
        $app = $this->appWithHttpClient($http);

        $response = $app->handle($this->get("/login/oauth/google/callback?state={$state}&code=abc"));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_token_exchange_failure_is_a_generic_error_and_creates_no_user(): void
    {
        $state = $this->seedState('google');
        $http  = new InMemoryOAuthHttpClient();
        $http->stub('https://oauth2.googleapis.com/token', ['status' => 400, 'body' => ['error' => 'invalid_grant']]);
        $app = $this->appWithHttpClient($http);

        $response = $app->handle($this->get("/login/oauth/google/callback?state={$state}&code=abc"));
        self::assertSame(400, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('invalid_grant', $body);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    // ── Account linking ──────────────────────────────────────────────────

    public function test_existing_magic_link_user_signing_in_via_google_links_onto_the_existing_account(): void
    {
        $existingUserId = $this->insertUser('google-user@example.com');

        $state = $this->seedState('google');
        $app   = $this->appWithGoogleHappyPath();
        $response = $app->handle($this->get("/login/oauth/google/callback?state={$state}&code=abc"));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users'));

        $identity = $this->conn->fetchAssociative('SELECT user_id FROM oauth_identities');
        self::assertIsArray($identity);
        self::assertSame($existingUserId, (int) $identity['user_id']);
    }

    public function test_a_second_google_login_with_the_same_identity_does_not_create_a_duplicate_user(): void
    {
        $state1 = $this->seedState('google');
        $app    = $this->appWithGoogleHappyPath();
        $app->handle($this->get("/login/oauth/google/callback?state={$state1}&code=abc"));

        $state2 = $this->seedState('google');
        $response = $app->handle($this->get("/login/oauth/google/callback?state={$state2}&code=abc"));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users'));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM oauth_identities'));
    }

    // ── TOTP 2FA gate ─────────────────────────────────────────────────────

    public function test_user_with_totp_enabled_gets_a_pending_token_not_a_session(): void
    {
        $secret = (new Totp())->generateSecret();
        $this->insertUser('google-user@example.com', [
            'totp_secret_encrypted' => (new EncryptionService(str_repeat('a', 64), 'totp'))->encrypt($secret),
            'totp_enabled_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $state = $this->seedState('google');
        $app   = $this->appWithGoogleHappyPath();
        $response = $app->handle($this->get("/login/oauth/google/callback?state={$state}&code=abc"));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['requires_2fa']);
        self::assertIsString($data['pending_token']);
        self::assertStringNotContainsString('votepit_sess=', implode(';', $response->getHeader('Set-Cookie')));

        $purpose = $this->conn->fetchOne('SELECT purpose FROM login_tokens ORDER BY id DESC LIMIT 1');
        self::assertSame('2fa_pending', $purpose);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Inserts an active oauth_states row the same way OAuthStartAction would, returns the PLAINTEXT state. */
    private function seedState(string $provider, string $expiresAt = '+600 seconds', ?string $codeVerifier = 'fixed-test-verifier'): string
    {
        $plain = bin2hex(random_bytes(32));
        $this->conn->insert('oauth_states', [
            'state_hash'    => hash('sha256', $plain),
            'provider'      => $provider,
            'code_verifier' => $provider === 'google' ? $codeVerifier : null,
            'return_to'     => null,
            'expires_at'    => (new \DateTimeImmutable($expiresAt))->format('Y-m-d H:i:s'),
            'used_at'       => null,
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $plain;
    }

    /** @return \Slim\App<null> */
    private function appWithGoogleHappyPath(): \Slim\App
    {
        $http = new InMemoryOAuthHttpClient();
        $http->stub('https://oauth2.googleapis.com/token', [
            'status' => 200,
            'body'   => ['access_token' => 'g-token', 'token_type' => 'Bearer'],
        ]);
        $http->stub('https://www.googleapis.com/oauth2/v3/userinfo', [
            'status' => 200,
            'body'   => ['sub' => 'google-sub-123', 'email' => 'google-user@example.com', 'email_verified' => true],
        ]);

        return $this->appWithHttpClient($http);
    }

    /**
     * Builds the app with a swapped-in OAuthHttpClient via
     * IntegrationTestCase::createApp()'s $oauthHttpClient seam (mirrors the
     * existing $mailer/$planPolicy test-injection pattern) — AppFactory
     * itself defaults to the real CurlOAuthHttpClient outside tests.
     * @return \Slim\App<null>
     */
    private function appWithHttpClient(InMemoryOAuthHttpClient $http): \Slim\App
    {
        return $this->createApp(oauthHttpClient: $http);
    }
}
