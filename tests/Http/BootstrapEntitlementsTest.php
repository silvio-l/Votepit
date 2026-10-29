<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * P-38: the entitlements object is account-scoped. Members see their own
 * accounts' plan and feature answers in bootstrap memberships; a foreign
 * account's plan never appears, and public board payloads carry none.
 */
final class BootstrapEntitlementsTest extends IntegrationTestCase
{
    /** @return array<string, mixed> */
    private function bootstrap(?int $userId): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/bootstrap');
        if ($userId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
        }

        return json_decode((string) $this->createApp()->handle($request)->getBody(), true);
    }

    public function test_member_sees_entitlements_for_own_account_only(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $userId = $this->insertUser('member-ent@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'admin');

        $foreign = $this->insertAccount(['slug' => 'foreign-plan', 'plan' => 'pro']);
        $other   = $this->insertUser('other-owner@example.com');
        $this->insertAccountMember($foreign, $other, 'owner');

        $data = $this->bootstrap($userId);
        $memberships = $data['user']['memberships'];

        self::assertCount(1, $memberships);
        self::assertSame('lite', $memberships[0]['entitlements']['current_plan']);
        self::assertFalse($memberships[0]['entitlements']['can_change_plan']); // admin, not owner
        self::assertTrue($memberships[0]['entitlements']['features']['logo_url']['allowed']);
        self::assertFalse($memberships[0]['entitlements']['features']['hide_badge']['allowed']);
        self::assertStringNotContainsString('foreign-plan', (string) json_encode($data));
    }

    public function test_owner_is_offered_a_plan_change_only_when_billing_is_installed(): void
    {
        $userId = $this->insertUser('owner-ent@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'owner');

        // The core test app has no billing extension.
        $data = $this->bootstrap($userId);
        self::assertFalse($data['user']['memberships'][0]['entitlements']['can_change_plan']);
    }

    public function test_anonymous_caller_gets_no_memberships_and_no_entitlements(): void
    {
        $data = $this->bootstrap(null);

        self::assertNull($data['user']);
        self::assertStringNotContainsString('entitlements', (string) json_encode($data));
    }

    public function test_public_board_payload_carries_no_entitlements(): void
    {
        $this->conn->insert('boards', [
            'account_id' => $this->defaultAccountId(),
            'slug'       => 'open-board',
            'name'       => 'Open',
            'visibility' => 'public',
            'is_default' => 0,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $response = $this->createApp()->handle((new ServerRequestFactory())->createServerRequest('GET', '/open-board'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('entitlements', (string) $response->getBody());
    }
}
