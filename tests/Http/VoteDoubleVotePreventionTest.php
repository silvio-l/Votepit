<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Double-vote-prevention follow-up (ADR 0004), found via live testing: on a
 * board with anonymous_voting_enabled, the same physical person could vote
 * once while authenticated (keyed by votes.user_id) and once more anonymous
 * (keyed by votes.voter_token_hash) — two structurally independent
 * identities, so both counted. Covers both orders: authenticated-then-
 * anonymous (the reported scenario) and anonymous-then-authenticated.
 */
final class VoteDoubleVotePreventionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postVote(string $slug, int $ideaId, string $value, ?int $userId, ?string $anonCookie = null): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }
        if ($anonCookie !== null) {
            $cookies['vp_anon_voter'] = $anonCookie;
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/vote')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'value' => $value]);
    }

    private function extractAnonCookie(ResponseInterface $response): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            [$pair] = explode(';', $header, 2);
            [$name, $value] = explode('=', $pair, 2);
            if ($name === 'vp_anon_voter') {
                return $value;
            }
        }

        return null;
    }

    private function rowCount(int $ideaId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id', ['id' => $ideaId]);
    }

    private function scoreCache(int $ideaId): int
    {
        return (int) $this->conn->fetchOne('SELECT score_cache FROM ideas WHERE id = :id', ['id' => $ideaId]);
    }

    /**
     * Denormalized up/down counters (migration 0072) must match the votes
     * rows after every identity-link path, exactly like score_cache.
     */
    private function assertCountersMatchVotes(int $ideaId, int $expectedUp, int $expectedDown): void
    {
        $row = $this->conn->fetchAssociative('SELECT up_count, down_count FROM ideas WHERE id = :id', ['id' => $ideaId]);
        self::assertIsArray($row);
        self::assertSame([$expectedUp, $expectedDown], [(int) $row['up_count'], (int) $row['down_count']]);
        self::assertSame([
            (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id AND value > 0', ['id' => $ideaId]),
            (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id AND value < 0', ['id' => $ideaId]),
        ], [(int) $row['up_count'], (int) $row['down_count']]);
    }

    public function test_vote_while_authenticated_then_logout_and_vote_anonymously_does_not_double_count(): void
    {
        $boardId = $this->insertBoard('double-vote-1', ['anonymous_voting_enabled' => 1]);
        $userId  = $this->insertUser('double-vote-1@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $app = $this->createApp();

        // 1) Authenticated vote — mints and issues the anon-voter cookie
        // too (board has anonymous voting enabled), linking it to $userId.
        $authResponse = $app->handle($this->postVote('double-vote-1', $ideaId, 'up', $userId));
        self::assertSame(200, $authResponse->getStatusCode());
        self::assertSame(1, $this->rowCount($ideaId));
        self::assertSame(1, $this->scoreCache($ideaId));

        $anonCookie = $this->extractAnonCookie($authResponse);
        self::assertNotNull($anonCookie, 'authenticated vote must issue the anon-voter dedup cookie');

        $linkedUserId = (int) $this->conn->fetchOne('SELECT user_id FROM voter_identity_links');
        self::assertSame($userId, $linkedUserId);

        // 2) Same browser, now logged out, votes again — same direction ('up'),
        // same idea, carrying the SAME anon cookie the auth vote just issued.
        $anonResponse = $app->handle($this->postVote('double-vote-1', $ideaId, 'up', null, $anonCookie));
        self::assertSame(200, $anonResponse->getStatusCode());

        // Routed to the SAME identity → clicking "up" again retracts it
        // (existing toggle semantics), never a second counted vote.
        $data = json_decode((string) $anonResponse->getBody(), true);
        self::assertSame('none', $data['my_vote'] ?? null);
        self::assertSame(0, $this->rowCount($ideaId));
        self::assertSame(0, $this->scoreCache($ideaId));
        $this->assertCountersMatchVotes($ideaId, 0, 0);
        self::assertSame([0, 0], [$data['up_count'] ?? null, $data['down_count'] ?? null]);

        // Never created a second, independent voter_token_hash identity.
        self::assertSame(
            0,
            (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE voter_token_hash IS NOT NULL'),
        );
    }

    public function test_vote_anonymously_then_login_and_vote_again_merges_into_one_vote(): void
    {
        $boardId = $this->insertBoard('double-vote-2', ['anonymous_voting_enabled' => 1]);
        $userId  = $this->insertUser('double-vote-2@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $app = $this->createApp();

        // 1) Anonymous vote, no account involved yet — mints its own cookie.
        $anonResponse = $app->handle($this->postVote('double-vote-2', $ideaId, 'up', null));
        self::assertSame(200, $anonResponse->getStatusCode());
        self::assertSame(1, $this->rowCount($ideaId));
        self::assertSame(1, $this->scoreCache($ideaId));

        $anonCookie = $this->extractAnonCookie($anonResponse);
        self::assertNotNull($anonCookie);

        $this->assertCountersMatchVotes($ideaId, 1, 0);

        // 2) Same browser now logs in and votes again, still carrying that
        // SAME anon cookie.
        $authResponse = $app->handle($this->postVote('double-vote-2', $ideaId, 'up', $userId, $anonCookie));
        self::assertSame(200, $authResponse->getStatusCode());
        $this->assertCountersMatchVotes($ideaId, 1, 0);
        $authData = json_decode((string) $authResponse->getBody(), true);
        self::assertSame([1, 0], [$authData['up_count'] ?? null, $authData['down_count'] ?? null]);

        // The stale anonymous row is folded away, not double-counted
        // alongside the fresh authenticated one.
        self::assertSame(1, $this->rowCount($ideaId));
        self::assertSame(1, $this->scoreCache($ideaId));
        self::assertSame(
            0,
            (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE voter_token_hash IS NOT NULL'),
        );
        self::assertSame(
            $userId,
            (int) $this->conn->fetchOne('SELECT user_id FROM votes WHERE idea_id = :id', ['id' => $ideaId]),
        );
    }
}
