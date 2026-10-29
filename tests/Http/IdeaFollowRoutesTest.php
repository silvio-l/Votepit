<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * POST /{account}/{board}/ideas/{id}/follow — following an idea is account
 * scoped: the board must belong to the account in the path, and an idea must
 * belong to that board. Anything else is a 404, never a leak across tenants.
 */
final class IdeaFollowRoutesTest extends IntegrationTestCase
{
    private function cloudConfig(): Config
    {
        return Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud']));
    }

    private function post(string $path, ?int $userId): ServerRequestInterface
    {
        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])
            ->withHeader('X-CSRF-Token', $token)
            ->withParsedBody([]);

        return $userId === null ? $request : $request->withCookieParams([
            $csrf->cookieName() => $csrf->sign($token),
            'votepit_sess'      => $this->sessionCookie($userId),
        ]);
    }

    /** @return array{alpha: int, board_a: int, beta: int, board_b: int} */
    private function twoAccounts(): array
    {
        $alpha = $this->insertAccount(['slug' => 'alpha', 'plan' => 'pro']);
        $beta  = $this->insertAccount(['slug' => 'beta', 'plan' => 'pro']);

        return [
            'alpha'   => $alpha,
            'board_a' => $this->insertBoard('board-a', ['account_id' => $alpha]),
            'beta'    => $beta,
            'board_b' => $this->insertBoard('board-b', ['account_id' => $beta, 'visibility' => 'private']),
        ];
    }

    private function isFollowing(int $userId, int $ideaId): bool
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM idea_follows WHERE user_id = :u AND idea_id = :i',
            ['u' => $userId, 'i' => $ideaId],
        ) === 1;
    }

    public function test_a_guest_cannot_follow(): void
    {
        $ids    = $this->twoAccounts();
        $author = $this->insertUser('follow-author@example.com');
        $ideaId = $this->seedIdea($ids['board_a'], $author, 'Followable');

        $response = $this->createApp(config: $this->cloudConfig())->handle($this->post('/alpha/board-a/ideas/' . $ideaId . '/follow', null));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_user_can_follow_an_idea_on_a_public_board_of_the_path_account(): void
    {
        $ids    = $this->twoAccounts();
        $author = $this->insertUser('follow-author-ok@example.com');
        $user   = $this->insertUser('follow-user@example.com');
        $ideaId = $this->seedIdea($ids['board_a'], $author, 'Followable');

        $response = $this->createApp(config: $this->cloudConfig())->handle($this->post('/alpha/board-a/ideas/' . $ideaId . '/follow', $user));

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->isFollowing($user, $ideaId));
    }

    public function test_a_private_board_of_another_account_is_not_found_under_this_account(): void
    {
        $ids    = $this->twoAccounts();
        $author = $this->insertUser('follow-beta-author@example.com');
        $user   = $this->insertUser('follow-beta-user@example.com');
        $ideaId = $this->seedIdea($ids['board_b'], $author, 'Beta secret');

        // board-b lives in account beta; asking for it under alpha must not resolve it.
        $response = $this->createApp(config: $this->cloudConfig())->handle($this->post('/alpha/board-b/ideas/' . $ideaId . '/follow', $user));

        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($this->isFollowing($user, $ideaId));
    }

    public function test_an_idea_from_another_board_of_the_same_account_is_not_found(): void
    {
        $ids    = $this->twoAccounts();
        $second = $this->insertBoard('board-a2', ['account_id' => $ids['alpha']]);
        $author = $this->insertUser('follow-other-board@example.com');
        $user   = $this->insertUser('follow-other-board-user@example.com');
        $ideaId = $this->seedIdea($second, $author, 'Idea on the other board');

        $response = $this->createApp(config: $this->cloudConfig())->handle($this->post('/alpha/board-a/ideas/' . $ideaId . '/follow', $user));

        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($this->isFollowing($user, $ideaId));
    }

    /** @param array<string, mixed> $config overrides merged into the cloud test config */
    private function request(string $method, string $path, ?int $userId, bool $withCsrf = true, array $config = []): ServerRequestInterface
    {
        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();

        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '203.0.113.40']);
        if ($withCsrf) {
            $request = $request->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])->withHeader('X-CSRF-Token', $token);
        }
        if ($userId !== null) {
            $request = $request->withCookieParams([
                ...$request->getCookieParams(),
                'votepit_sess' => $this->sessionCookie($userId),
            ]);
        }

        return $request->withParsedBody([]);
    }

    public function test_a_guest_cannot_read_or_unfollow(): void
    {
        $ids    = $this->twoAccounts();
        $author = $this->insertUser('follow-guest-author@example.com');
        $ideaId = $this->seedIdea($ids['board_a'], $author, 'Guest-proof');

        $status = $this->createApp(config: $this->cloudConfig())->handle($this->request('GET', '/alpha/board-a/ideas/' . $ideaId . '/follow', null));
        $unfollow = $this->createApp(config: $this->cloudConfig())->handle($this->request('DELETE', '/alpha/board-a/ideas/' . $ideaId . '/follow', null));

        self::assertSame(401, $status->getStatusCode());
        self::assertSame(401, $unfollow->getStatusCode());
    }

    public function test_status_and_unfollow_of_another_accounts_board_are_not_found(): void
    {
        $ids    = $this->twoAccounts();
        $author = $this->insertUser('follow-beta-author-2@example.com');
        $user   = $this->insertUser('follow-beta-user-2@example.com');
        $ideaId = $this->seedIdea($ids['board_b'], $author, 'Beta, still secret');
        $this->conn->insert('idea_follows', ['idea_id' => $ideaId, 'user_id' => $user, 'board_id' => $ids['board_b'], 'created_at' => '2026-10-26 10:00:00']);

        $status   = $this->createApp(config: $this->cloudConfig())->handle($this->request('GET', '/alpha/board-b/ideas/' . $ideaId . '/follow', $user));
        $unfollow = $this->createApp(config: $this->cloudConfig())->handle($this->request('DELETE', '/alpha/board-b/ideas/' . $ideaId . '/follow', $user));

        self::assertSame(404, $status->getStatusCode());
        self::assertSame(404, $unfollow->getStatusCode());
        self::assertTrue($this->isFollowing($user, $ideaId), 'a foreign-account unfollow must not remove the follow row');
    }

    public function test_a_state_changing_follow_without_csrf_is_rejected_and_changes_nothing(): void
    {
        $ids    = $this->twoAccounts();
        $author = $this->insertUser('follow-csrf-author@example.com');
        $user   = $this->insertUser('follow-csrf-user@example.com');
        $ideaId = $this->seedIdea($ids['board_a'], $author, 'CSRF target');

        $response = $this->createApp(config: $this->cloudConfig())->handle($this->request('POST', '/alpha/board-a/ideas/' . $ideaId . '/follow', $user, withCsrf: false));

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->isFollowing($user, $ideaId));
    }

    public function test_reading_the_follow_state_stays_answerable_on_a_frozen_board_but_following_is_refused(): void
    {
        $ids    = $this->twoAccounts();
        $frozen = $this->insertBoard('board-frozen', ['account_id' => $ids['alpha'], 'frozen_at' => '2026-10-01 00:00:00']);
        $author = $this->insertUser('follow-frozen-author@example.com');
        $user   = $this->insertUser('follow-frozen-user@example.com');
        $ideaId = $this->seedIdea($frozen, $author, 'Frozen idea');

        $read  = $this->createApp(config: $this->cloudConfig())->handle($this->request('GET', '/alpha/board-frozen/ideas/' . $ideaId . '/follow', $user));
        $write = $this->createApp(config: $this->cloudConfig())->handle($this->request('POST', '/alpha/board-frozen/ideas/' . $ideaId . '/follow', $user));

        self::assertSame(200, $read->getStatusCode());
        self::assertSame(423, $write->getStatusCode());
        self::assertFalse($this->isFollowing($user, $ideaId));
    }

    public function test_follow_writes_are_rate_limited_per_user(): void
    {
        $ids    = $this->twoAccounts();
        $author = $this->insertUser('follow-limit-author@example.com');
        $user   = $this->insertUser('follow-limit-user@example.com');
        $ideaId = $this->seedIdea($ids['board_a'], $author, 'Rate limited');
        $app    = $this->createApp(config: Config::fromArray($this->testConfigArray([
            'routing_mode' => 'cloud',
            'rate_limits'  => ['idea:follow' => ['limit' => 2, 'window' => 3600]],
        ])));

        $path = '/alpha/board-a/ideas/' . $ideaId . '/follow';
        self::assertSame(200, $app->handle($this->request('POST', $path, $user))->getStatusCode());
        self::assertSame(200, $app->handle($this->request('DELETE', $path, $user))->getStatusCode());
        self::assertSame(429, $app->handle($this->request('POST', $path, $user))->getStatusCode());
        self::assertSame(200, $app->handle($this->request('GET', $path, $user))->getStatusCode(), 'reads are not counted');
    }
}
