<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * GET /admin/account (AccountSettingsAction) — owner-only account summary
 * for the SPA's account settings page.
 */
final class AccountSettingsActionTest extends IntegrationTestCase
{
    private function get(?int $actingUserId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/account');

        if ($actingUserId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($actingUserId)]);
        }

        return $request;
    }

    public function test_owner_gets_slug_default_flag_and_deletion_state(): void
    {
        $ownerId = $this->insertUser('owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->get($ownerId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertSame($this->defaultAccountId(), $data['account_id']);
        self::assertSame($this->defaultAccountSlug(), $data['slug']);
        self::assertTrue($data['is_default_account']);
        self::assertNull($data['deletion_scheduled_at']);
        self::assertArrayNotHasKey('plan', $data);
    }

    public function test_pending_deletion_deadline_is_exposed(): void
    {
        $ownerId = $this->insertUser('owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $this->conn->update('accounts', ['deletion_scheduled_at' => '2030-01-01 00:00:00'], ['id' => $this->defaultAccountId()]);

        $response = $this->createApp()->handle($this->get($ownerId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertSame('2030-01-01 00:00:00', $data['deletion_scheduled_at']);
    }

    public function test_moderator_is_forbidden(): void
    {
        $modId = $this->insertUser('mod@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->get($modId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_anonymous_is_unauthorized(): void
    {
        $response = $this->createApp()->handle($this->get(null));

        self::assertSame(401, $response->getStatusCode());
    }
}
