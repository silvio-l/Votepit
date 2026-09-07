<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Persistence\IdeaRepository;
use Votepit\Security\IdeaViewTracker;
use Votepit\Security\RateLimiter;
use Votepit\Security\ViewDedupHasher;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Covers IdeaViewTracker — increments ideas.view_count at most once per
 * IP+User-Agent per 24h window, via the existing rate_limits table (no new
 * table, no cookies, no persisted plaintext IP/User-Agent).
 */
final class IdeaViewTrackerTest extends IntegrationTestCase
{
    private function tracker(): IdeaViewTracker
    {
        return new IdeaViewTracker(
            new RateLimiter($this->conn),
            new IdeaRepository($this->conn),
            new ViewDedupHasher(self::identityServerKey()),
            trustCloudflareIp: false,
        );
    }

    private function request(string $ip, string $userAgent = 'Mozilla/5.0'): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/demo/ideas/1', ['REMOTE_ADDR' => $ip])
            ->withHeader('User-Agent', $userAgent);
    }

    private function viewCount(int $ideaId): int
    {
        return (int) $this->conn->fetchOne('SELECT view_count FROM ideas WHERE id = :id', ['id' => $ideaId]);
    }

    public function test_first_view_increments_the_counter(): void
    {
        $boardId  = $this->insertBoard('tracker-board');
        $authorId = $this->insertUser('tracker@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        $this->tracker()->recordView($this->request('203.0.113.10'), $ideaId);

        self::assertSame(1, $this->viewCount($ideaId));
    }

    public function test_repeat_view_from_the_same_visitor_is_deduplicated(): void
    {
        $boardId  = $this->insertBoard('tracker-board-2');
        $authorId = $this->insertUser('tracker2@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        $tracker = $this->tracker();
        $tracker->recordView($this->request('203.0.113.11'), $ideaId);
        $tracker->recordView($this->request('203.0.113.11'), $ideaId);
        $tracker->recordView($this->request('203.0.113.11'), $ideaId);

        self::assertSame(1, $this->viewCount($ideaId));
    }

    public function test_views_from_different_visitors_both_count(): void
    {
        $boardId  = $this->insertBoard('tracker-board-3');
        $authorId = $this->insertUser('tracker3@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        $tracker = $this->tracker();
        $tracker->recordView($this->request('203.0.113.20'), $ideaId);
        $tracker->recordView($this->request('203.0.113.21'), $ideaId);

        self::assertSame(2, $this->viewCount($ideaId));
    }

    public function test_views_on_different_ideas_are_tracked_independently(): void
    {
        $boardId  = $this->insertBoard('tracker-board-4');
        $authorId = $this->insertUser('tracker4@example.com');
        $ideaA    = $this->seedIdea($boardId, $authorId, 'Idea A');
        $ideaB    = $this->seedIdea($boardId, $authorId, 'Idea B');

        $tracker = $this->tracker();
        $tracker->recordView($this->request('203.0.113.30'), $ideaA);
        $tracker->recordView($this->request('203.0.113.30'), $ideaB);

        self::assertSame(1, $this->viewCount($ideaA));
        self::assertSame(1, $this->viewCount($ideaB));
    }

    public function test_missing_ip_does_not_throw_and_still_counts_as_a_single_bucket(): void
    {
        $boardId  = $this->insertBoard('tracker-board-5');
        $authorId = $this->insertUser('tracker5@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        $requestWithoutIp = (new ServerRequestFactory())
            ->createServerRequest('GET', '/demo/ideas/1')
            ->withHeader('User-Agent', 'Mozilla/5.0');

        $this->tracker()->recordView($requestWithoutIp, $ideaId);
        $this->tracker()->recordView($requestWithoutIp, $ideaId);

        self::assertSame(1, $this->viewCount($ideaId));
    }
}
