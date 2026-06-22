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
use Votepit\Security\SessionService;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für GET /login/verify (Sprint 2 — Issue 03).
 *
 * Booten über AppFactory mit SQLite-In-Memory. Tokens werden direkt in die
 * Test-DB geseedet (nur der Hash; der Klartext bleibt im Test). Assertions
 * prüfen beobachtbares Verhalten: HTTP-Status/Redirect, Set-Cookie, DB-Zustand,
 * Audit-Log — keine privaten Methoden.
 */
final class VerifyActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Seedet einen User; liefert dessen ID. */
    private function seedUser(string $email, int $isAdmin = 0): int
    {
        $this->conn->insert('users', [
            'email'       => $email,
            'is_admin'    => $isAdmin,
            'is_blocked'  => 0,
            'verified_at' => null,
            'created_at'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->lastInsertId();
    }

    /** Seedet einen Login-Token (nur Hash); liefert die Token-ID. */
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
    private function verifyRequest(string $token, array $cookies = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/login/verify')
            ->withQueryParams(['token' => $token])
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
     * App mit optionaler Admin-Allowlist (sonst Default-testConfig).
     *
     * @return \Slim\App<null>
     */
    private function appWithAdmins(string ...$adminEmails): \Slim\App
    {
        $config = Config::fromArray([
            'env'            => 'dev',
            'app_url'        => 'http://localhost:8000',
            'app_key'        => self::APP_KEY,
            'db'             => ['name' => ':memory:'],
            'smtp'           => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl' => 900,
            'admin_emails'   => $adminEmails,
        ]);

        return AppFactory::create($config, $this->conn, new InMemoryMailer(), new AuditLogger($this->logFile));
    }

    // -------------------------------------------------------------------------
    // AC1: gültiger Link → eingeloggt + signiertes Session-Cookie + Redirect
    // -------------------------------------------------------------------------

    public function test_valid_token_logs_in_and_redirects_with_signed_session(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('user@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $response = $this->createApp()->handle($this->verifyRequest($plain));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));

        $sessCookie = $this->cookieValue($response, 'votepit_sess');
        self::assertNotNull($sessCookie);

        $payload = $this->sessions()->verify($sessCookie);
        self::assertIsArray($payload);
        self::assertSame($userId, $payload['uid']);
        self::assertSame(0, $payload['v']);

        // used_at + verified_at gesetzt
        $row = $this->conn->fetchAssociative('SELECT used_at FROM login_tokens WHERE user_id = :id', ['id' => $userId]);
        self::assertIsArray($row);
        self::assertNotNull($row['used_at']);

        $verifiedAt = $this->conn->fetchOne('SELECT verified_at FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNotNull($verifiedAt);
    }

    // -------------------------------------------------------------------------
    // AC1 (Persistenz): AuthN hydratisiert die Session über einen Folge-Request
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

        // Folge-Request mit dem Session-Cookie wird ohne Fehler bedient (AuthN
        // lädt den User via findById; eine kaputte/fehlende Hydratation würde
        // hier einen 500 erzeugen).
        $followup = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withCookieParams(['votepit_sess' => $sessCookie]);
        $response = $app->handle($followup);

        self::assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC2: Wiederverwendung desselben Links → abgewiesen
    // -------------------------------------------------------------------------

    public function test_token_reuse_is_rejected(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('reuse@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $app    = $this->createApp();
        $first  = $app->handle($this->verifyRequest($plain));
        self::assertSame(302, $first->getStatusCode());

        $second = $app->handle($this->verifyRequest($plain));
        self::assertSame(400, $second->getStatusCode());
        self::assertNull($this->cookieValue($second, 'votepit_sess'));
        self::assertStringContainsString('ungültig', (string) $second->getBody());
    }

    // -------------------------------------------------------------------------
    // AC3: abgelaufener Link → abgewiesen, kein Side-Effect
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
    // AC4: unbekannter/garbage Token → einheitliche 4xx, kein Side-Effect
    // -------------------------------------------------------------------------

    public function test_unknown_token_returns_4xx_without_side_effect(): void
    {
        // Ein echter User+Token existiert, darf aber NICHT angefasst werden.
        $realPlain = bin2hex(random_bytes(32));
        $userId    = $this->seedUser('innocent@example.com');
        $this->seedToken($userId, $realPlain, $this->future());

        $response = $this->createApp()->handle($this->verifyRequest('deadbeef' . bin2hex(random_bytes(28))));

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($this->cookieValue($response, 'votepit_sess'));

        // Der unbeteiligte Token bleibt unangetastet.
        $usedAt = $this->conn->fetchOne('SELECT used_at FROM login_tokens WHERE user_id = :id', ['id' => $userId]);
        self::assertNull($usedAt);
    }

    public function test_empty_token_returns_4xx(): void
    {
        $response = $this->createApp()->handle($this->verifyRequest(''));
        self::assertSame(400, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC5: Session-Fixation — Vor-Login-Cookie wird durch frisches ersetzt
    // -------------------------------------------------------------------------

    public function test_pre_login_session_cookie_is_replaced_not_honoured(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('fixation@example.com');
        $this->seedToken($userId, $plain, $this->future());

        // Ein gültig signiertes Vor-Login-Cookie mit fremder uid wird mitgeschickt.
        $stale = $this->sessions()->sign(['uid' => 999, 'v' => 0]);

        $response = $this->createApp()->handle(
            $this->verifyRequest($plain, ['votepit_sess' => $stale]),
        );

        self::assertSame(302, $response->getStatusCode());

        $fresh = $this->cookieValue($response, 'votepit_sess');
        self::assertNotNull($fresh);
        self::assertNotSame($stale, $fresh, 'Session-Cookie muss frisch ausgestellt werden');

        $payload = $this->sessions()->verify($fresh);
        self::assertIsArray($payload);
        self::assertSame($userId, $payload['uid']); // nicht 999 (altes Cookie ignoriert)
    }

    // -------------------------------------------------------------------------
    // AC6: Verify-Response trägt BEIDE Set-Cookie (Session + CSRF)
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
    // AC7: Admin-Promotion via Allowlist; kein stilles Downgrade
    // -------------------------------------------------------------------------

    public function test_allowlist_email_is_promoted_to_admin(): void
    {
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->seedUser('boss@example.com');
        $this->seedToken($userId, $plain, $this->future());

        $response = $this->appWithAdmins('boss@example.com')->handle($this->verifyRequest($plain));

        self::assertSame(302, $response->getStatusCode());
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
        $userId = $this->seedUser('exboss@example.com', isAdmin: 1); // bereits Admin
        $this->seedToken($userId, $plain, $this->future());

        // Allowlist ist jetzt leer — Login darf Admin NICHT entziehen.
        $response = $this->appWithAdmins()->handle($this->verifyRequest($plain));

        self::assertSame(302, $response->getStatusCode());
        $isAdmin = (int) $this->conn->fetchOne('SELECT is_admin FROM users WHERE id = :id', ['id' => $userId]);
        self::assertSame(1, $isAdmin);
    }

    // -------------------------------------------------------------------------
    // AC8: token_version-Spalte existiert additiv mit Default 0
    // -------------------------------------------------------------------------

    public function test_token_version_column_exists_with_default_zero(): void
    {
        $userId  = $this->seedUser('tv@example.com');
        $version = $this->conn->fetchOne('SELECT token_version FROM users WHERE id = :id', ['id' => $userId]);

        self::assertSame(0, (int) $version);
    }

    // -------------------------------------------------------------------------
    // AC9: Audit-Log pseudonymisiert, KEIN Klartext-Token
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
        self::assertStringNotContainsString($plain, $log);   // kein Klartext-Token
        self::assertStringNotContainsString($email, $log);   // E-Mail maskiert
    }

    public function test_failed_verify_is_logged_without_token(): void
    {
        $garbage = bin2hex(random_bytes(32));

        $this->createApp()->handle($this->verifyRequest($garbage));

        $log = $this->readAuditLog();
        self::assertStringContainsString('magic_link.verify_failed', $log);
        self::assertStringNotContainsString($garbage, $log);
    }
}
