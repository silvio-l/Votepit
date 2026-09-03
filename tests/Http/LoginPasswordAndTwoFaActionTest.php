<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\CsrfService;
use Votepit\Security\EncryptionService;
use Votepit\Security\Totp;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the login-side security property that a
 * compromised mailbox (magic link) or a leaked password ALONE must not be
 * enough to obtain a session once TOTP is enabled — POST /login/password
 * and GET /login/verify both hand back a pending-2FA token instead, and only
 * POST /login/2fa (with a correct TOTP or backup code) actually issues a
 * session cookie.
 */
final class LoginPasswordAndTwoFaActionTest extends IntegrationTestCase
{
    /**
     * Overrides the default (unset → limit=0) rate limits with small,
     * deterministic values for login:password/login:2fa — every other test
     * in this class makes at most 2-3 requests from the same default IP
     * (127.0.0.1), well under this threshold; only
     * test_password_login_is_rate_limited_per_ip deliberately exceeds it
     * (from a distinct IP, so it doesn't interfere with the others).
     */
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
            'rate_limits'         => [
                'login:password' => ['limit' => 10, 'window' => 900],
                'login:2fa'      => ['limit' => 10, 'window' => 900],
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

    /** Seeds a user with a password set, no TOTP. */
    private function userWithPassword(string $email, string $password): int
    {
        return $this->insertUser($email, ['password_hash' => password_hash($password, PASSWORD_ARGON2ID)]);
    }

    /**
     * Seeds a fully-enrolled user (password + confirmed TOTP).
     *
     * @return array{0: int, 1: string} [userId, secretBase32]
     */
    private function userWithPasswordAndTotp(string $email, string $password): array
    {
        $secret = (new Totp())->generateSecret();
        $userId = $this->insertUser($email, [
            'password_hash'         => password_hash($password, PASSWORD_ARGON2ID),
            'totp_secret_encrypted' => (new EncryptionService(str_repeat('a', 64), 'totp'))->encrypt($secret),
            'totp_enabled_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return [$userId, $secret];
    }

    private function currentTotpCode(string $secretBase32): string
    {
        $reflection = new \ReflectionClass(Totp::class);
        $hotp       = $reflection->getMethod('hotp');
        $decode     = $reflection->getMethod('base32Decode');
        $binary     = $decode->invoke(new Totp(), $secretBase32);
        return $hotp->invoke(new Totp(), $binary, intdiv(time(), 30));
    }

    // ── POST /login/password ────────────────────────────────────────────

    public function test_correct_password_without_totp_issues_a_session_immediately(): void
    {
        $this->userWithPassword('plain-login@example.com', 'correct-password-1');

        $response = $this->createApp()->handle($this->post('/login/password', [
            'email'    => 'plain-login@example.com',
            'password' => 'correct-password-1',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok']);
        self::assertStringContainsString('votepit_sess=', implode(';', $response->getHeader('Set-Cookie')));
    }

    public function test_correct_password_with_totp_returns_pending_2fa_without_a_session(): void
    {
        [$userId] = $this->userWithPasswordAndTotp('pw2fa@example.com', 'correct-password-1');

        $response = $this->createApp()->handle($this->post('/login/password', [
            'email'    => 'pw2fa@example.com',
            'password' => 'correct-password-1',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['requires_2fa']);
        self::assertIsString($data['pending_token']);
        self::assertStringNotContainsString('votepit_sess=', implode(';', $response->getHeader('Set-Cookie')));

        // Pending token is stored only as a hash, purpose = 2fa_pending.
        $purpose = $this->conn->fetchOne(
            "SELECT purpose FROM login_tokens WHERE user_id = :uid ORDER BY id DESC LIMIT 1",
            ['uid' => $userId],
        );
        self::assertSame('2fa_pending', $purpose);
    }

    public function test_wrong_password_returns_generic_error(): void
    {
        $this->userWithPassword('wrongpw@example.com', 'correct-password-1');

        $response = $this->createApp()->handle($this->post('/login/password', [
            'email'    => 'wrongpw@example.com',
            'password' => 'totally-wrong',
        ]));

        self::assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('invalid_credentials', $data['error']['key']);
    }

    public function test_unknown_email_returns_the_same_generic_error_as_wrong_password(): void
    {
        $response = $this->createApp()->handle($this->post('/login/password', [
            'email'    => 'no-such-user@example.com',
            'password' => 'whatever-12345',
        ]));

        self::assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('invalid_credentials', $data['error']['key']);
    }

    public function test_user_without_a_password_set_fails_generically_not_a_500(): void
    {
        $this->insertUser('nopassword@example.com');

        $response = $this->createApp()->handle($this->post('/login/password', [
            'email'    => 'nopassword@example.com',
            'password' => 'anything-at-all',
        ]));

        self::assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('invalid_credentials', $data['error']['key']);
    }

    public function test_blocked_user_with_correct_password_is_rejected(): void
    {
        $this->insertUser('blockedpw@example.com', [
            'password_hash' => password_hash('correct-password-1', PASSWORD_ARGON2ID),
            'is_blocked'    => 1,
        ]);

        $response = $this->createApp()->handle($this->post('/login/password', [
            'email'    => 'blockedpw@example.com',
            'password' => 'correct-password-1',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_password_login_is_rate_limited_per_ip(): void
    {
        $this->userWithPassword('ratelimited@example.com', 'correct-password-1');
        $app = $this->createApp();

        // Config default: 10/900s (see testConfig() override above).
        $statusCodes = [];
        for ($i = 0; $i < 11; $i++) {
            $request       = $this->post('/login/password', [
                'email'    => 'ratelimited@example.com',
                'password' => 'wrong-password',
            ], '203.0.113.9');
            $statusCodes[] = $app->handle($request)->getStatusCode();
        }

        self::assertContains(429, $statusCodes);
    }

    // ── GET /login/verify with TOTP enabled ─────────────────────────────

    public function test_magic_link_verify_with_totp_enabled_returns_pending_2fa_not_a_session(): void
    {
        $secret = (new Totp())->generateSecret();
        $userId = $this->insertUser('ml2fa@example.com', [
            'totp_secret_encrypted' => (new EncryptionService(str_repeat('a', 64), 'totp'))->encrypt($secret),
            'totp_enabled_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $plainToken = bin2hex(random_bytes(32));
        $this->conn->insert('login_tokens', [
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $plainToken),
            'purpose'    => 'login',
            'expires_at' => (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s'),
            'used_at'    => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/login/verify?token=' . $plainToken);
        $response = $this->createApp()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['requires_2fa']);
        self::assertIsString($data['pending_token']);
        self::assertStringNotContainsString('votepit_sess=', implode(';', $response->getHeader('Set-Cookie')));
    }

    // ── POST /login/2fa ──────────────────────────────────────────────────

    public function test_correct_totp_code_completes_login_and_issues_a_session(): void
    {
        [$userId, $secret] = $this->userWithPasswordAndTotp('twofa-ok@example.com', 'correct-password-1');
        $app = $this->createApp();

        $pendingResponse = $app->handle($this->post('/login/password', ['email' => 'twofa-ok@example.com', 'password' => 'correct-password-1']));
        $pendingToken     = json_decode((string) $pendingResponse->getBody(), true)['pending_token'];

        $response = $app->handle($this->post('/login/2fa', [
            'pending_token' => $pendingToken,
            'code'          => $this->currentTotpCode($secret),
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok']);
        self::assertStringContainsString('votepit_sess=', implode(';', $response->getHeader('Set-Cookie')));
    }

    public function test_wrong_totp_code_is_rejected_and_pending_token_stays_usable(): void
    {
        [$userId, $secret] = $this->userWithPasswordAndTotp('twofa-wrong@example.com', 'correct-password-1');
        $app = $this->createApp();

        $pendingResponse = $app->handle($this->post('/login/password', ['email' => 'twofa-wrong@example.com', 'password' => 'correct-password-1']));
        $pendingToken     = json_decode((string) $pendingResponse->getBody(), true)['pending_token'];

        $badResponse = $app->handle($this->post('/login/2fa', ['pending_token' => $pendingToken, 'code' => '000000']));
        self::assertSame(400, $badResponse->getStatusCode());
        self::assertStringNotContainsString('votepit_sess=', implode(';', $badResponse->getHeader('Set-Cookie')));

        // Still usable with the correct code afterwards.
        $goodResponse = $app->handle($this->post('/login/2fa', ['pending_token' => $pendingToken, 'code' => $this->currentTotpCode($secret)]));
        self::assertSame(200, $goodResponse->getStatusCode());
    }

    public function test_pending_token_is_single_use(): void
    {
        [, $secret] = $this->userWithPasswordAndTotp('twofa-single@example.com', 'correct-password-1');
        $app = $this->createApp();

        $pendingResponse = $app->handle($this->post('/login/password', ['email' => 'twofa-single@example.com', 'password' => 'correct-password-1']));
        $pendingToken     = json_decode((string) $pendingResponse->getBody(), true)['pending_token'];

        $first  = $app->handle($this->post('/login/2fa', ['pending_token' => $pendingToken, 'code' => $this->currentTotpCode($secret)]));
        self::assertSame(200, $first->getStatusCode());

        $second = $app->handle($this->post('/login/2fa', ['pending_token' => $pendingToken, 'code' => $this->currentTotpCode($secret)]));
        self::assertSame(400, $second->getStatusCode());
    }

    public function test_backup_code_completes_login_and_is_single_use(): void
    {
        [$userId] = $this->userWithPasswordAndTotp('twofa-backup@example.com', 'correct-password-1');
        $this->conn->insert('totp_backup_codes', [
            'user_id'    => $userId,
            'code_hash'  => hash('sha256', 'BACKUP-CODE1'),
            'used_at'    => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $app = $this->createApp();
        $pendingResponse = $app->handle($this->post('/login/password', ['email' => 'twofa-backup@example.com', 'password' => 'correct-password-1']));
        $pendingToken     = json_decode((string) $pendingResponse->getBody(), true)['pending_token'];

        $response = $app->handle($this->post('/login/2fa', ['pending_token' => $pendingToken, 'backup_code' => 'BACKUP-CODE1']));
        self::assertSame(200, $response->getStatusCode());

        $usedAt = $this->conn->fetchOne('SELECT used_at FROM totp_backup_codes WHERE code_hash = :h', ['h' => hash('sha256', 'BACKUP-CODE1')]);
        self::assertNotNull($usedAt);

        // Re-using the same backup code fails, even with a fresh pending token.
        $pendingResponse2 = $app->handle($this->post('/login/password', ['email' => 'twofa-backup@example.com', 'password' => 'correct-password-1']));
        $pendingToken2     = json_decode((string) $pendingResponse2->getBody(), true)['pending_token'];

        $reuse = $app->handle($this->post('/login/2fa', ['pending_token' => $pendingToken2, 'backup_code' => 'BACKUP-CODE1']));
        self::assertSame(400, $reuse->getStatusCode());
    }

    public function test_invalid_pending_token_is_rejected(): void
    {
        $response = $this->createApp()->handle($this->post('/login/2fa', [
            'pending_token' => 'not-a-real-token',
            'code'          => '123456',
        ]));

        self::assertSame(400, $response->getStatusCode());
    }
}
