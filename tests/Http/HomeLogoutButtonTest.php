<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * The logout button on the home page must only appear for actually logged-in
 * users — not for anonymous visitors (it previously incorrectly depended
 * only on the always-set csrf_token). Fixed here.
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
        // JSON API: anon → is_authenticated=false (SPA shows no logout button)
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['is_authenticated'] ?? true);
    }

    public function test_authenticated_home_shows_logout_form(): void
    {
        $emailHmac = (new \Votepit\Security\IdentityHasher(self::identityServerKey()))->hash('user@example.com');
        $userId    = (int) $this->conn->executeStatement(
            "INSERT INTO users (public_id, email_hmac, token_version, verified_at) VALUES ('TESTUSER01', '{$emailHmac}', 0, CURRENT_TIMESTAMP)",
        ) === 1 ? (int) $this->conn->lastInsertId() : 0;

        $sessCookie = (new SessionService(self::APP_KEY, 3600, false))->sign(['uid' => $userId, 'v' => 0]);

        $app      = $this->createApp();
        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withCookieParams(['votepit_sess' => $sessCookie]);
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        // JSON API: logged-in user → is_authenticated=true (SPA shows logout button)
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['is_authenticated'] ?? false);
    }
}
