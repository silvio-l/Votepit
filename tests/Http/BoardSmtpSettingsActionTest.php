<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\EncryptionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für Board-spezifische SMTP-Konfiguration.
 */
final class BoardSmtpSettingsActionTest extends IntegrationTestCase
{
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

        return $request->withParsedBody($body);
    }

    /** @return array<string, mixed> */
    private function validBoardSmtpBody(): array
    {
        return [
            'host'       => 'board-smtp.example.com',
            'port'       => 587,
            'user'       => 'boarduser',
            'encryption' => 'tls',
            'from_email' => 'board@example.com',
            'from_name'  => 'Board Test',
            'password'   => 'boardsecret',
        ];
    }

    // ── AuthZ ─────────────────────────────────────────────────────────────────

    public function test_get_board_smtp_as_anon_is_rejected(): void
    {
        $this->insertBoard('demo');
        $response = $this->createApp()->handle($this->getRequest('/admin/boards/demo/smtp', null));
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_get_board_smtp_as_non_admin_is_rejected(): void
    {
        $this->insertBoard('demo');
        $userId   = $this->insertUser('plain@example.com');
        $response = $this->createApp()->handle($this->getRequest('/admin/boards/demo/smtp', $userId));
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_get_board_smtp_unknown_board_is_404(): void
    {
        $adminId  = $this->insertUser('admin@example.com', ['is_admin' => 1]);
        $response = $this->createApp()->handle($this->getRequest('/admin/boards/nonexistent/smtp', $adminId));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_get_board_smtp_returns_uses_global_default_when_not_configured(): void
    {
        $this->insertBoard('demo');
        $adminId  = $this->insertUser('admin@example.com', ['is_admin' => 1]);
        $response = $this->createApp()->handle($this->getRequest('/admin/boards/demo/smtp', $adminId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertTrue($data['uses_global_default'] ?? false);
        self::assertFalse($data['password_set'] ?? true);
    }

    public function test_put_board_smtp_as_anon_is_rejected(): void
    {
        $this->insertBoard('demo');
        $response = $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/boards/demo/smtp', null, $this->validBoardSmtpBody()),
        );
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_put_board_smtp_as_non_admin_is_rejected(): void
    {
        $this->insertBoard('demo');
        $userId   = $this->insertUser('plain2@example.com');
        $response = $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/boards/demo/smtp', $userId, $this->validBoardSmtpBody()),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_put_board_smtp_without_csrf_is_rejected(): void
    {
        $this->insertBoard('demo');
        $adminId  = $this->insertUser('admin2@example.com', ['is_admin' => 1]);
        $response = $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/boards/demo/smtp', $adminId, $this->validBoardSmtpBody(), withCsrf: false),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    // ── Speichern / Lesen ─────────────────────────────────────────────────────

    public function test_admin_saves_board_smtp_settings(): void
    {
        $boardId = $this->insertBoard('demo');
        $adminId = $this->insertUser('admin3@example.com', ['is_admin' => 1]);
        $app     = $this->createApp();

        $response = $app->handle(
            $this->mutatingRequest('PUT', '/admin/boards/demo/smtp', $adminId, $this->validBoardSmtpBody()),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);

        $host = $this->conn->fetchOne(
            'SELECT host FROM board_smtp_settings WHERE board_id = :id',
            ['id' => $boardId],
        );
        self::assertSame('board-smtp.example.com', $host);
    }

    public function test_board_smtp_password_stored_encrypted(): void
    {
        $this->insertBoard('demo');
        $adminId = $this->insertUser('admin4@example.com', ['is_admin' => 1]);
        $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/boards/demo/smtp', $adminId, $this->validBoardSmtpBody()),
        );

        $storedPass = (string) $this->conn->fetchOne(
            'SELECT pass FROM board_smtp_settings WHERE board_id = (SELECT id FROM boards WHERE slug = \'demo\')',
        );
        self::assertNotSame('boardsecret', $storedPass);

        $enc       = new EncryptionService(str_repeat('a', 64));
        $decrypted = $enc->decrypt($storedPass);
        self::assertSame('boardsecret', $decrypted);
    }

    public function test_get_board_smtp_does_not_return_password(): void
    {
        $this->insertBoard('demo');
        $adminId = $this->insertUser('admin5@example.com', ['is_admin' => 1]);
        $app     = $this->createApp();

        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/smtp', $adminId, $this->validBoardSmtpBody()));
        $response = $app->handle($this->getRequest('/admin/boards/demo/smtp', $adminId));

        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayNotHasKey('password', $data);
        self::assertTrue($data['password_set'] ?? false);
        self::assertFalse($data['uses_global_default'] ?? true);
    }

    public function test_empty_password_does_not_overwrite_existing_board_password(): void
    {
        $this->insertBoard('demo');
        $adminId = $this->insertUser('admin6@example.com', ['is_admin' => 1]);
        $app     = $this->createApp();

        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/smtp', $adminId, $this->validBoardSmtpBody()));

        $firstPass = (string) $this->conn->fetchOne(
            'SELECT pass FROM board_smtp_settings WHERE board_id = (SELECT id FROM boards WHERE slug = \'demo\')',
        );

        $body             = $this->validBoardSmtpBody();
        $body['password'] = '';
        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/smtp', $adminId, $body));

        $secondPass = (string) $this->conn->fetchOne(
            'SELECT pass FROM board_smtp_settings WHERE board_id = (SELECT id FROM boards WHERE slug = \'demo\')',
        );
        self::assertSame($firstPass, $secondPass);
    }

    // ── Auflösungs-Präzedenz ──────────────────────────────────────────────────

    public function test_resolver_prefers_board_over_global(): void
    {
        $boardId = $this->insertBoard('demo');
        $adminId = $this->insertUser('admin7@example.com', ['is_admin' => 1]);
        $app     = $this->createApp();

        $app->handle($this->mutatingRequest('PUT', '/admin/smtp', $adminId, [
            'host'       => 'global-smtp.example.com',
            'port'       => 587,
            'user'       => 'global',
            'encryption' => 'tls',
            'from_email' => 'global@example.com',
            'from_name'  => 'Global',
            'password'   => 'globalpass',
        ]));

        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/smtp', $adminId, $this->validBoardSmtpBody()));

        $enc           = new EncryptionService(str_repeat('a', 64));
        $globalRepo    = new \Votepit\Persistence\SmtpSettingsRepository($this->conn);
        $boardSmtpRepo = new \Votepit\Persistence\BoardSmtpSettingsRepository($this->conn);
        $resolver      = new \Votepit\Mail\SmtpConfigResolver(
            $globalRepo,
            $boardSmtpRepo,
            $enc,
            \Votepit\SmtpConfig::fromArray(['host' => 'fallback', 'from_email' => 'fb@example.com']),
        );

        $resolved = $resolver->resolve($boardId);
        self::assertSame('board-smtp.example.com', $resolved->host);
    }

    public function test_resolver_falls_back_to_global_when_no_board_settings(): void
    {
        $boardId = $this->insertBoard('demo');
        $adminId = $this->insertUser('admin8@example.com', ['is_admin' => 1]);
        $app     = $this->createApp();

        $app->handle($this->mutatingRequest('PUT', '/admin/smtp', $adminId, [
            'host'       => 'global-smtp.example.com',
            'port'       => 587,
            'user'       => 'global',
            'encryption' => 'tls',
            'from_email' => 'global@example.com',
            'from_name'  => 'Global',
            'password'   => 'globalpass',
        ]));

        $enc           = new EncryptionService(str_repeat('a', 64));
        $globalRepo    = new \Votepit\Persistence\SmtpSettingsRepository($this->conn);
        $boardSmtpRepo = new \Votepit\Persistence\BoardSmtpSettingsRepository($this->conn);
        $resolver      = new \Votepit\Mail\SmtpConfigResolver(
            $globalRepo,
            $boardSmtpRepo,
            $enc,
            \Votepit\SmtpConfig::fromArray(['host' => 'fallback', 'from_email' => 'fb@example.com']),
        );

        $resolved = $resolver->resolve($boardId);
        self::assertSame('global-smtp.example.com', $resolved->host);
    }

    public function test_resolver_falls_back_to_config_when_no_settings_at_all(): void
    {
        $boardId       = $this->insertBoard('demo');
        $enc           = new EncryptionService(str_repeat('a', 64));
        $globalRepo    = new \Votepit\Persistence\SmtpSettingsRepository($this->conn);
        $boardSmtpRepo = new \Votepit\Persistence\BoardSmtpSettingsRepository($this->conn);
        $fallback      = \Votepit\SmtpConfig::fromArray(['host' => 'config-fallback.example.com', 'from_email' => 'fb@example.com']);
        $resolver      = new \Votepit\Mail\SmtpConfigResolver($globalRepo, $boardSmtpRepo, $enc, $fallback);

        $resolved = $resolver->resolve($boardId);
        self::assertSame('config-fallback.example.com', $resolved->host);
    }

    // ── Reset auf globalen Default ─────────────────────────────────────────────

    public function test_reset_to_global_default_deletes_board_settings(): void
    {
        $boardId = $this->insertBoard('demo');
        $adminId = $this->insertUser('admin9@example.com', ['is_admin' => 1]);
        $app     = $this->createApp();

        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/smtp', $adminId, $this->validBoardSmtpBody()));

        $row = $this->conn->fetchOne('SELECT id FROM board_smtp_settings WHERE board_id = :id', ['id' => $boardId]);
        self::assertNotFalse($row);

        $response = $app->handle(
            $this->mutatingRequest('PUT', '/admin/boards/demo/smtp', $adminId, ['reset_to_global' => true]),
        );
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['reset'] ?? false);

        $after = $this->conn->fetchOne('SELECT id FROM board_smtp_settings WHERE board_id = :id', ['id' => $boardId]);
        self::assertFalse($after);

        $getResp = $app->handle($this->getRequest('/admin/boards/demo/smtp', $adminId));
        $getData = json_decode((string) $getResp->getBody(), true);
        self::assertTrue($getData['uses_global_default'] ?? false);
    }

    // ── Validierung ────────────────────────────────────────────────────────────

    public function test_invalid_port_is_rejected_for_board_smtp(): void
    {
        $this->insertBoard('demo');
        $adminId = $this->insertUser('admin10@example.com', ['is_admin' => 1]);
        $body    = $this->validBoardSmtpBody();
        $body['port'] = 99999;

        $response = $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/boards/demo/smtp', $adminId, $body),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('port', $data['error']['fields'] ?? []);
    }

    // ── verify_peer Roundtrip ─────────────────────────────────────────────────

    public function test_board_verify_peer_default_true_in_get_response(): void
    {
        $this->insertBoard('demo');
        $adminId = $this->insertUser('admin11@example.com', ['is_admin' => 1]);
        $app     = $this->createApp();

        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/smtp', $adminId, $this->validBoardSmtpBody()));
        $response = $app->handle($this->getRequest('/admin/boards/demo/smtp', $adminId));

        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('verify_peer', $data);
        self::assertTrue($data['verify_peer']);
    }

    public function test_board_verify_peer_false_is_stored_and_returned(): void
    {
        $boardId = $this->insertBoard('demo');
        $adminId = $this->insertUser('admin12@example.com', ['is_admin' => 1]);
        $app     = $this->createApp();

        $body                = $this->validBoardSmtpBody();
        $body['verify_peer'] = false;
        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/smtp', $adminId, $body));

        // GET liefert verify_peer=false zurück.
        $response = $app->handle($this->getRequest('/admin/boards/demo/smtp', $adminId));
        $data     = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['verify_peer'] ?? true);

        // Repo liefert SmtpConfig->verifyPeer === false.
        $enc           = new EncryptionService(str_repeat('a', 64));
        $boardSmtpRepo = new \Votepit\Persistence\BoardSmtpSettingsRepository($this->conn);
        $smtpCfg       = $boardSmtpRepo->findAsSmtpConfig($boardId, $enc);
        self::assertNotNull($smtpCfg);
        self::assertFalse($smtpCfg->verifyPeer);
    }

    public function test_resolver_passes_verify_peer_false_through(): void
    {
        $boardId = $this->insertBoard('demo');
        $adminId = $this->insertUser('admin13@example.com', ['is_admin' => 1]);
        $app     = $this->createApp();

        $body                = $this->validBoardSmtpBody();
        $body['verify_peer'] = false;
        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/smtp', $adminId, $body));

        $enc           = new EncryptionService(str_repeat('a', 64));
        $globalRepo    = new \Votepit\Persistence\SmtpSettingsRepository($this->conn);
        $boardSmtpRepo = new \Votepit\Persistence\BoardSmtpSettingsRepository($this->conn);
        $resolver      = new \Votepit\Mail\SmtpConfigResolver(
            $globalRepo,
            $boardSmtpRepo,
            $enc,
            \Votepit\SmtpConfig::fromArray(['host' => 'fallback', 'from_email' => 'fb@example.com']),
        );

        $resolved = $resolver->resolve($boardId);
        self::assertFalse($resolved->verifyPeer);
    }
}
