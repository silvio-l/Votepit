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
                'login:password'       => ['limit' => 10, 'window' => 900],
                // Deliberately higher than login:password's per-IP limit so
                // the existing per-IP tests below (which reuse one email
                // across up to 19 sequential same-IP requests) keep tripping
                // the IP bucket first, exactly as before — the email bucket
                // is exercised by its own dedicated cross-IP test.
                'login:password:email' => ['limit' => 15, 'window' => 900],
                'login:2fa'            => ['limit' => 10, 'window' => 900],
                'login:2fa:token'      => ['limit' => 8,  'window' => 900],
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

    /**
     * A successful login only refunds its own request from the per-IP
     * budget — it must never wipe the failures that came before it.
     * Otherwise anyone holding one valid account could log into it after
     * every few guesses against OTHER accounts and spray passwords from a
     * single IP without ever reaching the limit (Sentinel finding
     * 2026-09-14, "rate limit bypass via global IP bucket reset").
     */
    public function test_a_successful_password_login_does_not_erase_earlier_failures_from_the_ip_budget(): void
    {
        $this->userWithPassword('attacker@example.com', 'attacker-password-1');
        $app = $this->createApp();
        $ip  = '203.0.113.10';

        // Config default: 10/900s. Nine guesses against nine different
        // victims (so the per-email buckets never trip) ...
        for ($i = 0; $i < 9; $i++) {
            $response = $app->handle($this->post('/login/password', [
                'email'    => "victim{$i}@example.com",
                'password' => 'Summer2026!',
            ], $ip));
            self::assertSame(401, $response->getStatusCode());
        }

        // ... then the attacker's own valid login from the same IP ...
        $own = $app->handle($this->post('/login/password', [
            'email'    => 'attacker@example.com',
            'password' => 'attacker-password-1',
        ], $ip));
        self::assertSame(200, $own->getStatusCode());

        // ... leaves the nine failures on the books: the 10th failure is
        // still allowed, the 11th is throttled.
        $statusCodes = [];
        for ($i = 9; $i < 11; $i++) {
            $statusCodes[] = $app->handle($this->post('/login/password', [
                'email'    => "victim{$i}@example.com",
                'password' => 'Summer2026!',
            ], $ip))->getStatusCode();
        }

        self::assertSame([401, 429], $statusCodes);
    }

    /**
     * The flip side: successful logins themselves never use up the per-IP
     * budget, so many people behind one shared address (office, school,
     * club house NAT) — or one person on several devices — are never
     * throttled for logging in correctly, and a typo followed by the right
     * password still works.
     */
    public function test_successful_password_logins_do_not_consume_the_ip_budget(): void
    {
        $app = $this->createApp();
        $ip  = '203.0.113.11';

        // Config default: 10/900s — 15 people log in from the same IP,
        // each after one typo would be 30 requests; keep it to the
        // successes plus a single typo to stay below the failure budget.
        $this->userWithPassword('typo@example.com', 'correct-password-1');
        self::assertSame(401, $app->handle($this->post('/login/password', [
            'email'    => 'typo@example.com',
            'password' => 'wrong-password',
        ], $ip))->getStatusCode());

        $statusCodes = [];
        for ($i = 0; $i < 15; $i++) {
            $this->userWithPassword("colleague{$i}@example.com", 'correct-password-1');
            $statusCodes[] = $app->handle($this->post('/login/password', [
                'email'    => "colleague{$i}@example.com",
                'password' => 'correct-password-1',
            ], $ip))->getStatusCode();
        }
        $statusCodes[] = $app->handle($this->post('/login/password', [
            'email'    => 'typo@example.com',
            'password' => 'correct-password-1',
        ], $ip))->getStatusCode();

        self::assertSame(array_fill(0, 16, 200), $statusCodes);
    }

    /**
     * The second rate-limit dimension (issue 05): rotating the source IP on
     * every request must NOT reset the per-account budget — the email-HMAC
     * bucket still catches the attacker.
     */
    public function test_password_login_email_bucket_stops_a_cross_ip_brute_force(): void
    {
        $this->userWithPassword('crossip@example.com', 'correct-password-1');
        $app = $this->createApp();

        // Config default: login:password:email = 15/900s (see testConfig()).
        $statusCodes = [];
        for ($i = 0; $i < 16; $i++) {
            $request       = $this->post('/login/password', [
                'email'    => 'crossip@example.com',
                'password' => 'wrong-password',
            ], "203.0.114.{$i}"); // a fresh IP on every single request
            $statusCodes[] = $app->handle($request)->getStatusCode();
        }

        self::assertContains(429, $statusCodes);
    }

    public function test_password_login_email_bucket_does_not_throttle_a_different_account(): void
    {
        $this->userWithPassword('victim@example.com', 'correct-password-1');
        $this->userWithPassword('bystander@example.com', 'correct-password-2');
        $app = $this->createApp();

        for ($i = 0; $i < 16; $i++) {
            $app->handle($this->post('/login/password', [
                'email'    => 'victim@example.com',
                'password' => 'wrong-password',
            ], "203.0.115.{$i}"));
        }

        $response = $app->handle($this->post('/login/password', [
            'email'    => 'bystander@example.com',
            'password' => 'correct-password-2',
        ], '203.0.115.200'));

        self::assertSame(200, $response->getStatusCode());
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

    /**
     * A TOTP code, once accepted, must be rejected on reuse even via a
     * brand-new pending token — the ±1-step verification window would
     * otherwise let an attacker who intercepts a code in transit replay it
     * for up to ~90s after the legitimate login (CWE-294, OWASP/BSI audit
     * issue 03).
     */
    public function test_totp_code_is_rejected_on_replay_via_a_fresh_pending_token(): void
    {
        [, $secret] = $this->userWithPasswordAndTotp('twofa-replay@example.com', 'correct-password-1');
        $app  = $this->createApp();
        $code = $this->currentTotpCode($secret);

        $pendingResponse1 = $app->handle($this->post('/login/password', ['email' => 'twofa-replay@example.com', 'password' => 'correct-password-1']));
        $pendingToken1     = json_decode((string) $pendingResponse1->getBody(), true)['pending_token'];

        $first = $app->handle($this->post('/login/2fa', ['pending_token' => $pendingToken1, 'code' => $code]));
        self::assertSame(200, $first->getStatusCode());

        $pendingResponse2 = $app->handle($this->post('/login/password', ['email' => 'twofa-replay@example.com', 'password' => 'correct-password-1']));
        $pendingToken2     = json_decode((string) $pendingResponse2->getBody(), true)['pending_token'];

        $replay = $app->handle($this->post('/login/2fa', ['pending_token' => $pendingToken2, 'code' => $code]));
        self::assertSame(400, $replay->getStatusCode());
        self::assertStringNotContainsString('votepit_sess=', implode(';', $replay->getHeader('Set-Cookie')));
    }

    /**
     * The second rate-limit dimension for POST /login/2fa (issue 05):
     * rotating the source IP on every guess must not reset the
     * per-pending-token budget — the 6-digit TOTP space is small enough that
     * IP rotation alone must not be a way around the limit.
     */
    public function test_totp_token_bucket_stops_a_cross_ip_brute_force(): void
    {
        $this->userWithPasswordAndTotp('tokenbucket@example.com', 'correct-password-1');
        $app = $this->createApp();

        $pendingResponse = $app->handle($this->post('/login/password', ['email' => 'tokenbucket@example.com', 'password' => 'correct-password-1']));
        $pendingToken     = json_decode((string) $pendingResponse->getBody(), true)['pending_token'];

        // Config default: login:2fa:token = 8/900s (see testConfig()).
        $statusCodes = [];
        for ($i = 0; $i < 9; $i++) {
            $request       = $this->post('/login/2fa', ['pending_token' => $pendingToken, 'code' => '000000'], "203.0.116.{$i}");
            $statusCodes[] = $app->handle($request)->getStatusCode();
        }

        self::assertContains(429, $statusCodes);
    }

    /**
     * Same rule for POST /login/2fa: a correct code refunds only its own
     * request from the per-IP budget, never the wrong codes sent before it
     * from that IP (Sentinel finding 2026-09-14).
     */
    public function test_a_successful_2fa_login_does_not_erase_earlier_failures_from_the_ip_budget(): void
    {
        [, $ownSecret] = $this->userWithPasswordAndTotp('attacker2fa@example.com', 'attacker-password-1');
        $this->userWithPasswordAndTotp('victim2fa-a@example.com', 'victim-password-1');
        $this->userWithPasswordAndTotp('victim2fa-b@example.com', 'victim-password-1');
        $app = $this->createApp();
        $ip  = '203.0.117.1';

        $pending = function (string $email, string $password) use ($app): string {
            $response = $app->handle($this->post('/login/password', ['email' => $email, 'password' => $password], '198.51.100.1'));
            return json_decode((string) $response->getBody(), true)['pending_token'];
        };
        $victimA = $pending('victim2fa-a@example.com', 'victim-password-1');
        $victimB = $pending('victim2fa-b@example.com', 'victim-password-1');
        $own     = $pending('attacker2fa@example.com', 'attacker-password-1');

        // Config default: login:2fa 10/900s, login:2fa:token 8/900s — split
        // nine wrong codes over two pending tokens so no token bucket trips.
        for ($i = 0; $i < 9; $i++) {
            $token    = $i < 5 ? $victimA : $victimB;
            $response = $app->handle($this->post('/login/2fa', ['pending_token' => $token, 'code' => '000000'], $ip));
            self::assertNotSame(429, $response->getStatusCode());
        }

        $success = $app->handle($this->post('/login/2fa', ['pending_token' => $own, 'code' => $this->currentTotpCode($ownSecret)], $ip));
        self::assertSame(200, $success->getStatusCode());

        $statusCodes = [];
        for ($i = 0; $i < 2; $i++) {
            $statusCodes[] = $app->handle($this->post('/login/2fa', ['pending_token' => $victimB, 'code' => '000000'], $ip))->getStatusCode();
        }

        self::assertNotSame(429, $statusCodes[0]);
        self::assertSame(429, $statusCodes[1]);
    }

    public function test_successful_2fa_logins_do_not_consume_the_ip_budget(): void
    {
        $app = $this->createApp();
        $ip  = '203.0.117.2';

        // Config default: login:2fa 10/900s — 12 people complete 2FA from
        // the same IP (their password step comes from elsewhere).
        $statusCodes = [];
        for ($i = 0; $i < 12; $i++) {
            [, $secret] = $this->userWithPasswordAndTotp("team2fa{$i}@example.com", 'correct-password-1');
            $pendingResponse = $app->handle($this->post('/login/password', ['email' => "team2fa{$i}@example.com", 'password' => 'correct-password-1'], "198.51.100.{$i}"));
            $pendingToken    = json_decode((string) $pendingResponse->getBody(), true)['pending_token'];
            $statusCodes[]   = $app->handle($this->post('/login/2fa', ['pending_token' => $pendingToken, 'code' => $this->currentTotpCode($secret)], $ip))->getStatusCode();
        }

        self::assertSame(array_fill(0, 12, 200), $statusCodes);
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

    /**
     * OWASP/BSI audit remediation issue 11: password_login.* audit entries
     * carry a pseudonymized ip_hmac field — never the plaintext client IP.
     */
    public function test_password_login_audit_entry_carries_a_pseudonymized_ip_never_plaintext(): void
    {
        $this->userWithPassword('ip-audit@example.com', 'correct-password-1');

        $this->createApp()->handle($this->post('/login/password', [
            'email'    => 'ip-audit@example.com',
            'password' => 'correct-password-1',
        ], '203.0.113.42'));

        $log = (string) file_get_contents($this->logFile);
        self::assertStringNotContainsString('203.0.113.42', $log);

        $lines = array_values(array_filter(explode("\n", $log), fn (string $l): bool => $l !== ''));
        $entry = null;
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['action'] ?? null) === 'password_login.succeeded') {
                $entry = $decoded;
            }
        }

        self::assertIsArray($entry);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry['context']['ip_hmac']);
    }
}
