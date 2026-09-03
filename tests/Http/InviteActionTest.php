<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /admin/invites and POST /admin/invites/{id}/revoke.
 *
 * AC coverage:
 *   - Owner can invite by email → 200, invites row + mail sent.
 *   - Moderator (non-owner) → 403 (invite is owner-only).
 *   - anon → 401.
 *   - Invalid/empty email → 422.
 *   - Already a member → 422.
 *   - Self-invite → 422.
 *   - Re-invite invalidates the previous open token (no accumulation).
 *   - Revoke: owner revokes a pending invite → 200, accept no longer
 *     possible afterwards; foreign account/unknown ID → 404; moderator → 403.
 *   - Cross-tenant isolation: invite in account A does not touch account B.
 */
final class InviteActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postInvite(string $email, ?int $actingUserId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($actingUserId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($actingUserId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/invites')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'email' => $email]);
    }

    private function postRevoke(int $inviteId, ?int $actingUserId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($actingUserId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($actingUserId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', "/admin/invites/{$inviteId}/revoke")
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token]);
    }

    // -------------------------------------------------------------------------
    // Owner invites by email → 200, invites row persisted, mail sent
    // -------------------------------------------------------------------------

    public function test_owner_invites_by_email_returns_200_and_persists_invite(): void
    {
        $ownerId = $this->insertUser('owner-invite@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $mailer   = new InMemoryMailer();
        $response = $this->createApp($mailer)->handle($this->postInvite('newbie@example.com', $ownerId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);

        $count = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM invites WHERE account_id = :a AND used_at IS NULL AND revoked_at IS NULL",
            ['a' => $this->defaultAccountId()],
        );
        self::assertSame(1, $count);

        self::assertSame(1, $mailer->count());
        $sent = $mailer->lastSent();
        self::assertNotNull($sent);
        self::assertSame('newbie@example.com', $sent['to']);
        self::assertStringContainsString('/invite/accept?token=', $sent['body']);
        self::assertStringContainsString('The link is valid for 7 days.', $sent['body']);

        // Multipart: HTML part with the same accept link + validity notice.
        self::assertIsString($sent['html']);
        self::assertStringContainsString('/invite/accept?token=', $sent['html']);
        self::assertStringContainsString('The link is valid for 7 days.', $sent['html']);
    }

    // -------------------------------------------------------------------------
    // Moderator (non-owner) → 403 (invite is owner-only)
    // -------------------------------------------------------------------------

    public function test_moderator_cannot_send_invite_returns_403(): void
    {
        $modId = $this->insertUser('mod-invite@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postInvite('newbie2@example.com', $modId));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM invites'));
    }

    // -------------------------------------------------------------------------
    // anon → 401
    // -------------------------------------------------------------------------

    public function test_anon_invite_request_returns_401(): void
    {
        $response = $this->createApp()->handle($this->postInvite('newbie3@example.com', null));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM invites'));
    }

    // -------------------------------------------------------------------------
    // Invalid email → 422
    // -------------------------------------------------------------------------

    public function test_invalid_email_returns_422(): void
    {
        $ownerId = $this->insertUser('owner-invalid@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postInvite('not-an-email', $ownerId));

        self::assertSame(422, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Already a member → 422
    // -------------------------------------------------------------------------

    public function test_inviting_existing_member_returns_422(): void
    {
        $ownerId = $this->insertUser('owner-already@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $this->insertUser('existing-member@example.com');
        $this->insertAccountMember(
            $this->defaultAccountId(),
            (int) $this->conn->fetchOne(
                'SELECT id FROM users WHERE email_hmac = :h',
                ['h' => (new \Votepit\Security\IdentityHasher(self::identityServerKey()))->hash('existing-member@example.com')],
            ),
            'moderator',
        );

        $response = $this->createApp()->handle($this->postInvite('existing-member@example.com', $ownerId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('already_member', $data['error']['key'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Self-invite → 422
    // -------------------------------------------------------------------------

    public function test_inviting_self_returns_422(): void
    {
        $ownerId = $this->insertUser('owner-self@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postInvite('owner-self@example.com', $ownerId));

        self::assertSame(422, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Re-invite invalidates the previous open token
    // -------------------------------------------------------------------------

    public function test_re_invite_replaces_previous_open_invite(): void
    {
        $ownerId = $this->insertUser('owner-reinvite@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $app = $this->createApp();
        $app->handle($this->postInvite('reinvited@example.com', $ownerId));
        $app->handle($this->postInvite('reinvited@example.com', $ownerId));

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM invites WHERE account_id = :a AND used_at IS NULL AND revoked_at IS NULL',
            ['a' => $this->defaultAccountId()],
        );
        self::assertSame(1, $count, 'Re-invite must not accumulate — only one open token per (account, user).');
    }

    // -------------------------------------------------------------------------
    // Revoke
    // -------------------------------------------------------------------------

    public function test_owner_revokes_pending_invite_returns_200(): void
    {
        $ownerId = $this->insertUser('owner-revoke@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $app = $this->createApp();
        $app->handle($this->postInvite('revokee@example.com', $ownerId));
        $inviteId = (int) $this->conn->fetchOne('SELECT id FROM invites ORDER BY id DESC LIMIT 1');

        $response = $app->handle($this->postRevoke($inviteId, $ownerId));

        self::assertSame(200, $response->getStatusCode());
        $revokedAt = $this->conn->fetchOne('SELECT revoked_at FROM invites WHERE id = :id', ['id' => $inviteId]);
        self::assertNotNull($revokedAt);
    }

    public function test_moderator_cannot_revoke_invite_returns_403(): void
    {
        $ownerId = $this->insertUser('owner-revoke-403@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-revoke-403@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $app = $this->createApp();
        $app->handle($this->postInvite('revokee-403@example.com', $ownerId));
        $inviteId = (int) $this->conn->fetchOne('SELECT id FROM invites ORDER BY id DESC LIMIT 1');

        $response = $app->handle($this->postRevoke($inviteId, $modId));

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($this->conn->fetchOne('SELECT revoked_at FROM invites WHERE id = :id', ['id' => $inviteId]));
    }

    public function test_revoking_unknown_invite_returns_404(): void
    {
        $ownerId = $this->insertUser('owner-revoke-404@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postRevoke(999999, $ownerId));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Cross-tenant isolation: foreign invite ID (different account) → 404, no leak
    // -------------------------------------------------------------------------

    public function test_owner_cannot_revoke_invite_of_foreign_account(): void
    {
        $accountB = $this->insertAccount(['slug' => 'acct-invite-foreign', 'name' => 'Foreign Account']);
        $ownerB   = $this->insertUser('owner-foreign-invite@example.com');
        $this->insertAccountMember($accountB, $ownerB, 'owner');
        $invitedB = $this->insertUser('invited-foreign@example.com');
        $foreignInviteId = $this->insertInvite($accountB, $invitedB, $ownerB, str_repeat('a', 64));

        $ownerA = $this->insertUser('owner-default-invite@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerA, 'owner');

        $response = $this->createApp()->handle($this->postRevoke($foreignInviteId, $ownerA));

        self::assertSame(404, $response->getStatusCode());
        self::assertNull($this->conn->fetchOne('SELECT revoked_at FROM invites WHERE id = :id', ['id' => $foreignInviteId]));
    }

    // -------------------------------------------------------------------------
    // Audit-Log
    // -------------------------------------------------------------------------

    public function test_audit_log_contains_invite_sent_event(): void
    {
        $ownerId = $this->insertUser('owner-audit-invite@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $this->createApp()->handle($this->postInvite('audited@example.com', $ownerId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('invite.sent', $log);
        self::assertStringNotContainsString('audited@example.com', $log, 'Email must not appear in the log.');
    }

    // =========================================================================
    // Tier enforcement: team-size plan limit
    // =========================================================================

    public function test_free_plan_rejects_any_invite(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'starter');
        $ownerId = $this->insertUser('owner-free-invite@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner'); // already at member_limit=1

        $response = $this->createApp()->handle($this->postInvite('blocked@example.com', $ownerId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('plan_limit_team', $data['error']['key'] ?? null);
        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM invites WHERE account_id = :a',
            ['a' => $this->defaultAccountId()],
        );
        self::assertSame(0, $count);
    }

    public function test_lite_plan_blocks_invite_past_team_cap(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'team');
        $ownerId = $this->insertUser('owner-lite-invite@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        // 4 more moderators → 5 members total, Lite's team cap.
        for ($i = 1; $i <= 4; $i++) {
            $memberId = $this->insertUser("lite-member-{$i}@example.com");
            $this->insertAccountMember($this->defaultAccountId(), $memberId, 'moderator');
        }

        $response = $this->createApp()->handle($this->postInvite('one-too-many@example.com', $ownerId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('plan_limit_team', $data['error']['key'] ?? null);
    }

    public function test_pro_plan_allows_invite_past_lite_cap(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'business');
        $ownerId = $this->insertUser('owner-pro-invite@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        for ($i = 1; $i <= 4; $i++) {
            $memberId = $this->insertUser("pro-member-{$i}@example.com");
            $this->insertAccountMember($this->defaultAccountId(), $memberId, 'moderator');
        }

        $response = $this->createApp()->handle($this->postInvite('room-for-more@example.com', $ownerId));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_unknown_plan_rejects_invite(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'not-a-real-plan');
        $ownerId = $this->insertUser('owner-unknown-plan-invite@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postInvite('denied@example.com', $ownerId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('plan_limit_team', $data['error']['key'] ?? null);
    }
}
