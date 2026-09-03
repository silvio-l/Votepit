<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\IdentityHasher;
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
}
