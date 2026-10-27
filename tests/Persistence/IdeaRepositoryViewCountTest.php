<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\IdeaRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Covers IdeaRepository::incrementViewCount() — the app-side counter
 * (migrations/0049_add_ideas_view_count.sql), maintained the same way
 * score_cache is (VoteRepository), not via a COUNT-subquery.
 */
final class IdeaRepositoryViewCountTest extends IntegrationTestCase
{
    public function test_increment_view_count_increments_by_one(): void
    {
        $boardId  = $this->insertBoard('view-count-board');
        $authorId = $this->insertUser('views@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Idea with views');

        $repo = new IdeaRepository($this->conn);
        $repo->incrementViewCount($ideaId);
        $repo->incrementViewCount($ideaId);

        $idea = $repo->findInBoard($boardId, $ideaId);
        self::assertIsArray($idea);
        self::assertSame(2, (int) $idea['view_count']);
    }

    public function test_find_in_board_defaults_view_count_to_zero(): void
    {
        $boardId  = $this->insertBoard('view-count-board-2');
        $authorId = $this->insertUser('views2@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Fresh idea');

        $idea = (new IdeaRepository($this->conn))->findInBoard($boardId, $ideaId);

        self::assertIsArray($idea);
        self::assertSame(0, (int) $idea['view_count']);
    }
}
