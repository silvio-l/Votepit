<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the "forgot password" flow (POST /password/reset/request
 * + POST /password/reset/confirm): anti-enumeration on the request step, the
 * token lifecycle (single-use/expired/wrong-purpose all collapse into the same
 * generic error on confirm), the double-entry confirmation requirement, and
 * the dual email+IP rate-limit buckets mirroring POST /login's.
 */
final class PasswordResetActionTest extends IntegrationTestCase
{
    /** Low, deterministic rate limits — mirrors RateLimitLoginTest's approach. */
    protected function testConfig(): Config
    {
        return Config::fromArray([
            'env'                  => 'dev',
            'app_url'              => 'http://localhost:8000',
            'app_key'              => str_repeat('a', 64),
            'identity_server_key'  => self::identityServerKey(),
            'db'                   => ['name' => ':memory:'],
            'smtp'                 => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'       => 900,
            'rate_limits'          => [
                'password:reset:email' => ['limit' => 1, 'window' => 3600],
                'password:reset:ip'    => ['limit' => 2, 'window' => 3600],
            ],
        ]);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body = [], string $remoteAddr = '127.0.0.1'): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path, ['REMOTE_ADDR' => $remoteAddr])
            ->withCookieParams([$csrf->cookieName() => $signed])
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    private function userWithPassword(string $email, string $password): int
    {
        return $this->insertUser($email, ['password_hash' => password_hash($password, PASSWORD_ARGON2ID)]);
    }

    /** Extracts the plaintext reset token from the confirm link in a sent mail's text body. */
    private function extractToken(string $mailBody): string
    {
        preg_match('#token=([0-9a-f]{64})#', $mailBody, $m);
        self::assertArrayHasKey(1, $m, 'reset link with token expected in mail body');
        return $m[1];
    }

    // ── POST /password/reset/request — anti-enumeration ─────────────────

    public function test_request_for_an_existing_account_with_a_password_sends_a_mail_and_returns_ok(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $this->userWithPassword('resetme@example.com', 'old-password-1');

        $response = $app->handle($this->post('/password/reset/request', ['email' => 'resetme@example.com']));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok']);

        self::assertCount(1, $mailer->sent);
        self::assertSame('resetme@example.com', $mailer->sent[0]['to']);

        $tokenCount = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM login_tokens WHERE purpose = 'password_reset'",
        );
        self::assertSame(1, $tokenCount);
    }

    public function test_request_for_an_unknown_email_returns_the_identical_response_and_sends_no_mail(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $knownResponse = $app->handle($this->post('/password/reset/request', ['email' => 'noaccount@example.com'], '10.0.0.1'));
        $knownData     = json_decode((string) $knownResponse->getBody(), true);

        self::assertSame(200, $knownResponse->getStatusCode());
        self::assertSame(['ok' => true], $knownData);
        self::assertCount(0, $mailer->sent);
    }

    public function test_request_for_a_magic_link_only_account_without_a_password_still_sends_a_mail(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        // Account exists but was never given a password (magic-link only) —
        // the reset flow doubles as "set your first password" for it, so a
        // mail still goes out (any existing account is eligible, not just
        // one that already has a password).
        $this->insertUser('magiconly@example.com');

        $response = $app->handle($this->post('/password/reset/request', ['email' => 'magiconly@example.com']));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], $data);
        self::assertCount(1, $mailer->sent);
    }

    public function test_request_responses_are_identical_in_shape_for_existing_and_nonexisting_accounts(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $this->userWithPassword('exists@example.com', 'old-password-1');

        $existingResponse = $app->handle($this->post('/password/reset/request', ['email' => 'exists@example.com'], '10.0.0.2'));
        $unknownResponse  = $app->handle($this->post('/password/reset/request', ['email' => 'ghost@example.com'], '10.0.0.3'));

        self::assertSame($existingResponse->getStatusCode(), $unknownResponse->getStatusCode());
        self::assertSame(
            (string) $existingResponse->getBody(),
            (string) $unknownResponse->getBody(),
            'the response body must not leak whether the account exists',
        );
    }

    public function test_request_with_malformed_email_returns_ok_and_sends_no_mail(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $response = $app->handle($this->post('/password/reset/request', ['email' => 'not-an-email']));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $mailer->sent);
    }

    // ── POST /password/reset/request — rate limiting ─────────────────────

    public function test_request_is_rate_limited_per_email(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $this->userWithPassword('ratelimited@example.com', 'old-password-1');

        $first  = $app->handle($this->post('/password/reset/request', ['email' => 'ratelimited@example.com'], '10.0.1.1'));
        $second = $app->handle($this->post('/password/reset/request', ['email' => 'ratelimited@example.com'], '10.0.1.2'));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
        self::assertCount(1, $mailer->sent);
    }

    public function test_request_is_rate_limited_per_ip(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $ip     = '10.0.2.1';
        $this->userWithPassword('ipa@example.com', 'old-password-1');
        $this->userWithPassword('ipb@example.com', 'old-password-1');
        $this->userWithPassword('ipc@example.com', 'old-password-1');

        $first  = $app->handle($this->post('/password/reset/request', ['email' => 'ipa@example.com'], $ip));
        $second = $app->handle($this->post('/password/reset/request', ['email' => 'ipb@example.com'], $ip));
        $third  = $app->handle($this->post('/password/reset/request', ['email' => 'ipc@example.com'], $ip));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame(429, $third->getStatusCode());
        self::assertCount(2, $mailer->sent);
    }

    // ── POST /password/reset/confirm — happy path ────────────────────────

    public function test_confirm_with_a_valid_token_sets_the_new_password_and_invalidates_the_token(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $userId = $this->userWithPassword('confirmme@example.com', 'old-password-1');

        $app->handle($this->post('/password/reset/request', ['email' => 'confirmme@example.com']));
        $token = $this->extractToken($mailer->sent[0]['body']);

        $response = $app->handle($this->post('/password/reset/confirm', [
            'token'                     => $token,
            'new_password'              => 'brand-new-password',
            'new_password_confirmation' => 'brand-new-password',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $hash = $this->conn->fetchOne('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
        self::assertTrue(password_verify('brand-new-password', (string) $hash));

        $usedAt = $this->conn->fetchOne(
            "SELECT used_at FROM login_tokens WHERE purpose = 'password_reset' AND user_id = :uid",
            ['uid' => $userId],
        );
        self::assertNotNull($usedAt);
    }

    public function test_confirm_bumps_token_version_to_invalidate_every_existing_session(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $userId = $this->userWithPassword('logoutall@example.com', 'old-password-1');

        $versionBefore = (int) $this->conn->fetchOne('SELECT token_version FROM users WHERE id = :id', ['id' => $userId]);

        $app->handle($this->post('/password/reset/request', ['email' => 'logoutall@example.com']));
        $token = $this->extractToken($mailer->sent[0]['body']);

        $app->handle($this->post('/password/reset/confirm', [
            'token'                     => $token,
            'new_password'              => 'brand-new-password',
            'new_password_confirmation' => 'brand-new-password',
        ]));

        $versionAfter = (int) $this->conn->fetchOne('SELECT token_version FROM users WHERE id = :id', ['id' => $userId]);
        self::assertGreaterThan($versionBefore, $versionAfter);
    }

    public function test_confirm_cannot_reuse_the_same_token_twice(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $this->userWithPassword('reuse@example.com', 'old-password-1');

        $app->handle($this->post('/password/reset/request', ['email' => 'reuse@example.com']));
        $token = $this->extractToken($mailer->sent[0]['body']);

        $body = [
            'token'                     => $token,
            'new_password'              => 'brand-new-password',
            'new_password_confirmation' => 'brand-new-password',
        ];
        $first  = $app->handle($this->post('/password/reset/confirm', $body));
        $second = $app->handle($this->post('/password/reset/confirm', $body));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(400, $second->getStatusCode());
        $data = json_decode((string) $second->getBody(), true);
        self::assertSame('invalid_token', $data['error']['key']);
    }

    // ── POST /password/reset/confirm — invalid token shapes ──────────────

    public function test_confirm_with_a_malformed_token_returns_invalid_token(): void
    {
        $app = $this->createApp();

        $response = $app->handle($this->post('/password/reset/confirm', [
            'token'                     => 'not-a-real-token',
            'new_password'              => 'brand-new-password',
            'new_password_confirmation' => 'brand-new-password',
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('invalid_token', $data['error']['key']);
    }

    public function test_confirm_with_an_expired_token_returns_invalid_token(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $userId = $this->userWithPassword('expired@example.com', 'old-password-1');

        $app->handle($this->post('/password/reset/request', ['email' => 'expired@example.com']));
        $token = $this->extractToken($mailer->sent[0]['body']);

        // Force the just-issued token into the past.
        $this->conn->executeStatement(
            "UPDATE login_tokens SET expires_at = datetime('now', '-1 hour') WHERE purpose = 'password_reset' AND user_id = :uid",
            ['uid' => $userId],
        );

        $response = $app->handle($this->post('/password/reset/confirm', [
            'token'                     => $token,
            'new_password'              => 'brand-new-password',
            'new_password_confirmation' => 'brand-new-password',
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('invalid_token', $data['error']['key']);
    }

    public function test_confirm_rejects_a_pending_2fa_token_from_a_different_purpose(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $email  = 'wrongpurpose@example.com';
        $this->userWithPassword($email, 'old-password-1');

        // A magic-link request issues a purpose='login' token, not 'password_reset'.
        $app->handle($this->post('/login', ['email' => $email]));
        $loginLinkBody = $mailer->sent[0]['body'];
        preg_match('#token=([0-9a-f]{64})#', $loginLinkBody, $m);
        self::assertArrayHasKey(1, $m, 'login link with token expected in mail body');
        $loginToken = $m[1];

        $response = $app->handle($this->post('/password/reset/confirm', [
            'token'                     => $loginToken,
            'new_password'              => 'brand-new-password',
            'new_password_confirmation' => 'brand-new-password',
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('invalid_token', $data['error']['key']);
    }

    // ── POST /password/reset/confirm — password validation ───────────────

    public function test_confirm_rejects_a_mismatched_confirmation(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $userId = $this->userWithPassword('mismatch@example.com', 'old-password-1');

        $app->handle($this->post('/password/reset/request', ['email' => 'mismatch@example.com']));
        $token = $this->extractToken($mailer->sent[0]['body']);

        $response = $app->handle($this->post('/password/reset/confirm', [
            'token'                     => $token,
            'new_password'              => 'brand-new-password',
            'new_password_confirmation' => 'something-else',
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('password_mismatch', $data['error']['key']);

        $hash = $this->conn->fetchOne('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
        self::assertTrue(password_verify('old-password-1', (string) $hash));
    }

    public function test_confirm_rejects_a_password_below_the_minimum_length(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $this->userWithPassword('tooshort@example.com', 'old-password-1');

        $app->handle($this->post('/password/reset/request', ['email' => 'tooshort@example.com']));
        $token = $this->extractToken($mailer->sent[0]['body']);

        $response = $app->handle($this->post('/password/reset/confirm', [
            'token'                     => $token,
            'new_password'              => 'short',
            'new_password_confirmation' => 'short',
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('weak_password', $data['error']['key']);
    }
}
