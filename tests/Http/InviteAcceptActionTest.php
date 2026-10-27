<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /invite/accept?token=….
 *
 * AC coverage:
 *   - Valid token + logged-in target user → 200, an account_members row with
 *     role=moderator is created, token is consumed (no double accept).
 *   - Expired token → 400, no mutation.
 *   - Already consumed token (double accept) → 400, no second membership.
 *   - Foreign session (logged in as someone other than the invitee) → 403,
 *     no mutation.
 *   - anon → 401.
 */
final class InviteAcceptActionTest extends IntegrationTestCase
{
    private function vault(): TokenVault
    {
        return new TokenVault();
    }

    private function getAccept(string $token, ?int $actingUserId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invite/accept?token=' . $token);

        if ($actingUserId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($actingUserId)]);
        }

        return $request;
    }

    public function test_invited_user_accepts_and_becomes_moderator(): void
    {
        $ownerId   = $this->insertUser('owner-accept@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $invitedId = $this->insertUser('invited-accept@example.com');

        $pair = $this->vault()->generate();
        $this->insertInvite($this->defaultAccountId(), $invitedId, $ownerId, $pair['hash']);

        $response = $this->createApp()->handle($this->getAccept($pair['token'], $invitedId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        self::assertSame('moderator', $data['role'] ?? null);
        self::assertSame($this->defaultAccountSlug(), $data['account_slug'] ?? null);

        $role = $this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $invitedId],
        );
        self::assertSame('moderator', $role);

        $usedAt = $this->conn->fetchOne(
            'SELECT used_at FROM invites WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $invitedId],
        );
        self::assertNotNull($usedAt);
    }

    public function test_expired_token_returns_400_and_no_membership(): void
    {
        $ownerId   = $this->insertUser('owner-expired@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $invitedId = $this->insertUser('invited-expired@example.com');

        $pair = $this->vault()->generate();
        $this->insertInvite($this->defaultAccountId(), $invitedId, $ownerId, $pair['hash'], [
            'expires_at' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
        ]);

        $response = $this->createApp()->handle($this->getAccept($pair['token'], $invitedId));

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $invitedId],
        ));
    }

    public function test_immediate_second_accept_succeeds_idempotently_within_grace_window(): void
    {
        // Mail security gateway prescanning compensation (2026-08-31, see
        // InviteAcceptAction::REPLAY_GRACE_SECONDS): a prescan followed by the
        // real click seconds later must not fail with "invalid link".
        $ownerId   = $this->insertUser('owner-double@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $invitedId = $this->insertUser('invited-double@example.com');

        $pair = $this->vault()->generate();
        $this->insertInvite($this->defaultAccountId(), $invitedId, $ownerId, $pair['hash']);

        $app = $this->createApp();
        $first  = $app->handle($this->getAccept($pair['token'], $invitedId));
        $second = $app->handle($this->getAccept($pair['token'], $invitedId));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());

        $role = $this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $invitedId],
        );
        self::assertSame('moderator', $role);
    }

    public function test_accept_after_grace_window_is_rejected(): void
    {
        $ownerId   = $this->insertUser('owner-stale@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $invitedId = $this->insertUser('invited-stale@example.com');

        $pair = $this->vault()->generate();
        // used_at already 3 minutes in the past (grace window:
        // InviteAcceptAction::REPLAY_GRACE_SECONDS = 120s) — simulates an
        // invite that was already accepted long before this request.
        $this->insertInvite($this->defaultAccountId(), $invitedId, $ownerId, $pair['hash'], [
            'used_at' => (new \DateTimeImmutable('-3 minutes'))->format('Y-m-d H:i:s'),
        ]);

        $response = $this->createApp()->handle($this->getAccept($pair['token'], $invitedId));

        self::assertSame(400, $response->getStatusCode());
    }

    public function test_wrong_session_user_gets_403_and_no_mutation(): void
    {
        $ownerId    = $this->insertUser('owner-mismatch@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $invitedId  = $this->insertUser('invited-mismatch@example.com');
        $strangerId = $this->insertUser('stranger-mismatch@example.com');

        $pair = $this->vault()->generate();
        $this->insertInvite($this->defaultAccountId(), $invitedId, $ownerId, $pair['hash']);

        $response = $this->createApp()->handle($this->getAccept($pair['token'], $strangerId));

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $invitedId],
        ));
        self::assertNull($this->conn->fetchOne(
            'SELECT used_at FROM invites WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $invitedId],
        ));
    }

    public function test_anon_accept_returns_401(): void
    {
        $ownerId   = $this->insertUser('owner-anon-accept@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $invitedId = $this->insertUser('invited-anon-accept@example.com');

        $pair = $this->vault()->generate();
        $this->insertInvite($this->defaultAccountId(), $invitedId, $ownerId, $pair['hash']);

        $response = $this->createApp()->handle($this->getAccept($pair['token'], null));

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * OWASP/BSI audit remediation issue 18 (business-logic locking): two
     * already-sent, still-open invites racing for the LAST team-size slot
     * must not both be accepted — InviteAction's send-time check alone
     * can't prevent this (sending an invite doesn't consume a member slot;
     * see InviteAcceptAction's class doc). Simulated as two immediate,
     * back-to-back accept requests against the same app/DB connection
     * (same pattern as test_immediate_second_accept_succeeds_idempotently_
     * within_grace_window above) — AccountMemberRepository::
     * addMemberWithinLimit()'s locked re-check must see the first accept's
     * committed INSERT and reject the second.
     */
    public function test_two_invites_racing_for_the_last_team_slot_only_one_is_accepted(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite'); // member_limit = 5
        $ownerId = $this->insertUser('owner-race-invite@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        for ($i = 1; $i <= 3; $i++) {
            $memberId = $this->insertUser("race-existing-member-{$i}@example.com");
            $this->insertAccountMember($this->defaultAccountId(), $memberId, 'moderator');
        }
        // 4 members so far, 1 slot left — two open invites both racing for it.
        $invitedA = $this->insertUser('invited-race-a@example.com');
        $invitedB = $this->insertUser('invited-race-b@example.com');
        $pairA    = $this->vault()->generate();
        $pairB    = $this->vault()->generate();
        $this->insertInvite($this->defaultAccountId(), $invitedA, $ownerId, $pairA['hash']);
        $this->insertInvite($this->defaultAccountId(), $invitedB, $ownerId, $pairB['hash']);

        $app    = $this->createApp();
        $first  = $app->handle($this->getAccept($pairA['token'], $invitedA));
        $second = $app->handle($this->getAccept($pairB['token'], $invitedB));

        $statuses = [$first->getStatusCode(), $second->getStatusCode()];
        sort($statuses);
        self::assertSame([200, 422], $statuses);

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM account_members WHERE account_id = :a',
            ['a' => $this->defaultAccountId()],
        );
        self::assertSame(5, $count);

        $secondData = json_decode((string) $second->getBody(), true);
        if ($second->getStatusCode() === 422) {
            self::assertSame('plan_limit_team', $secondData['error']['key'] ?? null);
            // Left unconsumed — the invited user could retry once a slot opens.
            self::assertNull($this->conn->fetchOne(
                'SELECT used_at FROM invites WHERE account_id = :a AND user_id = :u',
                ['a' => $this->defaultAccountId(), 'u' => $invitedB],
            ));
        }
    }

    /**
     * Regression (2026-09-30): the operator invited a new user into the
     * operator's own account (stored plan with member_limit 1). Sending passed
     * because the operator's request resolved to the top plan, but accepting
     * resolved the plan against the invitee (not an operator) and was
     * rejected with plan_limit_team, which the SPA shows as "expired". The
     * operator-owned-account bypass is a property of the account, so it must
     * apply to whoever accepts.
     */
    public function test_invitee_can_join_an_operator_owned_account_on_a_single_member_plan(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free'); // member_limit = 1
        $operatorId = $this->insertUser('operator-owner@example.com', ['is_operator' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $operatorId, 'owner');
        $invitedId = $this->insertUser('invited-operator-account@example.com');

        $pair = $this->vault()->generate();
        $this->insertInvite($this->defaultAccountId(), $invitedId, $operatorId, $pair['hash']);

        $response = $this->createApp()->handle($this->getAccept($pair['token'], $invitedId));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNotFalse($this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $invitedId],
        ));
    }

    public function test_team_limit_still_applies_to_accounts_without_an_operator_member(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free'); // member_limit = 1
        $ownerId = $this->insertUser('owner-starter@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $invitedId = $this->insertUser('invited-starter@example.com');

        $pair = $this->vault()->generate();
        $this->insertInvite($this->defaultAccountId(), $invitedId, $ownerId, $pair['hash']);

        $response = $this->createApp()->handle($this->getAccept($pair['token'], $invitedId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('plan_limit_team', $data['error']['key'] ?? null);
    }
}
