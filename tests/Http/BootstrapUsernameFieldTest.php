<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * GET /api/bootstrap exposes users.username (migration 0022) so the SPA's
 * session/account menu (AdminShell.tsx) can show the caller's real display
 * name instead of falling back to the current account slug or the opaque
 * public_id — which broke on operator/platform pages that have no account
 * slug in the URL (2026-09-05 bug report).
 */
final class BootstrapUsernameFieldTest extends IntegrationTestCase
{
    public function test_bootstrap_reports_the_username_when_set(): void
    {
        $userId = $this->insertUser('named@example.com', ['username' => 'jane', 'username_lower' => 'jane']);

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/bootstrap')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]),
        );

        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('jane', $data['user']['username']);
    }

    public function test_bootstrap_reports_null_username_when_unset(): void
    {
        $userId = $this->insertUser('anon@example.com');

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/bootstrap')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]),
        );

        $data = json_decode((string) $response->getBody(), true);
        self::assertNull($data['user']['username']);
    }
}
