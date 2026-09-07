<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * GET /api/bootstrap exposes users.is_test_account (migration 0041, Test-User
 * feature) so the SPA can skip Matomo entirely for a dedicated QA/E2E
 * account — see core/app/src/App.tsx's bootstrap effect.
 */
final class BootstrapTestAccountFlagTest extends IntegrationTestCase
{
    public function test_bootstrap_reports_is_test_account_true_for_a_test_account(): void
    {
        $userId = $this->insertUser('e2e@example.com', ['is_test_account' => 1]);

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/bootstrap')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]),
        );

        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['user']['is_test_account']);
    }

    public function test_bootstrap_reports_is_test_account_false_for_a_regular_user(): void
    {
        $userId = $this->insertUser('regular@example.com');

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/bootstrap')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]),
        );

        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['user']['is_test_account']);
    }
}
