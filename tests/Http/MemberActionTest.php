<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /admin/members, POST /admin/members/{id}/remove
 * and POST /admin/members/{id}/role.
 *
 * AC coverage:
 *   - Owner + admin can read the list (accountAdmin); moderator + anon → 403/401.
 *   - Only owner may remove/change role (accountOwner) — admin + moderator → 403.
 *   - The account's owner is never returned in the list, can never be removed,
 *     and can never have their role changed → 422 (exactly one owner, always).
 *   - changeRole only accepts 'admin'/'moderator' as a target — 'owner' → 422.
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

    private function postPasswordReset(string $email, ?int $actingUserId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($actingUserId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($actingUserId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/members/password-reset')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'email' => $email]);
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
    // GET /admin/members — owner + admin may read; moderator + anon → 403/401
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
        // The owner's own row is never included in the members list.
        self::assertCount(1, $data['members']);
        self::assertSame($modId, $data['members'][0]['user_id']);
        self::assertCount(1, $data['invites']);
    }

    public function test_admin_can_also_list_members(): void
    {
        $ownerId = $this->insertUser('owner-admin-view@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $adminId = $this->insertUser('admin-view@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'admin');

        $response = $this->createApp()->handle($this->getMembers($adminId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('admin', $data['viewer_role'] ?? null);
    }

    public function test_moderator_cannot_list_members_returns_403(): void
    {
        $ownerId = $this->insertUser('owner-mod-view@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-view@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->getMembers($modId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_anon_list_returns_401(): void
    {
        $response = $this->createApp()->handle($this->getMembers(null));

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Remove — owner-only, the account's owner is protected
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

    public function test_admin_cannot_remove_member_returns_403(): void
    {
        $ownerId = $this->insertUser('owner-remove-admin-403@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $adminId = $this->insertUser('admin-remove-403@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'admin');
        $modId = $this->insertUser('mod-remove-admin-403-target@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postRemove($modId, $adminId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_removing_the_owner_returns_422(): void
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
    // Role change — owner-only, 'owner' is neither an accepted target nor
    // ever the current role of a member that can be re-roled.
    // -------------------------------------------------------------------------

    public function test_owner_promotes_moderator_to_admin(): void
    {
        $ownerId = $this->insertUser('owner-promote@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-promote@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postRole($modId, 'admin', $ownerId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('admin', $this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $modId],
        ));
    }

    public function test_owner_demotes_moderator_to_plain_member(): void
    {
        $ownerId = $this->insertUser('owner-demote-to-member@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-demote-to-member@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postRole($modId, 'member', $ownerId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('member', $this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $modId],
        ));
    }

    public function test_promoting_a_member_to_owner_returns_422_invalid_input(): void
    {
        $ownerId = $this->insertUser('owner-promote-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-promote-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postRole($modId, 'owner', $ownerId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('moderator', $this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $modId],
        ));
    }

    public function test_re_roling_the_owner_returns_422(): void
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

    public function test_re_roling_one_of_two_owners_still_returns_422(): void
    {
        $ownerId  = $this->insertUser('owner-demote-a@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $owner2Id = $this->insertUser('owner-demote-b@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $owner2Id, 'owner');

        $response = $this->createApp()->handle($this->postRole($owner2Id, 'moderator', $ownerId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('owner', $this->conn->fetchOne(
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

        $response = $this->createApp()->handle($this->postRole($modId, 'admin', $modId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_admin_cannot_change_role_returns_403(): void
    {
        $ownerId = $this->insertUser('owner-role-admin-403@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $adminId = $this->insertUser('admin-role-403@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'admin');
        $modId = $this->insertUser('mod-role-admin-403-target@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postRole($modId, 'admin', $adminId));

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
    // Password reset — accountAdmin (owner + admin), by re-typed email
    // -------------------------------------------------------------------------

    public function test_owner_triggers_a_password_reset_for_a_member(): void
    {
        $mailer  = new InMemoryMailer();
        $app     = $this->createApp($mailer);
        $ownerId = $this->insertUser('owner-pwreset@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-pwreset@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $app->handle($this->postPasswordReset('mod-pwreset@example.com', $ownerId));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $mailer->sent);
        self::assertSame('mod-pwreset@example.com', $mailer->sent[0]['to']);
    }

    public function test_admin_can_also_trigger_a_password_reset_for_a_member(): void
    {
        $mailer  = new InMemoryMailer();
        $app     = $this->createApp($mailer);
        $ownerId = $this->insertUser('owner-admin-pwreset@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $adminId = $this->insertUser('admin-pwreset@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'admin');
        $modId = $this->insertUser('mod-admin-pwreset@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $app->handle($this->postPasswordReset('mod-admin-pwreset@example.com', $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $mailer->sent);
    }

    public function test_moderator_cannot_trigger_a_password_reset_returns_403(): void
    {
        $mailer  = new InMemoryMailer();
        $app     = $this->createApp($mailer);
        $ownerId = $this->insertUser('owner-pwreset-403@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-pwreset-403@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $app->handle($this->postPasswordReset('owner-pwreset-403@example.com', $modId));

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, $mailer->sent);
    }

    public function test_password_reset_for_an_unknown_email_returns_404(): void
    {
        $mailer  = new InMemoryMailer();
        $app     = $this->createApp($mailer);
        $ownerId = $this->insertUser('owner-pwreset-404@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $app->handle($this->postPasswordReset('nobody@example.com', $ownerId));

        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, $mailer->sent);
    }

    public function test_password_reset_for_a_member_of_a_foreign_account_returns_404(): void
    {
        $mailer   = new InMemoryMailer();
        $app      = $this->createApp($mailer);
        $accountB = $this->insertAccount(['slug' => 'acct-member-pwreset-foreign', 'name' => 'Foreign Account']);
        $memberB  = $this->insertUser('member-pwreset-foreign@example.com');
        $this->insertAccountMember($accountB, $memberB, 'moderator');

        $ownerA = $this->insertUser('owner-pwreset-foreign@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerA, 'owner');

        $response = $app->handle($this->postPasswordReset('member-pwreset-foreign@example.com', $ownerA));

        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, $mailer->sent);
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
