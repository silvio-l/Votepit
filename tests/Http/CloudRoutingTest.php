<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Http\AppFactory;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\InMemoryMailer;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Cloud-mode routing (cloud path routing).
 *
 * Verifies both halves of the lockstep invariant:
 * 1) Cloud mode (`routing_mode: 'cloud'`) correctly resolves the {account}
 *    path segment; unknown account slug → 404 (fail-secure, no cross-tenant
 *    fallback to the default account).
 * 2) Reserved system routes (login, admin/smtp, api/v1/*) always resolve as
 *    a system route in cloud mode, never as an attempted {account} match —
 *    structurally guaranteed by the differing segment count/missing
 *    {account} prefix (see AccountContextMiddleware doc), captured here as
 *    a behavioral acceptance test.
 *
 * Self-host (routing_mode default 'self-host') remains unchanged — the
 * existing 700+-test bed (IntegrationTestCase::testConfig(), no
 * routing_mode override) keeps running entirely without an {account} segment.
 */
final class CloudRoutingTest extends IntegrationTestCase
{
    private function cloudConfig(): Config
    {
        return Config::fromArray([
            'env'                  => 'dev',
            'app_url'              => 'http://localhost:8000',
            'app_key'              => str_repeat('a', 64),
            'identity_server_key'  => self::identityServerKey(),
            'db'                   => ['name' => ':memory:'],
            'smtp'                 => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'       => 900,
            'routing_mode'         => 'cloud',
        ]);
    }

    /** @return \Slim\App<null> */
    private function cloudApp(): \Slim\App
    {
        return AppFactory::create($this->cloudConfig(), $this->conn, new InMemoryMailer(), new AuditLogger($this->logFile));
    }

    // ── Cloud mode: {account} segment resolves the correct account ──────

    public function test_cloud_mode_resolves_board_via_account_slug_segment(): void
    {
        $accountId = $this->insertAccount(['slug' => 'acme']);
        $this->insertBoard('roadmap-ideas', ['account_id' => $accountId, 'name' => 'Acme Board']);

        $response = $this->cloudApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/acme/roadmap-ideas'),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('Acme Board', $data['board']['name'] ?? null);
    }

    public function test_cloud_mode_keeps_two_accounts_with_the_same_board_slug_isolated(): void
    {
        $accountA = $this->insertAccount(['slug' => 'account-a']);
        $accountB = $this->insertAccount(['slug' => 'account-b']);
        $this->insertBoard('shared-slug', ['account_id' => $accountA, 'name' => 'Board A']);
        $this->insertBoard('shared-slug', ['account_id' => $accountB, 'name' => 'Board B']);

        $responseA = $this->cloudApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/account-a/shared-slug'),
        );
        $responseB = $this->cloudApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/account-b/shared-slug'),
        );

        $dataA = json_decode((string) $responseA->getBody(), true);
        $dataB = json_decode((string) $responseB->getBody(), true);
        self::assertSame('Board A', $dataA['board']['name'] ?? null);
        self::assertSame('Board B', $dataB['board']['name'] ?? null);
    }

    public function test_cloud_mode_returns_404_for_unknown_account_slug(): void
    {
        // Fail-secure: an unknown account slug must NEVER silently fall back
        // to the default account (cross-tenant leak).
        $response = $this->cloudApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/no-such-account/some-board'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    // ── Route resolution order: reserved system routes are never
    //    shadowed by the {account} segment ─────────────────────────────

    public function test_login_route_resolves_as_system_route_in_cloud_mode(): void
    {
        $response = $this->cloudApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/login'),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
    }

    public function test_bearer_api_v1_route_resolves_as_system_route_in_cloud_mode(): void
    {
        // No account/board slug in the path — must still require Bearer auth
        // unchanged in cloud mode too (401, not 404 from a failed account
        // match).
        $response = $this->cloudApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/board'),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    // ── /api/bootstrap surfaces routing_mode (SPA needs it BEFORE deciding
    //    whether to expect an {account}-prefixed path segment) ─────────────

    public function test_bootstrap_reports_cloud_routing_mode(): void
    {
        $response = $this->cloudApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/bootstrap'),
        );

        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('cloud', $data['routing_mode'] ?? null);
    }

    public function test_bootstrap_reports_self_host_routing_mode_by_default(): void
    {
        $app = AppFactory::create($this->testConfig(), $this->conn, new InMemoryMailer(), new AuditLogger($this->logFile));

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/bootstrap'),
        );

        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('self-host', $data['routing_mode'] ?? null);
    }

    // ── /api/bootstrap surfaces the caller's account memberships (SPA fix,
    //    Fable audit 2026-09-02: gate on account role instead of the platform
    //    admin flag) ────────────────────────────────────────────────────────

    public function test_bootstrap_reports_account_memberships_for_logged_in_user(): void
    {
        $userId    = $this->insertUser('owner@example.com');
        $accountId = $this->insertAccount(['slug' => 'acme']);
        $this->insertAccountMember($accountId, $userId, 'owner');

        $response = $this->cloudApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/bootstrap')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame(
            [['account_slug' => 'acme', 'role' => 'owner']],
            $data['user']['memberships'] ?? null,
        );
    }

    public function test_bootstrap_reports_empty_memberships_for_anonymous_caller(): void
    {
        $response = $this->cloudApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/bootstrap'),
        );

        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('user', $data);
        self::assertNull($data['user']);
    }
}
