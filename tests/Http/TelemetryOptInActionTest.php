<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /admin/telemetry-opt-in — self-host
 * product-improvement telemetry opt-out toggle (TelemetryOptInAction).
 */
final class TelemetryOptInActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postRequest(?int $userId, bool $optedIn): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/telemetry-opt-in')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'opted_in' => $optedIn]);
    }

    public function test_post_as_anon_returns_401(): void
    {
        $response = $this->createApp()->handle($this->postRequest(null, false));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_post_as_non_member_returns_403(): void
    {
        $userId   = $this->insertUser('no-membership-telemetry@example.com');
        $response = $this->createApp()->handle($this->postRequest($userId, false));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_accounts_default_to_opted_in(): void
    {
        $optedIn = $this->conn->fetchOne(
            'SELECT telemetry_opted_in FROM accounts WHERE id = :id',
            ['id' => $this->defaultAccountId()],
        );
        self::assertSame(1, (int) $optedIn);
    }

    public function test_owner_can_opt_out(): void
    {
        $ownerId = $this->insertUser('owner-telemetry-out@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postRequest($ownerId, false));

        self::assertSame(200, $response->getStatusCode());
        $optedIn = $this->conn->fetchOne(
            'SELECT telemetry_opted_in FROM accounts WHERE id = :id',
            ['id' => $this->defaultAccountId()],
        );
        self::assertSame(0, (int) $optedIn);
        $decidedAt = $this->conn->fetchOne(
            'SELECT telemetry_decided_at FROM accounts WHERE id = :id',
            ['id' => $this->defaultAccountId()],
        );
        self::assertNotNull($decidedAt);
    }

    public function test_owner_can_opt_back_in(): void
    {
        $this->conn->update(
            'accounts',
            ['telemetry_opted_in' => 0, 'telemetry_decided_at' => '2020-01-01 00:00:00'],
            ['id' => $this->defaultAccountId()],
        );
        $ownerId = $this->insertUser('owner-telemetry-in@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postRequest($ownerId, true));

        self::assertSame(200, $response->getStatusCode());
        $optedIn = $this->conn->fetchOne(
            'SELECT telemetry_opted_in FROM accounts WHERE id = :id',
            ['id' => $this->defaultAccountId()],
        );
        self::assertSame(1, (int) $optedIn);
    }

    public function test_post_as_admin_succeeds(): void
    {
        $adminId = $this->insertUser('admin-telemetry@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'admin');

        $response = $this->createApp()->handle($this->postRequest($adminId, false));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_post_as_moderator_returns_403(): void
    {
        $modId = $this->insertUser('mod-telemetry@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postRequest($modId, false));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_post_does_not_affect_a_different_account(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-foreign-telemetry']);
        $ownerId          = $this->insertUser('owner-cross-telemetry@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $this->createApp()->handle($this->postRequest($ownerId, false));

        $foreignOptedIn = $this->conn->fetchOne(
            'SELECT telemetry_opted_in FROM accounts WHERE id = :id',
            ['id' => $foreignAccountId],
        );
        self::assertSame(1, (int) $foreignOptedIn);
    }
}
