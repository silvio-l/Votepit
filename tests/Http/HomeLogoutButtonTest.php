<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Der Logout-Button auf der Startseite darf nur für tatsächlich eingeloggte
 * Nutzer erscheinen — nicht für anonyme Besucher (er hing zuvor fälschlich nur
 * am stets gesetzten csrf_token). Issue-04-Review-Suggestion, hier fixiert.
 */
final class HomeLogoutButtonTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_anonymous_home_has_no_logout_form(): void
    {
        $app      = $this->createApp();
        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        // JSON-API: Anon → is_authenticated=false (SPA zeigt keinen Logout-Button)
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['is_authenticated'] ?? true);
    }

    public function test_authenticated_home_shows_logout_form(): void
    {
        $userId = (int) $this->conn->executeStatement(
            "INSERT INTO users (email, token_version, verified_at) VALUES ('user@example.com', 0, CURRENT_TIMESTAMP)",
        ) === 1 ? (int) $this->conn->lastInsertId() : 0;

        $sessCookie = (new SessionService(self::APP_KEY, 3600, false))->sign(['uid' => $userId, 'v' => 0]);

        $app      = $this->createApp();
        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withCookieParams(['votepit_sess' => $sessCookie]);
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        // JSON-API: eingeloggter User → is_authenticated=true (SPA zeigt Logout-Button)
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['is_authenticated'] ?? false);
    }
}
