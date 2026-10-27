<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\EncryptionService;
use Votepit\Security\Password\InMemoryHibpHttpClient;
use Votepit\Security\Totp;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the Profile-settings side (password +
 * TOTP 2FA): POST /account/password and POST /account/totp/{setup,confirm,
 * disable,backup-codes/regenerate}. AuthZ: user — any logged-in user, no
 * special role required (CLAUDE.md scope note).
 *
 * The login-side gate (magic-link/password login behind an active TOTP) is
 * covered separately in LoginPasswordAndTwoFaActionTest.
 */
final class PasswordAndTotpActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, ?int $userId, array $body = []): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    // ── GET /api/bootstrap: has_password / totp_enabled flags ──────────

    public function test_bootstrap_reports_has_password_and_totp_enabled_flags(): void
    {
        $userId = $this->enrolledUser('bootstrapflags@example.com', 'current-pw-123');
        $csrf   = $this->csrf();
        $signed = $csrf->sign($csrf->generate());

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/bootstrap')
            ->withCookieParams([
                $csrf->cookieName() => $signed,
                'votepit_sess'      => $this->sessionCookie($userId),
            ]);

        $response = $this->createApp()->handle($request);
        $data     = json_decode((string) $response->getBody(), true);

        self::assertTrue($data['user']['has_password']);
        self::assertTrue($data['user']['totp_enabled']);
    }

    public function test_bootstrap_reports_false_flags_for_a_fresh_user(): void
    {
        $userId = $this->insertUser('bootstrapflagsfresh@example.com');
        $csrf   = $this->csrf();
        $signed = $csrf->sign($csrf->generate());

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/bootstrap')
            ->withCookieParams([
                $csrf->cookieName() => $signed,
                'votepit_sess'      => $this->sessionCookie($userId),
            ]);

        $response = $this->createApp()->handle($request);
        $data     = json_decode((string) $response->getBody(), true);

        self::assertFalse($data['user']['has_password']);
        self::assertFalse($data['user']['totp_enabled']);
    }

    // ── POST /account/password ──────────────────────────────────────────

    public function test_password_set_requires_login(): void
    {
        $response = $this->createApp()->handle($this->post('/account/password', null, ['new_password' => 'a-strong-password']));
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_first_time_password_set_succeeds_without_current_password(): void
    {
        $userId = $this->insertUser('pwset@example.com');

        $response = $this->createApp()->handle(
            $this->post('/account/password', $userId, [
                'new_password'              => 'a-strong-password',
                'new_password_confirmation' => 'a-strong-password',
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        $hash = $this->conn->fetchOne('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
        self::assertIsString($hash);
        self::assertTrue(password_verify('a-strong-password', $hash));
    }

    public function test_first_time_password_set_is_rejected_when_confirmation_does_not_match(): void
    {
        $userId = $this->insertUser('pwsetmismatch@example.com');

        $response = $this->createApp()->handle(
            $this->post('/account/password', $userId, [
                'new_password'              => 'a-strong-password',
                'new_password_confirmation' => 'a-different-password',
            ]),
        );

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('password_mismatch', $data['error']['key']);

        $hash = $this->conn->fetchOne('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNull($hash);
    }

    public function test_password_below_minimum_length_is_rejected(): void
    {
        $userId   = $this->insertUser('pwshort@example.com');
        $response = $this->createApp()->handle($this->post('/account/password', $userId, [
            'new_password'              => 'short',
            'new_password_confirmation' => 'short',
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('weak_password', $data['error']['key']);
    }

    public function test_changing_an_existing_password_requires_the_current_one(): void
    {
        $userId = $this->insertUser('pwchange@example.com', ['password_hash' => password_hash('old-password-1', PASSWORD_ARGON2ID)]);

        $response = $this->createApp()->handle(
            $this->post('/account/password', $userId, ['new_password' => 'new-password-1']),
        );

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('invalid_current_password', $data['error']['key']);

        $hash = $this->conn->fetchOne('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
        self::assertTrue(password_verify('old-password-1', (string) $hash));
    }

    public function test_changing_an_existing_password_with_the_correct_current_one_succeeds(): void
    {
        $userId = $this->insertUser('pwchangeok@example.com', ['password_hash' => password_hash('old-password-1', PASSWORD_ARGON2ID)]);

        $response = $this->createApp()->handle($this->post('/account/password', $userId, [
            'current_password'         => 'old-password-1',
            'new_password'             => 'new-password-1',
            'new_password_confirmation' => 'new-password-1',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $hash = $this->conn->fetchOne('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
        self::assertTrue(password_verify('new-password-1', (string) $hash));
    }

    public function test_changing_an_existing_password_bumps_token_version_and_invalidates_other_sessions(): void
    {
        $userId = $this->insertUser('pwchangebump@example.com', ['password_hash' => password_hash('old-password-1', PASSWORD_ARGON2ID)]);
        $app    = $this->createApp();

        // A second, still-valid session for the same user (e.g. another
        // browser/device) — must be rejected after the change.
        $otherSessionCookie = $this->sessionCookie($userId, 0);

        $response = $app->handle($this->post('/account/password', $userId, [
            'current_password'          => 'old-password-1',
            'new_password'              => 'new-password-1',
            'new_password_confirmation' => 'new-password-1',
        ]));

        self::assertSame(200, $response->getStatusCode());

        $version = (int) $this->conn->fetchOne('SELECT token_version FROM users WHERE id = :id', ['id' => $userId]);
        self::assertSame(1, $version);

        // The OTHER session (still carrying v=0) must now be rejected.
        self::assertNull($this->bootstrapUser($app, $otherSessionCookie));

        // But the session that PERFORMED the change is reissued with the new
        // version and stays valid.
        $reissued = $this->cookieValue($response, 'votepit_sess');
        self::assertNotNull($reissued);
        self::assertNotSame('', $reissued);
        self::assertNotNull($this->bootstrapUser($app, $reissued));
    }

    public function test_first_time_password_set_does_not_bump_token_version(): void
    {
        $userId = $this->insertUser('pwsetnobump@example.com');

        $response = $this->createApp()->handle(
            $this->post('/account/password', $userId, [
                'new_password'              => 'a-strong-password',
                'new_password_confirmation' => 'a-strong-password',
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        $version = (int) $this->conn->fetchOne('SELECT token_version FROM users WHERE id = :id', ['id' => $userId]);
        self::assertSame(0, $version);
    }

    public function test_changing_an_existing_password_is_rejected_when_confirmation_does_not_match(): void
    {
        $userId = $this->insertUser('pwchangemismatch@example.com', ['password_hash' => password_hash('old-password-1', PASSWORD_ARGON2ID)]);

        $response = $this->createApp()->handle($this->post('/account/password', $userId, [
            'current_password'          => 'old-password-1',
            'new_password'              => 'new-password-1',
            'new_password_confirmation' => 'something-else',
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('password_mismatch', $data['error']['key']);

        $hash = $this->conn->fetchOne('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
        self::assertTrue(password_verify('old-password-1', (string) $hash));
    }

    // ── POST /account/password: PasswordPolicy checks (issue 04) ────────

    public function test_password_set_rejects_a_top3000_common_password(): void
    {
        $userId = $this->insertUser('pwcommon@example.com');

        $response = $this->createApp()->handle($this->post('/account/password', $userId, [
            'new_password'              => 'qwertyuiop', // top-3000 list, ≥ MIN_LENGTH
            'new_password_confirmation' => 'qwertyuiop',
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('password_too_common', $data['error']['key']);

        $hash = $this->conn->fetchOne('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNull($hash);
    }

    public function test_password_set_rejects_a_password_containing_the_product_name(): void
    {
        $userId = $this->insertUser('pwcontext@example.com');

        $response = $this->createApp()->handle($this->post('/account/password', $userId, [
            'new_password'              => 'i-love-Votepit-app',
            'new_password_confirmation' => 'i-love-Votepit-app',
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('password_contains_context', $data['error']['key']);
    }

    public function test_password_set_rejects_a_password_containing_the_users_own_username(): void
    {
        $userId = $this->insertUser('pwcontextuser@example.com', ['username' => 'unusualhandle']);

        $response = $this->createApp()->handle($this->post('/account/password', $userId, [
            'new_password'              => 'go-unusualhandle-go',
            'new_password_confirmation' => 'go-unusualhandle-go',
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('password_contains_context', $data['error']['key']);
    }

    public function test_password_set_rejects_a_password_found_in_the_hibp_range_response(): void
    {
        $userId = $this->insertUser('pwpwned@example.com');
        $password = 'a-quite-unusual-string-27';
        $sha1     = strtoupper(sha1($password));
        $prefix   = substr($sha1, 0, 5);
        $suffix   = substr($sha1, 5);

        $hibp = new InMemoryHibpHttpClient();
        $hibp->stub('https://api.pwnedpasswords.com/range/' . $prefix, "{$suffix}:3\r\nFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFAAAA:1");

        $response = $this->createApp(hibpHttpClient: $hibp)->handle($this->post('/account/password', $userId, [
            'new_password'              => $password,
            'new_password_confirmation' => $password,
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('password_pwned', $data['error']['key']);
    }

    /**
     * A HIBP transport failure (network error, timeout, non-2xx) must fail
     * OPEN — the audit issue is explicit that HIBP being unreachable must
     * never block someone from setting an otherwise-strong password.
     */
    public function test_password_set_succeeds_when_hibp_is_unreachable(): void
    {
        $userId   = $this->insertUser('pwhibpdown@example.com');
        $password = 'a-quite-unusual-string-28';
        $sha1     = strtoupper(sha1($password));
        $prefix   = substr($sha1, 0, 5);

        $hibp = new InMemoryHibpHttpClient();
        $hibp->stubFailure('https://api.pwnedpasswords.com/range/' . $prefix);

        $response = $this->createApp(hibpHttpClient: $hibp)->handle($this->post('/account/password', $userId, [
            'new_password'              => $password,
            'new_password_confirmation' => $password,
        ]));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_password_set_succeeds_with_a_strong_unremarkable_password(): void
    {
        $userId   = $this->insertUser('pwstrong@example.com');
        $password = 'correcthorsebatterystaple9';

        $response = $this->createApp()->handle($this->post('/account/password', $userId, [
            'new_password'              => $password,
            'new_password_confirmation' => $password,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $hash = $this->conn->fetchOne('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
        self::assertTrue(password_verify($password, (string) $hash));
    }

    // ── POST /account/totp/setup + confirm ──────────────────────────────

    public function test_totp_setup_returns_secret_and_provisioning_uri(): void
    {
        $userId   = $this->insertUser('totpsetup@example.com');
        $response = $this->createApp()->handle($this->post('/account/totp/setup', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $data['secret']);
        self::assertStringStartsWith('otpauth://totp/', $data['provisioning_uri']);
        self::assertIsString($data['setup_token']);

        // The unconfirmed secret must NOT be written to the DB.
        $stored = $this->conn->fetchOne('SELECT totp_secret_encrypted FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNull($stored);
    }

    public function test_totp_confirm_with_correct_code_enables_2fa_and_returns_10_backup_codes(): void
    {
        $userId = $this->insertUser('totpconfirm@example.com');
        $app    = $this->createApp();

        $setupResponse = $app->handle($this->post('/account/totp/setup', $userId));
        $setupData     = json_decode((string) $setupResponse->getBody(), true);

        $code = (new Totp())->verify($setupData['secret'], $this->currentTotpCode($setupData['secret'])) ? $this->currentTotpCode($setupData['secret']) : null;
        self::assertNotNull($code);

        $confirmResponse = $app->handle($this->post('/account/totp/confirm', $userId, [
            'setup_token' => $setupData['setup_token'],
            'code'        => $code,
        ]));

        self::assertSame(200, $confirmResponse->getStatusCode());
        $confirmData = json_decode((string) $confirmResponse->getBody(), true);
        self::assertCount(10, $confirmData['backup_codes']);

        $enabledAt = $this->conn->fetchOne('SELECT totp_enabled_at FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNotNull($enabledAt);
        $encrypted = $this->conn->fetchOne('SELECT totp_secret_encrypted FROM users WHERE id = :id', ['id' => $userId]);
        self::assertIsString($encrypted);
        self::assertSame($setupData['secret'], (new EncryptionService(str_repeat('a', 64), 'totp'))->decrypt($encrypted));

        $codeCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM totp_backup_codes WHERE user_id = :id', ['id' => $userId]);
        self::assertSame(10, $codeCount);
    }

    public function test_totp_confirm_with_wrong_code_fails_and_does_not_enable_2fa(): void
    {
        $userId = $this->insertUser('totpwrong@example.com');
        $app    = $this->createApp();

        $setupResponse = $app->handle($this->post('/account/totp/setup', $userId));
        $setupData     = json_decode((string) $setupResponse->getBody(), true);

        $confirmResponse = $app->handle($this->post('/account/totp/confirm', $userId, [
            'setup_token' => $setupData['setup_token'],
            'code'        => '000000',
        ]));

        self::assertSame(400, $confirmResponse->getStatusCode());
        $enabledAt = $this->conn->fetchOne('SELECT totp_enabled_at FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNull($enabledAt);
    }

    public function test_totp_confirm_bumps_token_version_and_invalidates_other_sessions(): void
    {
        $userId = $this->insertUser('totpconfirmbump@example.com');
        $app    = $this->createApp();
        $otherSessionCookie = $this->sessionCookie($userId, 0);

        $setupResponse = $app->handle($this->post('/account/totp/setup', $userId));
        $setupData     = json_decode((string) $setupResponse->getBody(), true);
        $code          = $this->currentTotpCode($setupData['secret']);

        $confirmResponse = $app->handle($this->post('/account/totp/confirm', $userId, [
            'setup_token' => $setupData['setup_token'],
            'code'        => $code,
        ]));

        self::assertSame(200, $confirmResponse->getStatusCode());
        $version = (int) $this->conn->fetchOne('SELECT token_version FROM users WHERE id = :id', ['id' => $userId]);
        self::assertSame(1, $version);
        self::assertNull($this->bootstrapUser($app, $otherSessionCookie));
    }

    public function test_totp_confirm_rejects_a_setup_token_from_a_different_user(): void
    {
        $userId       = $this->insertUser('totpvictim@example.com');
        $attackerId   = $this->insertUser('totpattacker@example.com');
        $app          = $this->createApp();

        $setupResponse = $app->handle($this->post('/account/totp/setup', $userId));
        $setupData     = json_decode((string) $setupResponse->getBody(), true);
        $code          = $this->currentTotpCode($setupData['secret']);

        $confirmResponse = $app->handle($this->post('/account/totp/confirm', $attackerId, [
            'setup_token' => $setupData['setup_token'],
            'code'        => $code,
        ]));

        self::assertSame(400, $confirmResponse->getStatusCode());
    }

    // ── POST /account/totp/disable + backup-codes/regenerate ───────────

    public function test_totp_disable_with_correct_password_turns_2fa_off(): void
    {
        $userId = $this->enrolledUser('totpdisable@example.com', 'current-pw-123');

        $response = $this->createApp()->handle($this->post('/account/totp/disable', $userId, [
            'current_password' => 'current-pw-123',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $enabledAt = $this->conn->fetchOne('SELECT totp_enabled_at FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNull($enabledAt);
        $codeCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM totp_backup_codes WHERE user_id = :id', ['id' => $userId]);
        self::assertSame(0, $codeCount);
    }

    public function test_totp_disable_bumps_token_version_and_invalidates_other_sessions(): void
    {
        $userId = $this->enrolledUser('totpdisablebump@example.com', 'current-pw-123');
        $app    = $this->createApp();
        $otherSessionCookie = $this->sessionCookie($userId, 0);

        $response = $app->handle($this->post('/account/totp/disable', $userId, [
            'current_password' => 'current-pw-123',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $version = (int) $this->conn->fetchOne('SELECT token_version FROM users WHERE id = :id', ['id' => $userId]);
        self::assertSame(1, $version);
        self::assertNull($this->bootstrapUser($app, $otherSessionCookie));
    }

    public function test_totp_disable_with_wrong_password_fails_and_leaves_2fa_enabled(): void
    {
        $userId = $this->enrolledUser('totpdisablewrong@example.com', 'current-pw-123');

        $response = $this->createApp()->handle($this->post('/account/totp/disable', $userId, [
            'current_password' => 'wrong-password',
        ]));

        self::assertSame(400, $response->getStatusCode());
        $enabledAt = $this->conn->fetchOne('SELECT totp_enabled_at FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNotNull($enabledAt);
    }

    public function test_backup_codes_regenerate_replaces_all_codes(): void
    {
        $userId = $this->enrolledUser('bkregen@example.com', 'current-pw-123');

        $oldHashes = $this->conn->fetchFirstColumn('SELECT code_hash FROM totp_backup_codes WHERE user_id = :id', ['id' => $userId]);

        $response = $this->createApp()->handle($this->post('/account/totp/backup-codes/regenerate', $userId, [
            'current_password' => 'current-pw-123',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(10, $data['backup_codes']);

        $newHashes = $this->conn->fetchFirstColumn('SELECT code_hash FROM totp_backup_codes WHERE user_id = :id', ['id' => $userId]);
        self::assertCount(10, $newHashes);
        self::assertEmpty(array_intersect($oldHashes, $newHashes));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * GET /api/bootstrap with the given session cookie; returns the `user` payload or null.
     *
     * @param App<null> $app
     * @return array<string, mixed>|null
     */
    private function bootstrapUser(App $app, string $sessionCookie): ?array
    {
        $csrf   = $this->csrf();
        $signed = $csrf->sign($csrf->generate());

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/bootstrap')
            ->withCookieParams([
                $csrf->cookieName() => $signed,
                'votepit_sess'      => $sessionCookie,
            ]);

        $response = $app->handle($request);
        $data     = json_decode((string) $response->getBody(), true);
        return $data['user'] ?? null;
    }

    /** Searches the Set-Cookie headers for a cookie value. */
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

    private function currentTotpCode(string $secretBase32): string
    {
        $reflection = new \ReflectionClass(Totp::class);
        $hotp       = $reflection->getMethod('hotp');
        $decode     = $reflection->getMethod('base32Decode');
        $binary     = $decode->invoke(new Totp(), $secretBase32);
        return $hotp->invoke(new Totp(), $binary, intdiv(time(), 30));
    }

    /** Seeds a user with TOTP fully enrolled (password + confirmed TOTP + backup codes). */
    private function enrolledUser(string $email, string $password): int
    {
        $secret = (new Totp())->generateSecret();
        $userId = $this->insertUser($email, [
            'password_hash'         => password_hash($password, PASSWORD_ARGON2ID),
            'totp_secret_encrypted' => (new EncryptionService(str_repeat('a', 64), 'totp'))->encrypt($secret),
            'totp_enabled_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->conn->insert('totp_backup_codes', [
                'user_id'    => $userId,
                'code_hash'  => hash('sha256', 'SEED-CODE-' . $i),
                'used_at'    => null,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }

        return $userId;
    }
}
