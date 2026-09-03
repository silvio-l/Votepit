<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /admin/members, POST /admin/members/{id}/remove
 * and POST /admin/members/{id}/role.
 *
 * AC coverage:
 *   - Owner + moderator can read the list (accountAdmin); anon → 401.
 *   - Only owner may remove/change role (accountOwner) — moderator → 403.
 *   - The last owner can neither be removed nor demoted → 422.
 *   - Cross-tenant isolation: foreign user_id (different account) → 404, no leak.
 */
final class MemberActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function getMembers(?int $actingUserId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/members');

        if ($actingUserId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($actingUserId)]);
        }

        return $request;
    }

    private function postRemove(int $targetUserId, ?int $actingUserId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($actingUserId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($actingUserId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', "/admin/members/{$targetUserId}/remove")
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token]);
    }

    private function postRole(int $targetUserId, string $role, ?int $actingUserId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($actingUserId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($actingUserId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', "/admin/members/{$targetUserId}/role")
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'role' => $role]);
    }

    // -------------------------------------------------------------------------
    // GET /admin/members — owner + moderator may read, anon → 401
    // -------------------------------------------------------------------------

    public function test_owner_lists_members_and_pending_invites(): void
    {
        $ownerId = $this->insertUser('owner-list@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-list@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');
        $invitedId = $this->insertUser('invited-list@example.com');
        $this->insertInvite($this->defaultAccountId(), $invitedId, $ownerId, str_repeat('b', 64));

        $response = $this->createApp()->handle($this->getMembers($ownerId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('owner', $data['viewer_role'] ?? null);
        self::assertCount(2, $data['members']);
        self::assertCount(1, $data['invites']);
    }

    public function test_moderator_can_also_list_members(): void
    {
        $ownerId = $this->insertUser('owner-mod-view@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-view@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->getMembers($modId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('moderator', $data['viewer_role'] ?? null);
    }

    public function test_anon_list_returns_401(): void
    {
        $response = $this->createApp()->handle($this->getMembers(null));

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Remove — owner-only, last owner protected
    // -------------------------------------------------------------------------

    public function test_owner_removes_moderator_returns_200(): void
    {
        $ownerId = $this->insertUser('owner-remove@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-remove@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postRemove($modId, $ownerId));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $modId],
        ));
    }

    public function test_moderator_cannot_remove_member_returns_403(): void
    {
        $ownerId = $this->insertUser('owner-remove-403@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-remove-403@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');
        $otherModId = $this->insertUser('mod-remove-403-target@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $otherModId, 'moderator');

        $response = $this->createApp()->handle($this->postRemove($otherModId, $modId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_removing_last_owner_returns_422(): void
    {
        $ownerId = $this->insertUser('owner-last@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postRemove($ownerId, $ownerId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('owner', $this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $ownerId],
        ));
    }

    public function test_removing_unknown_member_returns_404(): void
    {
        $ownerId = $this->insertUser('owner-remove-404@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postRemove(999999, $ownerId));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Role change — owner-only, last owner protected
    // -------------------------------------------------------------------------

    public function test_owner_promotes_moderator_to_owner(): void
    {
        $ownerId = $this->insertUser('owner-promote@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-promote@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postRole($modId, 'owner', $ownerId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('owner', $this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $modId],
        ));
    }

    public function test_demoting_last_owner_returns_422(): void
    {
        $ownerId = $this->insertUser('owner-demote-last@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postRole($ownerId, 'moderator', $ownerId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('owner', $this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $ownerId],
        ));
    }

    public function test_demoting_one_of_two_owners_succeeds(): void
    {
        $ownerId  = $this->insertUser('owner-demote-a@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $owner2Id = $this->insertUser('owner-demote-b@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $owner2Id, 'owner');

        $response = $this->createApp()->handle($this->postRole($owner2Id, 'moderator', $ownerId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('moderator', $this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $owner2Id],
        ));
    }

    public function test_moderator_cannot_change_role_returns_403(): void
    {
        $ownerId = $this->insertUser('owner-role-403@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-role-403@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postRole($modId, 'owner', $modId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_invalid_role_value_returns_422(): void
    {
        $ownerId = $this->insertUser('owner-role-invalid@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-role-invalid@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postRole($modId, 'superadmin', $ownerId));

        self::assertSame(422, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Cross-Tenant-Isolation
    // -------------------------------------------------------------------------

    public function test_owner_cannot_remove_member_of_foreign_account(): void
    {
        $accountB = $this->insertAccount(['slug' => 'acct-member-foreign', 'name' => 'Foreign Account']);
        $memberB  = $this->insertUser('member-foreign@example.com');
        $this->insertAccountMember($accountB, $memberB, 'moderator');

        $ownerA = $this->insertUser('owner-member-foreign@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerA, 'owner');

        $response = $this->createApp()->handle($this->postRemove($memberB, $ownerA));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('moderator', $this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $accountB, 'u' => $memberB],
        ), 'Foreign membership must remain untouched by account A.');
    }
}
