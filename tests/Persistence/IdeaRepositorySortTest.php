<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\IdeaRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Allow-list guard for IdeaRepository::listByBoard — sort axis.
 *
 * Proves: a disallowed $sortKey does NOT flow into the query as a raw string;
 * instead 'newest' (created_at DESC) is used as a fallback.
 *
 * Covered requirement (reviewer blocker):
 *   "Fix: allow-list guard before concatenation — unknown value → Newest/created_at DESC."
 */
final class IdeaRepositorySortTest extends IntegrationTestCase
{
    private IdeaRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new IdeaRepository($this->conn);
    }

    /**
     * An invalid sortKey → fallback to Newest (created_at DESC).
     * Result: same order as passing 'newest' explicitly.
     */
    public function test_unknown_sort_key_falls_back_to_newest(): void
    {
        $boardId  = $this->insertBoard('sort-test-board');
        $authorId = $this->insertUser('sorttest@example.com');

        $this->seedIdea($boardId, $authorId, 'Older idea', [
            'created_at' => '2025-01-01 10:00:00',
            'updated_at' => '2025-01-01 10:00:00',
        ]);
        $this->seedIdea($boardId, $authorId, 'Newer idea', [
            'created_at' => '2025-06-01 10:00:00',
            'updated_at' => '2025-06-01 10:00:00',
        ]);

        // Invalid sortKey — must NOT flow into the query as a raw SQL string.
        $rowsUnknown = $this->repo->listByBoard($boardId, null, 50, 0, 'injected; DROP TABLE ideas;--');
        $rowsNewest  = $this->repo->listByBoard($boardId, null, 50, 0, 'newest');

        // Both calls must return the same number of rows (no SQL error, no data loss).
        self::assertCount(2, $rowsUnknown, 'Unknown sort key must not cause an SQL error.');
        self::assertCount(2, $rowsNewest);

        // Order: newest first (created_at DESC) — the newer idea comes first.
        self::assertSame('Newer idea', $rowsUnknown[0]['title'], 'Fallback must be created_at DESC (newest).');
        self::assertSame('Older idea', $rowsUnknown[1]['title']);

        // Order must be identical to passing 'newest' explicitly.
        self::assertSame($rowsNewest[0]['title'], $rowsUnknown[0]['title']);
        self::assertSame($rowsNewest[1]['title'], $rowsUnknown[1]['title']);
    }

    /** Known sort keys are processed correctly. */
    public function test_known_sort_keys_are_accepted(): void
    {
        $boardId  = $this->insertBoard('sort-known-board');
        $authorId = $this->insertUser('sortknown@example.com');

        $this->seedIdea($boardId, $authorId, 'Idea A');
        $this->seedIdea($boardId, $authorId, 'Idea B');

        foreach (array_keys(IdeaRepository::SORT_AXES) as $key) {
            $rows = $this->repo->listByBoard($boardId, null, 50, 0, $key);
            self::assertCount(2, $rows, "Sort key '{$key}' should return 2 rows.");
        }
    }

    /**
     * Pinned ideas appear on top, regardless of the chosen sort axis (AC1/AC2).
     */
    public function test_pinned_idea_appears_first_regardless_of_sort_axis(): void
    {
        $boardId  = $this->insertBoard('pin-sort-board');
        $authorId = $this->insertUser('pinsort@example.com');

        $this->seedIdea($boardId, $authorId, 'Newer, unpinned idea', [
            'created_at'  => '2025-06-01 10:00:00',
            'score_cache' => 10,
        ]);
        $this->seedIdea($boardId, $authorId, 'Older, pinned idea', [
            'created_at'  => '2025-01-01 10:00:00',
            'score_cache' => 1,
            'is_pinned'   => 1,
        ]);

        foreach (array_keys(IdeaRepository::SORT_AXES) as $key) {
            $rows = $this->repo->listByBoard($boardId, null, 50, 0, $key);
            self::assertSame(
                'Older, pinned idea',
                $rows[0]['title'],
                "Pinned idea must appear at the top for sort key '{$key}' even though it is older/weaker.",
            );
        }
    }

    /** An unpinned idea falls back into normal sort order. */
    public function test_unpinning_restores_normal_sort_order(): void
    {
        $boardId  = $this->insertBoard('unpin-sort-board');
        $authorId = $this->insertUser('unpinsort@example.com');

        $this->seedIdea($boardId, $authorId, 'Newer idea', ['created_at' => '2025-06-01 10:00:00']);
        $pinnedId = $this->seedIdea($boardId, $authorId, 'Older idea', [
            'created_at' => '2025-01-01 10:00:00',
            'is_pinned'  => 1,
        ]);

        $this->repo->setPinned($boardId, $pinnedId, false);

        $rows = $this->repo->listByBoard($boardId, null, 50, 0, 'newest');
        self::assertSame('Newer idea', $rows[0]['title'], 'After unpinning, created_at DESC applies again.');
    }
}
