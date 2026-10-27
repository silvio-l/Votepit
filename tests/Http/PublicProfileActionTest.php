<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\IdentityHasher;
use Votepit\Security\PublicIdGenerator;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET {account}/members/{userId}/profile
 * (profile-visibility feature). The default seeded account routes with an
 * empty prefix (self-host mode), so the tested path is plain
 * /members/{userId}/profile.
 */
final class PublicProfileActionTest extends IntegrationTestCase
{
    /** @param array<string, mixed> $overrides */
    private function seedUser(string $email, array $overrides = []): int
    {
        $this->conn->insert('users', array_merge([
            'public_id'     => PublicIdGenerator::generate(),
            'email_hmac'    => (new IdentityHasher(self::identityServerKey()))->hash($email),
            'is_admin'      => 0,
            'is_blocked'    => 0,
            'token_version' => 0,
            'verified_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    private function getProfile(int $userId): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', "/members/{$userId}/profile");
    }

    public function test_visible_profile_with_username_exposes_it(): void
    {
        $userId = $this->seedUser('visibleuser1@example.com', [
            'profile_visible' => 1,
            'username'        => 'maxmustermann',
            'username_lower'  => 'maxmustermann',
        ]);

        $response = $this->createApp()->handle($this->getProfile($userId));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['visible']);
        self::assertSame('maxmustermann', $data['username']);
    }

    public function test_visible_profile_without_username_returns_null(): void
    {
        $userId   = $this->seedUser('visibleuser2@example.com', ['profile_visible' => 1]);
        $response = $this->createApp()->handle($this->getProfile($userId));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertTrue($data['visible']);
        self::assertNull($data['username']);
    }

    public function test_anonymous_profile_never_exposes_username(): void
    {
        $userId = $this->seedUser('anonuser1@example.com', [
            'profile_visible' => 0,
            'username'        => 'maxmustermann',
            'username_lower'  => 'maxmustermann',
        ]);

        $response = $this->createApp()->handle($this->getProfile($userId));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertFalse($data['visible']);
        self::assertArrayNotHasKey('username', $data);
        self::assertArrayNotHasKey('avatar_url', $data);
    }

    public function test_unknown_user_returns_404(): void
    {
        $response = $this->createApp()->handle($this->getProfile(999999));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_visible_profile_with_zero_activity_exposes_zero_counts(): void
    {
        $userId = $this->seedUser('stats-zero@example.com', ['profile_visible' => 1]);

        $response = $this->createApp()->handle($this->getProfile($userId));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertTrue($data['visible']);
        self::assertSame(0, $data['ideas_submitted']);
        self::assertSame(0, $data['ideas_shipped']);
        self::assertSame(0, $data['votes_cast']);
    }

    public function test_visible_profile_exposes_contribution_stats_scoped_to_the_account(): void
    {
        $accountId = $this->defaultAccountId();
        $boardId   = $this->insertBoard('profile-stats-board', ['account_id' => $accountId]);
        $userId    = $this->seedUser('stats-user@example.com', ['profile_visible' => 1]);
        $voter     = $this->insertUser('stats-voter@example.com');

        $this->seedIdea($boardId, $userId, 'Open idea', ['status' => 'open']);
        $doneIdea = $this->seedIdea($boardId, $userId, 'Done idea', ['status' => 'done']);
        $this->seedVote($doneIdea, $userId, 1);
        $this->seedVote($doneIdea, $voter, 1);

        $response = $this->createApp()->handle($this->getProfile($userId));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertSame(2, $data['ideas_submitted']);
        self::assertSame(1, $data['ideas_shipped']);
        self::assertSame(1, $data['votes_cast']);
    }

    public function test_anonymous_profile_does_not_leak_bare_contribution_numbers(): void
    {
        $accountId = $this->defaultAccountId();
        $boardId   = $this->insertBoard('profile-stats-hidden-board', ['account_id' => $accountId]);
        $userId    = $this->seedUser('stats-hidden@example.com', ['profile_visible' => 0]);

        $this->seedIdea($boardId, $userId, 'Some idea', ['status' => 'done']);

        $response = $this->createApp()->handle($this->getProfile($userId));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertFalse($data['visible']);
        self::assertArrayNotHasKey('ideas_submitted', $data);
        self::assertArrayNotHasKey('ideas_shipped', $data);
        self::assertArrayNotHasKey('votes_cast', $data);
    }

    /**
     * Cross-tenant invariant (repo-wide security-critical, see CLAUDE.md §🔒):
     * a user's activity in a DIFFERENT account must never be counted when
     * their profile is fetched via account A's route.
     */
    public function test_contribution_stats_do_not_leak_across_accounts(): void
    {
        $accountA = $this->defaultAccountId();
        $accountB = $this->insertAccount();
        $boardA   = $this->insertBoard('profile-stats-cross-a', ['account_id' => $accountA]);
        $boardB   = $this->insertBoard('profile-stats-cross-b', ['account_id' => $accountB]);
        $userId   = $this->seedUser('stats-cross-tenant@example.com', ['profile_visible' => 1]);

        // Activity in account A: one idea, no votes.
        $this->seedIdea($boardA, $userId, 'Idea in account A');

        // Activity in account B (must not be visible via account A's route).
        $ideaB1 = $this->seedIdea($boardB, $userId, 'Idea in account B one', ['status' => 'done']);
        $this->seedIdea($boardB, $userId, 'Idea in account B two', ['status' => 'done']);
        $this->seedVote($ideaB1, $userId, 1);

        $response = $this->createApp()->handle($this->getProfile($userId));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertSame(1, $data['ideas_submitted']);
        self::assertSame(0, $data['ideas_shipped']);
        self::assertSame(0, $data['votes_cast']);
    }
}
