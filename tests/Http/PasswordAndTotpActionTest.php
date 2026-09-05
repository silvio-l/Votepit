<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\EncryptionService;
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
