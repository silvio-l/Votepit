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
}
