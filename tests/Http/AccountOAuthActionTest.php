<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\PublicIdGenerator;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the account-settings "linked OAuth providers" view
 * (list + unlink) — AccountOAuthAction. Booted over AppFactory with
 * SQLite-in-memory (IntegrationTestCase).
 */
final class AccountOAuthActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function seedUser(string $email): int
    {
        $this->conn->insert('users', [
            'public_id'     => PublicIdGenerator::generate(),
            'email_hmac'    => (new IdentityHasher(self::identityServerKey()))->hash($email),
            'is_admin'      => 0,
            'is_blocked'    => 0,
            'token_version' => 0,
            'verified_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->lastInsertId();
    }

    private function linkProvider(int $userId, string $provider, string $providerUserId): void
    {
        $this->conn->insert('oauth_identities', [
            'user_id'          => $userId,
            'provider'         => $provider,
            'provider_user_id' => $providerUserId,
            'created_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function sessions(): SessionService
    {
        return new SessionService(self::APP_KEY, 3600, false);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(self::APP_KEY, 3600, false);
    }

    private function authedRequest(string $method, string $uri, ?int $userId, bool $withCsrf = true): ServerRequestInterface
    {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();

        $cookies = [];
        $headers = [];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);
        }
        if ($withCsrf) {
            $cookies['votepit_csrf'] = $csrf->sign($csrfToken);
            $headers['X-CSRF-Token'] = $csrfToken;
        }

        $request = (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withCookieParams($cookies);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    // -------------------------------------------------------------------------
    // AuthZ: user required
    // -------------------------------------------------------------------------

    public function test_list_as_anon_is_rejected(): void
    {
        $response = $this->createApp()->handle($this->authedRequest('GET', '/account/oauth-identities', null));
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_unlink_as_anon_is_rejected(): void
    {
        $response = $this->createApp()->handle(
            $this->authedRequest('DELETE', '/account/oauth-identities/google', null),
        );
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_unlink_without_csrf_is_rejected(): void
    {
        $userId   = $this->seedUser('user1@example.com');
        $response = $this->createApp()->handle(
            $this->authedRequest('DELETE', '/account/oauth-identities/google', $userId, withCsrf: false),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    public function test_list_returns_empty_when_nothing_linked(): void
    {
        $userId   = $this->seedUser('user2@example.com');
        $response = $this->createApp()->handle($this->authedRequest('GET', '/account/oauth-identities', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([], $data['identities']);
    }

    public function test_list_returns_linked_providers_without_leaking_provider_user_id(): void
    {
        $userId = $this->seedUser('user3@example.com');
        $this->linkProvider($userId, 'google', 'google-subject-123');

        $response = $this->createApp()->handle($this->authedRequest('GET', '/account/oauth-identities', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $data['identities']);
        self::assertSame('google', $data['identities'][0]['provider']);
        self::assertArrayNotHasKey('provider_user_id', $data['identities'][0]);
        self::assertArrayHasKey('created_at', $data['identities'][0]);
    }

    public function test_list_never_returns_another_users_linked_providers(): void
    {
        $userA = $this->seedUser('user4a@example.com');
        $userB = $this->seedUser('user4b@example.com');
        $this->linkProvider($userB, 'github', 'github-subject-456');

        $response = $this->createApp()->handle($this->authedRequest('GET', '/account/oauth-identities', $userA));

        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([], $data['identities']);
    }

    // -------------------------------------------------------------------------
    // Unlink
    // -------------------------------------------------------------------------

    public function test_unlink_removes_the_identity(): void
    {
        $userId = $this->seedUser('user5@example.com');
        $this->linkProvider($userId, 'github', 'github-subject-789');

        $response = $this->createApp()->handle(
            $this->authedRequest('DELETE', '/account/oauth-identities/github', $userId),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok']);

        $remaining = $this->conn->fetchAllAssociative(
            'SELECT * FROM oauth_identities WHERE user_id = :id',
            ['id' => $userId],
        );
        self::assertSame([], $remaining);
    }

    public function test_unlink_is_idempotent_when_not_linked(): void
    {
        $userId   = $this->seedUser('user6@example.com');
        $response = $this->createApp()->handle(
            $this->authedRequest('DELETE', '/account/oauth-identities/google', $userId),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok']);
    }

    public function test_unlink_unknown_provider_is_rejected_with_404(): void
    {
        $userId   = $this->seedUser('user7@example.com');
        $response = $this->createApp()->handle(
            $this->authedRequest('DELETE', '/account/oauth-identities/facebook', $userId),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_unlink_only_removes_the_targeted_users_identity(): void
    {
        $userA = $this->seedUser('user8a@example.com');
        $userB = $this->seedUser('user8b@example.com');
        $this->linkProvider($userA, 'google', 'google-subject-a');
        $this->linkProvider($userB, 'google', 'google-subject-b');

        $response = $this->createApp()->handle(
            $this->authedRequest('DELETE', '/account/oauth-identities/google', $userA),
        );
        self::assertSame(200, $response->getStatusCode());

        $stillLinked = $this->conn->fetchOne(
            'SELECT provider FROM oauth_identities WHERE user_id = :id',
            ['id' => $userB],
        );
        self::assertSame('google', $stillLinked);
    }
}
