<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Rate-limit integration test for the per-action bucket `idea:vote`.
 *
 * Overrides testConfig() with a low threshold (1/window), so the limit is
 * deterministically reachable. Also proves that the key `idea:vote`
 * looked up in AppFactory matches the config key (bucket naming unification).
 */
final class RateLimitVoteTest extends IntegrationTestCase
{
    protected function testConfig(): Config
    {
        return Config::fromArray([
            'env'            => 'dev',
            'app_url'        => 'http://localhost:8000',
            'app_key'        => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'             => ['name' => ':memory:'],
            'smtp'           => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl' => 900,
            'rate_limits'    => [
                'idea:vote' => ['limit' => 1, 'window' => 3600],
            ],
        ]);
    }

    private function postVote(string $slug, int $ideaId, string $value, int $userId): ServerRequestInterface
    {
        $csrf   = new CsrfService(str_repeat('a', 64), 3600, false);
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/vote')
            ->withCookieParams([
                $csrf->cookieName() => $signed,
                'votepit_sess'      => $this->sessionCookie($userId),
            ])
            ->withParsedBody(['_csrf' => $token, 'value' => $value]);
    }

    public function test_exceeding_vote_limit_returns_429(): void
    {
        $boardId = $this->insertBoard('rl-vote');
        // Two ideas, so the second vote doesn't count as a retraction.
        $userId  = $this->insertUser('rl-vote@example.com');
        $ideaA   = $this->seedIdea($boardId, $userId, 'Idea A');
        $ideaB   = $this->seedIdea($boardId, $userId, 'Idea B');

        $app = $this->createApp();

        // 1st vote (count=1, 1 <= 1 → allowed → JSON 200)
        $first = $app->handle($this->postVote('rl-vote', $ideaA, 'up', $userId));
        self::assertSame(200, $first->getStatusCode());

        // 2nd vote from the same user (count=2, 2 > 1 → 429)
        $second = $app->handle($this->postVote('rl-vote', $ideaB, 'up', $userId));
        self::assertSame(429, $second->getStatusCode());

        // Second idea received no vote.
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id', ['id' => $ideaB]);
        self::assertSame(0, $count);
    }

    /**
     * Test-User feature: a dedicated QA/E2E account (is_test_account) is
     * exempt from every rate limit (RateLimitMiddleware::process()) — same
     * scenario as above, but with a third vote from a test-account user that
     * would otherwise also hit the 1/window bucket.
     */
    public function test_test_account_is_exempt_from_the_vote_limit(): void
    {
        $boardId = $this->insertBoard('rl-vote-exempt');
        $userId  = $this->insertUser('rl-vote-exempt@example.com', ['is_test_account' => 1]);
        $ideaA   = $this->seedIdea($boardId, $userId, 'Idea A');
        $ideaB   = $this->seedIdea($boardId, $userId, 'Idea B');

        $app = $this->createApp();

        $first = $app->handle($this->postVote('rl-vote-exempt', $ideaA, 'up', $userId));
        self::assertSame(200, $first->getStatusCode());

        // Would be a 429 for a regular user (limit is 1/window) — the
        // test-account flag exempts it instead.
        $second = $app->handle($this->postVote('rl-vote-exempt', $ideaB, 'up', $userId));
        self::assertSame(200, $second->getStatusCode());
    }
}
