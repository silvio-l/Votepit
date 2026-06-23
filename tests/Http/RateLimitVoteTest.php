<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Rate-Limit-Integrationstest für den per-Action-Bucket `idea:vote` (Sprint 4, Issue 01).
 *
 * Overridet testConfig() mit einem niedrigen Schwellwert (1/Fenster), damit das
 * Limit deterministisch erreichbar ist. Beweist zugleich, dass der in AppFactory
 * nachgeschlagene Schlüssel `idea:vote` mit dem Config-Schlüssel übereinstimmt
 * (Bucket-Naming-Vereinheitlichung).
 */
final class RateLimitVoteTest extends IntegrationTestCase
{
    protected function testConfig(): Config
    {
        return Config::fromArray([
            'env'            => 'dev',
            'app_url'        => 'http://localhost:8000',
            'app_key'        => str_repeat('a', 64),
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
        // Zwei Ideen, damit nicht das zweite Vote als Rücknahme zählt.
        $userId  = $this->insertUser('rl-vote@example.com');
        $ideaA   = $this->seedIdea($boardId, $userId, 'Idee A');
        $ideaB   = $this->seedIdea($boardId, $userId, 'Idee B');

        $app = $this->createApp();

        // 1. Vote (count=1, 1 <= 1 → erlaubt → PRG 302)
        $first = $app->handle($this->postVote('rl-vote', $ideaA, 'up', $userId));
        self::assertSame(302, $first->getStatusCode());

        // 2. Vote desselben Users (count=2, 2 > 1 → 429)
        $second = $app->handle($this->postVote('rl-vote', $ideaB, 'up', $userId));
        self::assertSame(429, $second->getStatusCode());

        // Zweite Idee bekam keine Stimme.
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id', ['id' => $ideaB]);
        self::assertSame(0, $count);
    }
}
