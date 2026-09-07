<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Http\AppFactory;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\IdentityHasher;
use Votepit\Security\PublicIdGenerator;
use Votepit\Security\SessionService;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /login/verify.
 *
 * Boots via AppFactory with SQLite in-memory. Tokens are seeded directly into
 * the test DB (only the hash; the plaintext stays in the test). Assertions
 * check observable behavior: HTTP status/redirect, Set-Cookie, DB state,
 * audit log — no private methods.
 */
final class VerifyActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Seeds a user; returns its ID. */
    private function seedUser(string $email, int $isAdmin = 0): int
    {
        $this->conn->insert('users', [
            'public_id'   => PublicIdGenerator::generate(),
            'email_hmac'  => (new IdentityHasher(self::identityServerKey()))->hash($email),
            'is_admin'    => $isAdmin,
            'is_blocked'  => 0,
            'verified_at' => null,
            'created_at'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->lastInsertId();
    }

    /** Seeds a login token (hash only); returns the token ID. */
    private function seedToken(int $userId, string $plaintext, string $expiresAt, ?string $usedAt = null): int
    {
        $this->conn->insert('login_tokens', [
            'user_id'    => $userId,
            'token_hash' => (new TokenVault())->hash($plaintext),
            'purpose'    => 'login',
            'expires_at' => $expiresAt,
            'used_at'    => $usedAt,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->lastInsertId();
    }

    private function future(): string
    {
        return (new \DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s');
    }

    private function past(): string
    {
        return (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $cookies */
    private function verifyRequest(string $token, array $cookies = [], ?string $returnTo = null): ServerRequestInterface
    {
        $params = ['token' => $token];
        if ($returnTo !== null) {
            $params['r'] = $returnTo;
        }

        return (new ServerRequestFactory())->createServerRequest('GET', '/login/verify')
            ->withQueryParams($params)
            ->withCookieParams($cookies);
    }

    private function cookieValue(ResponseInterface $response, string $name): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, $name . '=')) {
                $first = explode(';', $header, 2)[0];
                return substr($first, strlen($name) + 1);
            }
        }
        return null;
    }

    private function sessions(): SessionService
    {
        return new SessionService(self::APP_KEY, 3600, false);
    }

    /**
     * App with an optional admin allowlist (otherwise the default test config).
     *
     * @return \Slim\App<null>
     */
    private function appWithAdmins(string ...$adminEmails): \Slim\App
    {
        $config = Config::fromArray([
            'env'            => 'dev',
            'app_url'        => 'http://localhost:8000',
            'app_key'        => self::APP_KEY,
            'identity_server_key' => self::identityServerKey(),
            'db'             => ['name' => ':memory:'],
            'smtp'           => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl' => 900,
            'admin_emails'   => $adminEmails,
        ]);

        return AppFactory::create($config, $this->conn, new InMemoryMailer(), new AuditLogger($this->logFile));
    }

    // -------------------------------------------------------------------------
    // AC1: valid link → logged in + signed session cookie + redirect
    // -------------------------------------------------------------------------

    public function test_valid_token_logs_in_and_redirects_with_signed_session(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('user@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $response = $this->createApp()->handle($this->verifyRequest($plain));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertTrue($data['ok'] ?? false);
        self::assertSame('/', $data['redirect'] ?? null);

        $sessCookie = $this->cookieValue($response, 'votepit_sess');
        self::assertNotNull($sessCookie);

        $payload = $this->sessions()->verify($sessCookie);
        self::assertIsArray($payload);
        self::assertSame($userId, $payload['uid']);
        self::assertSame(0, $payload['v']);

        // used_at + verified_at set
        $row = $this->conn->fetchAssociative('SELECT used_at FROM login_tokens WHERE user_id = :id', ['id' => $userId]);
        self::assertIsArray($row);
        self::assertNotNull($row['used_at']);

        $verifiedAt = $this->conn->fetchOne('SELECT verified_at FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNotNull($verifiedAt);
    }

    // -------------------------------------------------------------------------
    // AC1 (persistence): AuthN hydrates the session on a follow-up request
    // -------------------------------------------------------------------------

    public function test_session_cookie_is_honoured_on_followup_request(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('persist@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $app        = $this->createApp();
        $login      = $app->handle($this->verifyRequest($plain));
        $sessCookie = $this->cookieValue($login, 'votepit_sess');
        self::assertNotNull($sessCookie);

        // A follow-up request with the session cookie is served without error (AuthN
        // loads the user via findById; a broken/missing hydration would
        // produce a 500 here).
        $followup = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withCookieParams(['votepit_sess' => $sessCookie]);
        $response = $app->handle($followup);

        self::assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC2: reusing the same link — succeeds idempotently within the grace
    // window (mail security gateway prescanning compensation, 2026-08-31),
    // rejected outright after that.
    // -------------------------------------------------------------------------

    public function test_immediate_token_reuse_succeeds_idempotently_within_grace_window(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('reuse@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $app    = $this->createApp();
        $first  = $app->handle($this->verifyRequest($plain));
        self::assertSame(200, $first->getStatusCode());

        // Simulates a mail security gateway prescan followed by the real click
        // seconds later — both get a valid session, no "invalid link"
        // failure for the real user.
        $second = $app->handle($this->verifyRequest($plain));
        self::assertSame(200, $second->getStatusCode());
        self::assertNotNull($this->cookieValue($second, 'votepit_sess'));
    }

    public function test_token_reuse_after_grace_window_is_rejected(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('reuse-stale@example.com');
        // used_at already set 3 minutes in the past (grace window:
        // LoginVerifyAction::REPLAY_GRACE_SECONDS = 120s) — simulates a
        // token that was already consumed well before this request.
        $this->seedToken($userId, $plain, $this->future(), (new \DateTimeImmutable('-3 minutes'))->format('Y-m-d H:i:s'));

        $response = $this->createApp()->handle($this->verifyRequest($plain));

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($this->cookieValue($response, 'votepit_sess'));
        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('invalid', $body['error']['message'] ?? '');
    }

    // -------------------------------------------------------------------------
    // AC3: expired link → rejected, no side effect
    // -------------------------------------------------------------------------

    public function test_expired_token_is_rejected_without_side_effect(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('expired@example.com');
        $this->seedToken($userId, $plain, $this->past());

        $response = $this->createApp()->handle($this->verifyRequest($plain));

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($this->cookieValue($response, 'votepit_sess'));

        $usedAt = $this->conn->fetchOne('SELECT used_at FROM login_tokens WHERE user_id = :id', ['id' => $userId]);
        self::assertNull($usedAt);
        $verifiedAt = $this->conn->fetchOne('SELECT verified_at FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNull($verifiedAt);
    }

    // -------------------------------------------------------------------------
    // AC4: unknown/garbage token → uniform 4xx, no side effect
    // -------------------------------------------------------------------------

    public function test_unknown_token_returns_4xx_without_side_effect(): void
    {
        // A real user+token exists, but must NOT be touched.
        $realPlain = bin2hex(random_bytes(32));
        $userId    = $this->seedUser('innocent@example.com');
        $this->seedToken($userId, $realPlain, $this->future());

        $response = $this->createApp()->handle($this->verifyRequest('deadbeef' . bin2hex(random_bytes(28))));

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($this->cookieValue($response, 'votepit_sess'));

        // The uninvolved token remains untouched.
        $usedAt = $this->conn->fetchOne('SELECT used_at FROM login_tokens WHERE user_id = :id', ['id' => $userId]);
        self::assertNull($usedAt);
    }

    public function test_empty_token_returns_4xx(): void
    {
        $response = $this->createApp()->handle($this->verifyRequest(''));
        self::assertSame(400, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC5: session fixation — pre-login cookie is replaced by a fresh one
    // -------------------------------------------------------------------------

    public function test_pre_login_session_cookie_is_replaced_not_honoured(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('fixation@example.com');
        $this->seedToken($userId, $plain, $this->future());

        // A validly signed pre-login cookie with a foreign uid is sent along.
        $stale = $this->sessions()->sign(['uid' => 999, 'v' => 0]);

        $response = $this->createApp()->handle(
            $this->verifyRequest($plain, ['votepit_sess' => $stale]),
        );

        self::assertSame(200, $response->getStatusCode());

        $fresh = $this->cookieValue($response, 'votepit_sess');
        self::assertNotNull($fresh);
        self::assertNotSame($stale, $fresh, 'Session cookie must be freshly issued');

        $payload = $this->sessions()->verify($fresh);
        self::assertIsArray($payload);
        self::assertSame($userId, $payload['uid']); // not 999 (old cookie ignored)
    }

    // -------------------------------------------------------------------------
    // AC6: verify response carries BOTH Set-Cookie headers (session + CSRF)
    // -------------------------------------------------------------------------

    public function test_verify_response_sets_both_session_and_csrf_cookies(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('cookies@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $response   = $this->createApp()->handle($this->verifyRequest($plain));
        $setCookies = $response->getHeader('Set-Cookie');

        self::assertCount(2, $setCookies);
        self::assertNotNull($this->cookieValue($response, 'votepit_sess'));
        self::assertNotNull($this->cookieValue($response, 'votepit_csrf'));
    }

    // -------------------------------------------------------------------------
    // AC7: admin promotion via allowlist; no silent downgrade
    // -------------------------------------------------------------------------

    public function test_allowlist_email_is_promoted_to_admin(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('boss@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $response = $this->appWithAdmins('boss@example.com')->handle($this->verifyRequest($plain));

        self::assertSame(200, $response->getStatusCode());
        $isAdmin = (int) $this->conn->fetchOne('SELECT is_admin FROM users WHERE id = :id', ['id' => $userId]);
        self::assertSame(1, $isAdmin);
    }

    public function test_non_allowlist_email_is_not_promoted(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('peon@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $this->appWithAdmins('boss@example.com')->handle($this->verifyRequest($plain));

        $isAdmin = (int) $this->conn->fetchOne('SELECT is_admin FROM users WHERE id = :id', ['id' => $userId]);
        self::assertSame(0, $isAdmin);
    }

    public function test_removing_email_from_allowlist_does_not_downgrade_existing_admin(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('exboss@example.com', isAdmin: 1); // already admin
        $this->seedToken($userId, $plain, $this->future());

        // Allowlist is now empty — login must NOT revoke admin.
        $response = $this->appWithAdmins()->handle($this->verifyRequest($plain));

        self::assertSame(200, $response->getStatusCode());
        $isAdmin = (int) $this->conn->fetchOne('SELECT is_admin FROM users WHERE id = :id', ['id' => $userId]);
        self::assertSame(1, $isAdmin);
    }

    // -------------------------------------------------------------------------
    // AC8: token_version column exists additively with a default of 0
    // -------------------------------------------------------------------------

    public function test_token_version_column_exists_with_default_zero(): void
    {
        $userId  = $this->seedUser('tv@example.com');
        $version = $this->conn->fetchOne('SELECT token_version FROM users WHERE id = :id', ['id' => $userId]);

        self::assertSame(0, (int) $version);
    }

    // -------------------------------------------------------------------------
    // AC9: audit log pseudonymized, NO plaintext token
    // -------------------------------------------------------------------------

    public function test_audit_log_is_pseudonymised_and_token_free(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $email  = 'audit-verify@example.com';
        $userId = $this->seedUser($email);
        $this->seedToken($userId, $plain, $this->future());

        $this->createApp()->handle($this->verifyRequest($plain));

        $log = $this->readAuditLog();
        self::assertStringContainsString('magic_link.verified', $log);
        self::assertStringNotContainsString($plain, $log);   // no plaintext token
        self::assertStringNotContainsString($email, $log);   // email masked
    }

    public function test_failed_verify_is_logged_without_token(): void
    {
        $garbage = bin2hex(random_bytes(32));

        $this->createApp()->handle($this->verifyRequest($garbage));

        $log = $this->readAuditLog();
        self::assertStringContainsString('magic_link.verify_failed', $log);
        self::assertStringNotContainsString($garbage, $log);
    }

    // -------------------------------------------------------------------------
    // Return-to (open-redirect-safe deep linking)
    // -------------------------------------------------------------------------

    public function test_valid_return_to_redirects_to_that_path(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('returnto@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $response = $this->createApp()->handle($this->verifyRequest($plain, [], '/some/board/path'));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('/some/board/path', $data['redirect'] ?? null);
    }

    public function test_protocol_relative_return_to_falls_back_to_default(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('rtproto@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $response = $this->createApp()->handle($this->verifyRequest($plain, [], '//evil.com'));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('/', $data['redirect'] ?? null);
    }

    public function test_absolute_url_return_to_falls_back_to_default(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('rtabs@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $response = $this->createApp()->handle($this->verifyRequest($plain, [], 'https://evil.com'));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('/', $data['redirect'] ?? null);
    }

    public function test_missing_return_to_redirects_to_default(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('rtnone@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $response = $this->createApp()->handle($this->verifyRequest($plain));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('/', $data['redirect'] ?? null);
    }

    /**
     * Cloud mode: bare '/' is a 404 (scoped routes are all /{account}-prefixed,
     * App.tsx) — a returning user with no explicit `r` must land on their own
     * account's admin dashboard instead (Fable-Audit 2026-09-02 P0 gap).
     */
    public function test_cloud_mode_missing_return_to_redirects_to_own_admin_dashboard(): void
    {
        $config = Config::fromArray([
            'env' => 'dev', 'app_url' => 'http://localhost:8000', 'app_key' => self::APP_KEY,
            'identity_server_key' => self::identityServerKey(), 'db' => ['name' => ':memory:'],
            'smtp' => ['from_email' => 'noreply@example.com'], 'magic_link_ttl' => 900,
            'routing_mode' => 'cloud',
        ]);
        $app = AppFactory::create($config, $this->conn, new InMemoryMailer(), new AuditLogger($this->logFile));

        $plain     = bin2hex(random_bytes(32));
        $userId    = $this->seedUser('returning@example.com');
        $this->seedToken($userId, $plain, $this->future());
        $accountId = $this->insertAccount(['slug' => 'acme']);
        $this->insertAccountMember($accountId, $userId, 'owner');

        $response = $app->handle($this->verifyRequest($plain));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('/acme/admin/boards', $data['redirect'] ?? null);
    }

    /** Cloud mode, no account yet (edge case): falls back to '/', unchanged. */
    public function test_cloud_mode_missing_return_to_falls_back_to_root_without_membership(): void
    {
        $config = Config::fromArray([
            'env' => 'dev', 'app_url' => 'http://localhost:8000', 'app_key' => self::APP_KEY,
            'identity_server_key' => self::identityServerKey(), 'db' => ['name' => ':memory:'],
            'smtp' => ['from_email' => 'noreply@example.com'], 'magic_link_ttl' => 900,
            'routing_mode' => 'cloud',
        ]);
        $app = AppFactory::create($config, $this->conn, new InMemoryMailer(), new AuditLogger($this->logFile));

        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('noaccount@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $response = $app->handle($this->verifyRequest($plain));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('/', $data['redirect'] ?? null);
    }

    /**
     * A plain member/moderator has no admin-panel access (AuthZMiddleware
     * accountAdmin()/accountModerate() exclude 'member', and 'moderator'
     * fails accountAdmin() too) — landing them on /admin/boards would 403.
     * Regression test for a security-review finding (2026-09-05).
     */
    public function test_cloud_mode_plain_member_redirects_to_board_home_not_admin_panel(): void
    {
        $config = Config::fromArray([
            'env' => 'dev', 'app_url' => 'http://localhost:8000', 'app_key' => self::APP_KEY,
            'identity_server_key' => self::identityServerKey(), 'db' => ['name' => ':memory:'],
            'smtp' => ['from_email' => 'noreply@example.com'], 'magic_link_ttl' => 900,
            'routing_mode' => 'cloud',
        ]);
        $app = AppFactory::create($config, $this->conn, new InMemoryMailer(), new AuditLogger($this->logFile));

        $plain     = bin2hex(random_bytes(32));
        $userId    = $this->seedUser('plainmember@example.com');
        $this->seedToken($userId, $plain, $this->future());
        $accountId = $this->insertAccount(['slug' => 'acme']);
        $this->insertAccountMember($accountId, $userId, 'member');

        $response = $app->handle($this->verifyRequest($plain));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('/acme', $data['redirect'] ?? null);
    }

    /** Owner membership wins over a moderator membership elsewhere, regardless of row order. */
    public function test_cloud_mode_prefers_owner_membership_over_moderator_membership(): void
    {
        $config = Config::fromArray([
            'env' => 'dev', 'app_url' => 'http://localhost:8000', 'app_key' => self::APP_KEY,
            'identity_server_key' => self::identityServerKey(), 'db' => ['name' => ':memory:'],
            'smtp' => ['from_email' => 'noreply@example.com'], 'magic_link_ttl' => 900,
            'routing_mode' => 'cloud',
        ]);
        $app = AppFactory::create($config, $this->conn, new InMemoryMailer(), new AuditLogger($this->logFile));

        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('multimember@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $foreignAccountId = $this->insertAccount(['slug' => 'foreign']);
        $this->insertAccountMember($foreignAccountId, $userId, 'moderator');
        $ownAccountId = $this->insertAccount(['slug' => 'own']);
        $this->insertAccountMember($ownAccountId, $userId, 'owner');

        $response = $app->handle($this->verifyRequest($plain));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('/own/admin/boards', $data['redirect'] ?? null);
    }
}
