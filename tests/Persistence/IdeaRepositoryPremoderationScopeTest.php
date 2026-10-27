<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Domain\IdeaSearchQuery;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Pre-moderation default scope (broaden-use-cases issue 05): every public
 * read of IdeaRepository/BoardRepository sees PUBLISHED ideas only; pending
 * ideas are reachable solely through the explicit opt-ins
 * (findInBoard(..., includePending: true), listPendingByBoard(),
 * countPendingByBoard()). Pinned at the persistence seam so a future read
 * path built on these methods inherits the invariant.
 */
final class IdeaRepositoryPremoderationScopeTest extends IntegrationTestCase
{
    private IdeaRepository $ideas;
    private BoardRepository $boards;
    private int $boardId;
    private int $authorId;
    private int $publishedId;
    private int $pendingId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ideas    = new IdeaRepository($this->conn);
        $this->boards   = new BoardRepository($this->conn);
        $this->boardId  = $this->insertBoard('scope-board', ['premoderation' => 'all']);
        $this->authorId = $this->insertUser('scope-author@example.com');

        $this->publishedId = $this->seedIdea($this->boardId, $this->authorId, 'Published planned idea', ['status' => 'planned']);
        $this->pendingId   = $this->seedIdea($this->boardId, $this->authorId, 'Pending planned idea', ['status' => 'planned', 'moderation_state' => 'pending']);
    }

    public function test_create_defaults_to_published_and_accepts_pending(): void
    {
        $default = $this->ideas->create($this->boardId, $this->authorId, 'Default idea', 'default idea', 'Body');
        $pending = $this->ideas->create($this->boardId, $this->authorId, 'Queued idea', 'queued idea', 'Body', 'pending');

        self::assertSame('published', $this->conn->fetchOne('SELECT moderation_state FROM ideas WHERE id = :id', ['id' => $default]));
        self::assertSame('pending', $this->conn->fetchOne('SELECT moderation_state FROM ideas WHERE id = :id', ['id' => $pending]));
    }

    public function test_counts_exclude_pending(): void
    {
        self::assertSame(1, $this->ideas->countByBoard($this->boardId));
        self::assertSame(1, $this->ideas->countByBoard($this->boardId, 'planned'));
        self::assertSame(1, $this->ideas->countByAuthorForAccount($this->defaultAccountId(), $this->authorId));
        self::assertSame(1, $this->ideas->countByAuthorForAccount($this->defaultAccountId(), $this->authorId, 'planned'));
        self::assertSame(1, $this->ideas->boardStats($this->boardId)['total_ideas']);
        self::assertSame(1, $this->ideas->boardStats($this->boardId)['weekly_new_ideas']);
        self::assertSame(1, $this->ideas->countListed($this->boardId));
        self::assertSame(1, $this->ideas->countListed($this->boardId, null, IdeaSearchQuery::fromRaw('planned')));
    }

    public function test_lists_exclude_pending(): void
    {
        self::assertSame([$this->publishedId], array_map(static fn (array $r): int => (int) $r['id'], $this->ideas->listByBoard($this->boardId)));
        self::assertSame([$this->publishedId], array_map(static fn (array $r): int => (int) $r['id'], $this->ideas->listByBoard($this->boardId, 'planned')));
        self::assertSame([$this->publishedId], array_map(static fn (array $r): int => (int) $r['id'], $this->ideas->listByBoard($this->boardId, null, search: IdeaSearchQuery::fromRaw('planned'))));
        self::assertSame([$this->publishedId], array_map(static fn (array $r): int => (int) $r['id'], $this->ideas->roadmapByBoard($this->boardId)['planned']));
        self::assertNotContains($this->pendingId, array_map(static fn (array $r): int => (int) $r['id'], $this->ideas->findDuplicateCandidates($this->boardId, 'Pending planned idea')));
    }

    public function test_find_in_board_hides_pending_unless_explicitly_requested(): void
    {
        self::assertNull($this->ideas->findInBoard($this->boardId, $this->pendingId));

        $published = $this->ideas->findInBoard($this->boardId, $this->publishedId);
        self::assertIsArray($published);
        self::assertArrayNotHasKey('moderation_state', $published, 'default payload stays byte-identical');

        $pending = $this->ideas->findInBoard($this->boardId, $this->pendingId, null, includePending: true);
        self::assertIsArray($pending);
        self::assertSame('pending', $pending['moderation_state']);
    }

    public function test_queue_reads_and_approve_are_board_scoped_and_idempotent(): void
    {
        $otherBoard = $this->insertBoard('scope-other', ['is_default' => 0]);
        $approverId = $this->insertUser('scope-approver@example.com');

        self::assertSame([$this->pendingId], array_map(static fn (array $r): int => (int) $r['id'], $this->ideas->listPendingByBoard($this->boardId)));
        self::assertSame(1, $this->ideas->countPendingByBoard($this->boardId));

        self::assertFalse($this->ideas->approve($otherBoard, $this->pendingId, $approverId), 'foreign board must not approve');
        self::assertFalse($this->ideas->approve($this->boardId, $this->publishedId, $approverId), 'published idea is not approvable');
        self::assertTrue($this->ideas->approve($this->boardId, $this->pendingId, $approverId));
        self::assertFalse($this->ideas->approve($this->boardId, $this->pendingId, $approverId), 'second approval is a no-op');

        self::assertSame(2, $this->ideas->countByBoard($this->boardId));
        self::assertSame(0, $this->ideas->countPendingByBoard($this->boardId));
    }

    public function test_soft_deleted_pending_idea_is_neither_queued_nor_approvable(): void
    {
        $this->conn->update('ideas', ['deleted_at' => '2026-01-01 00:00:00'], ['id' => $this->pendingId]);

        self::assertSame(0, $this->ideas->countPendingByBoard($this->boardId));
        self::assertFalse($this->ideas->approve($this->boardId, $this->pendingId, $this->authorId));
    }

    public function test_board_idea_counts_exclude_pending(): void
    {
        $byAccount = array_column($this->boards->listForAccount($this->defaultAccountId()), 'idea_count', 'slug');
        self::assertSame(1, $byAccount['scope-board']);

        $discovery = array_column($this->boards->listPublicDiscovery(50, 0), 'idea_count', 'slug');
        self::assertSame(1, $discovery['scope-board']);
    }

    public function test_board_lookup_exposes_premoderation_mode(): void
    {
        $board = $this->boards->findBySlugForAccount('scope-board', $this->defaultAccountId());

        self::assertIsArray($board);
        self::assertSame('all', $board['premoderation']);
    }

    public function test_export_marks_pending_ideas(): void
    {
        $states = array_column($this->ideas->listAllForAccount($this->defaultAccountId()), 'moderation_state', 'id');

        self::assertSame('published', $states[$this->publishedId]);
        self::assertSame('pending', $states[$this->pendingId]);
    }
}
