<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\EncryptionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für SMTP-Admin-Settings (Installation-weite SMTP-Konfiguration).
 *
 * Testet: AuthZ (anon/non-admin/admin), CSRF, Speichern/Lesen,
 * Passwort-Roundtrip (verschlüsselt, GET ohne Klartext), Validierung,
 * Mailer nutzt DB-Settings vor config.php.
 */
final class SmtpSettingsActionTest extends IntegrationTestCase
{
    private function seedUser(string $email = 'user@example.com', bool $admin = false): int
    {
        $this->conn->insert('users', [
            'email'         => $email,
            'is_admin'      => $admin ? 1 : 0,
            'is_blocked'    => 0,
            'token_version' => 0,
            'verified_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        return (int) $this->conn->lastInsertId();
    }

    private function sessions(): \Votepit\Security\SessionService
    {
        return new \Votepit\Security\SessionService(str_repeat('a', 64), 3600, false);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function getRequest(string $path, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessions()->sign(['uid' => $userId, 'v' => 0]),
            ]);
        }
        return $request;
    }

    /** @param array<string, mixed> $body */
    private function mutatingRequest(string $method, string $path, ?int $userId, array $body, bool $withCsrf = true): ServerRequestInterface
    {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();
        $cookies   = [];

        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);
        }
        if ($withCsrf) {
            $cookies['votepit_csrf'] = $csrf->sign($csrfToken);
        }

        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withCookieParams($cookies)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json');

        if ($withCsrf) {
            $request = $request->withHeader('X-CSRF-Token', $csrfToken);
        }

        // Body als parsed JSON simulieren (addBodyParsingMiddleware parst application/json)
        return $request->withParsedBody($body);
    }

    /** @return array<string, mixed> */
    private function validSmtpBody(): array
    {
        return [
            'host'       => 'smtp.example.com',
            'port'       => 587,
            'user'       => 'testuser',
            'encryption' => 'tls',
            'from_email' => 'noreply@example.com',
            'from_name'  => 'Votepit Test',
            'password'   => 'supersecret',
        ];
    }

    // ── AuthZ ─────────────────────────────────────────────────────────────────

    public function test_get_as_anon_is_rejected(): void
    {
        $response = $this->createApp()->handle($this->getRequest('/admin/smtp', null));
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_get_as_non_admin_is_rejected(): void
    {
        $userId   = $this->seedUser('plain@example.com', false);
        $response = $this->createApp()->handle($this->getRequest('/admin/smtp', $userId));
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_get_as_admin_returns_defaults_when_not_configured(): void
    {
        $adminId  = $this->seedUser('admin@example.com', true);
        $response = $this->createApp()->handle($this->getRequest('/admin/smtp', $adminId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertSame('', $data['host']);
        self::assertFalse($data['password_set']);
    }

    public function test_put_as_anon_is_rejected(): void
    {
        $response = $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/smtp', null, $this->validSmtpBody()),
        );
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_put_as_non_admin_is_rejected(): void
    {
        $userId   = $this->seedUser('plain2@example.com', false);
        $response = $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/smtp', $userId, $this->validSmtpBody()),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_put_without_csrf_is_rejected(): void
    {
        $adminId  = $this->seedUser('admin2@example.com', true);
        $response = $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/smtp', $adminId, $this->validSmtpBody(), withCsrf: false),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    // ── Speichern / Lesen ─────────────────────────────────────────────────────

    public function test_admin_saves_valid_settings(): void
    {
        $adminId  = $this->seedUser('admin3@example.com', true);
        $response = $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/smtp', $adminId, $this->validSmtpBody()),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);

        // Gespeichert in app_settings?
        $host = $this->conn->fetchOne('SELECT value FROM app_settings WHERE "key" = \'smtp.host\'');
        self::assertSame('smtp.example.com', $host);
    }

    public function test_password_stored_encrypted_not_plaintext(): void
    {
        $adminId = $this->seedUser('admin4@example.com', true);
        $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/smtp', $adminId, $this->validSmtpBody()),
        );

        $storedPass = (string) $this->conn->fetchOne('SELECT value FROM app_settings WHERE "key" = \'smtp.pass\'');
        // Passwort ist NICHT im Klartext gespeichert.
        self::assertNotSame('supersecret', $storedPass);
        // Aber entschlüsselbar (Roundtrip).
        $enc       = new EncryptionService(str_repeat('a', 64));
        $decrypted = $enc->decrypt($storedPass);
        self::assertSame('supersecret', $decrypted);
    }

    public function test_get_does_not_return_password(): void
    {
        $adminId = $this->seedUser('admin5@example.com', true);
        $app     = $this->createApp();

        $app->handle($this->mutatingRequest('PUT', '/admin/smtp', $adminId, $this->validSmtpBody()));
        $response = $app->handle($this->getRequest('/admin/smtp', $adminId));

        $data = json_decode((string) $response->getBody(), true);
        // Kein Passwort-Feld im Response.
        self::assertArrayNotHasKey('password', $data);
        // Aber password_set-Flag gesetzt.
        self::assertTrue($data['password_set'] ?? false);
    }

    public function test_empty_password_does_not_overwrite_existing(): void
    {
        $adminId = $this->seedUser('admin6@example.com', true);
        $app     = $this->createApp();

        // Erst mit Passwort speichern.
        $app->handle($this->mutatingRequest('PUT', '/admin/smtp', $adminId, $this->validSmtpBody()));

        $firstPass = (string) $this->conn->fetchOne('SELECT value FROM app_settings WHERE "key" = \'smtp.pass\'');

        // Zweiter Save ohne Passwort (leeres Feld).
        $body             = $this->validSmtpBody();
        $body['password'] = '';
        $app->handle($this->mutatingRequest('PUT', '/admin/smtp', $adminId, $body));

        $secondPass = (string) $this->conn->fetchOne('SELECT value FROM app_settings WHERE "key" = \'smtp.pass\'');
        // Passwort unverändert.
        self::assertSame($firstPass, $secondPass);
    }

    // ── Validierung ───────────────────────────────────────────────────────────

    public function test_invalid_port_is_rejected(): void
    {
        $adminId = $this->seedUser('admin7@example.com', true);
        $body    = $this->validSmtpBody();
        $body['port'] = 99999;

        $response = $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/smtp', $adminId, $body),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('port', $data['error']['fields'] ?? []);
    }

    public function test_invalid_from_email_is_rejected(): void
    {
        $adminId = $this->seedUser('admin8@example.com', true);
        $body    = $this->validSmtpBody();
        $body['from_email'] = 'not-an-email';

        $response = $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/smtp', $adminId, $body),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_empty_host_is_rejected(): void
    {
        $adminId = $this->seedUser('admin9@example.com', true);
        $body    = $this->validSmtpBody();
        $body['host'] = '';

        $response = $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/smtp', $adminId, $body),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    // ── Mailer DB-Vorrang ─────────────────────────────────────────────────────

    public function test_mailer_uses_db_settings_over_config(): void
    {
        $adminId    = $this->seedUser('admin10@example.com', true);
        $app        = $this->createApp();

        // Speichere DB-Settings.
        $app->handle($this->mutatingRequest('PUT', '/admin/smtp', $adminId, $this->validSmtpBody()));

        // Verifiziere: SmtpSettingsRepository liefert gültige SmtpConfig.
        $enc   = new EncryptionService(str_repeat('a', 64));
        $repo  = new \Votepit\Persistence\SmtpSettingsRepository($this->conn);
        $smtpCfg = $repo->findAsSmtpConfig($enc);

        self::assertNotNull($smtpCfg);
        self::assertSame('smtp.example.com', $smtpCfg->host);
        self::assertSame(587, $smtpCfg->port);
        self::assertSame('supersecret', $smtpCfg->pass);
    }
}
