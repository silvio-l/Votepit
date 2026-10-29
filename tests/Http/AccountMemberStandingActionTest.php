<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for PATCH /admin/members/{userId}/standing — manual
 * repeat-offender standing override (board-operator-compliance issue 10).
 */
final class AccountMemberStandingActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function patchStanding(int $targetUserId, array $body, ?int $actorId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($actorId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($actorId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/admin/members/' . $targetUserId . '/standing')
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    public function test_moderator_can_manually_suspend_a_member(): void
    {
        $accountId = $this->defaultAccountId();
        $modId     = $this->insertUser('standing-override-mod@example.com');
        $this->insertAccountMember($accountId, $modId, 'moderator');
        $targetId  = $this->insertUser('standing-override-target@example.com');
        $this->insertAccountMember($accountId, $targetId, 'member');

        $response = $this->createApp()->handle($this->patchStanding($targetId, ['state' => 'suspended'], $modId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        self::assertSame('suspended', $data['state'] ?? null);

        $row = $this->conn->fetchAssociative(
            'SELECT state, state_changed_by_user_id FROM account_member_standing WHERE account_id = :a AND user_id = :u',
            ['a' => $accountId, 'u' => $targetId],
        );
        self::assertIsArray($row);
        self::assertSame('suspended', $row['state']);
        self::assertSame($modId, (int) $row['state_changed_by_user_id'], 'manual override must stamp the acting user id');
    }

    public function test_manual_override_is_distinguishable_from_automatic_change(): void
    {
        $accountId = $this->defaultAccountId();
        $ownerId   = $this->insertUser('standing-distinguish-owner@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($accountId, $ownerId, 'owner');
        $targetId  = $this->insertUser('standing-distinguish-target@example.com');
        $this->insertAccountMember($accountId, $targetId, 'member');

        // Seed an "automatic" row (state_changed_by_user_id NULL), as
        // upsertAutomatic() would have left it.
        $this->seedStanding($accountId, $targetId, 'flagged', 1);

        $before = $this->conn->fetchAssociative(
            'SELECT state_changed_by_user_id FROM account_member_standing WHERE account_id = :a AND user_id = :u',
            ['a' => $accountId, 'u' => $targetId],
        );
        self::assertIsArray($before);
        self::assertNull($before['state_changed_by_user_id']);

        $response = $this->createApp()->handle($this->patchStanding($targetId, ['state' => 'normal'], $ownerId));
        self::assertSame(200, $response->getStatusCode());

        $after = $this->conn->fetchAssociative(
            'SELECT state, state_changed_by_user_id FROM account_member_standing WHERE account_id = :a AND user_id = :u',
            ['a' => $accountId, 'u' => $targetId],
        );
        self::assertIsArray($after);
        self::assertSame('normal', $after['state']);
        self::assertSame($ownerId, (int) $after['state_changed_by_user_id']);
    }

    public function test_invalid_state_returns_422(): void
    {
        $accountId = $this->defaultAccountId();
        $ownerId   = $this->insertUser('standing-invalid-owner@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($accountId, $ownerId, 'owner');
        $targetId  = $this->insertUser('standing-invalid-target@example.com');
        $this->insertAccountMember($accountId, $targetId, 'member');

        $response = $this->createApp()->handle($this->patchStanding($targetId, ['state' => 'banned'], $ownerId));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_non_member_of_account_returns_404(): void
    {
        $accountId = $this->defaultAccountId();
        $ownerId   = $this->insertUser('standing-404-owner@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($accountId, $ownerId, 'owner');
        $strangerId = $this->insertUser('standing-404-stranger@example.com');

        $response = $this->createApp()->handle($this->patchStanding($strangerId, ['state' => 'suspended'], $ownerId));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_non_moderator_member_cannot_override_standing(): void
    {
        $accountId = $this->defaultAccountId();
        $memberId  = $this->insertUser('standing-403-member@example.com');
        $this->insertAccountMember($accountId, $memberId, 'member');
        $targetId  = $this->insertUser('standing-403-target@example.com');
        $this->insertAccountMember($accountId, $targetId, 'member');

        $response = $this->createApp()->handle($this->patchStanding($targetId, ['state' => 'suspended'], $memberId));

        self::assertSame(403, $response->getStatusCode());
    }
}
