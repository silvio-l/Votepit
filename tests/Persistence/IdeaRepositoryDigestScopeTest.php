<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Domain\Premoderation;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Weekly owner digest highlights (competitor-parity issue 14) against
 * pre-moderation (broaden-use-cases issue 05) and status categories
 * (issue 03): a pending idea and an idea in a custom status of the archived
 * category never reach the digest mail.
 */
final class IdeaRepositoryDigestScopeTest extends IntegrationTestCase
{
    /**
     * @param array{new_ideas: list<array{id: int, title: string}>, top: list<array{id: int, title: string, up_count: int, down_count: int}>, most_rejected: list<array{id: int, title: string, up_count: int, down_count: int}>, most_polarising: list<array{id: int, title: string, up_count: int, down_count: int}>} $highlights
     * @return list<int>
     */
    private function ids(array $highlights, string $section): array
    {
        return array_column($highlights[$section], 'id');
    }

    /** Seeds an idea with one upvote and one downvote cast this week. */
    private function activeIdea(int $boardId, int $authorId, string $title, int $upVoter, int $downVoter): int
    {
        $ideaId = $this->seedIdea($boardId, $authorId, $title);
        $this->seedVote($ideaId, $upVoter, 1);
        $this->seedVote($ideaId, $downVoter, -1);
        $this->conn->update('ideas', ['up_count' => 2, 'down_count' => 1], ['id' => $ideaId]);

        return $ideaId;
    }

    public function test_pending_idea_is_excluded_from_new_ideas_and_the_vote_pool(): void
    {
        $boardId = $this->insertBoard('digest-pending');
        $author  = $this->insertUser('digest-author@example.com');
        $up      = $this->insertUser('digest-up@example.com');
        $down    = $this->insertUser('digest-down@example.com');

        $published = $this->activeIdea($boardId, $author, 'Published idea', $up, $down);
        $pending   = $this->activeIdea($boardId, $author, 'Pending idea', $up, $down);
        $this->conn->update('ideas', ['moderation_state' => Premoderation::STATE_PENDING], ['id' => $pending]);

        $highlights = (new IdeaRepository($this->conn))->digestHighlights($boardId, new \DateTimeImmutable('-7 days'));

        foreach (['new_ideas', 'top', 'most_rejected', 'most_polarising'] as $section) {
            self::assertContains($published, $this->ids($highlights, $section), $section);
            self::assertNotContains($pending, $this->ids($highlights, $section), $section);
        }
    }

    public function test_custom_status_in_the_archived_category_is_excluded_from_new_ideas_and_the_vote_pool(): void
    {
        $accountId = $this->insertAccount(['slug' => 'digest-archived-acc']);
        $boardId   = $this->insertBoard('digest-archived', ['account_id' => $accountId]);
        $author    = $this->insertUser('digest-arch-author@example.com');
        $up        = $this->insertUser('digest-arch-up@example.com');
        $down      = $this->insertUser('digest-arch-down@example.com');

        $statuses = new BoardStatusRepository($this->conn);
        $board    = $this->conn->fetchAssociative('SELECT * FROM boards WHERE id = :id', ['id' => $boardId]);
        self::assertIsArray($board);
        $statuses->materialize($board, $accountId);
        $shelvedKey = $statuses->createCustom($boardId, 'archived', 'Zurückgestellt', 'Shelved', false, false); // export-ok: comment-language

        $open     = $this->activeIdea($boardId, $author, 'Open idea', $up, $down);
        $shelved  = $this->activeIdea($boardId, $author, 'Shelved idea', $up, $down);
        $archived = $this->activeIdea($boardId, $author, 'Archived idea', $up, $down);
        $this->conn->update('ideas', ['status' => $shelvedKey], ['id' => $shelved]);
        $this->conn->update('ideas', ['status' => 'archived'], ['id' => $archived]);

        $highlights = (new IdeaRepository($this->conn))->digestHighlights($boardId, new \DateTimeImmutable('-7 days'));

        foreach (['new_ideas', 'top', 'most_rejected', 'most_polarising'] as $section) {
            self::assertContains($open, $this->ids($highlights, $section), $section);
            self::assertNotContains($shelved, $this->ids($highlights, $section), $section);
            self::assertNotContains($archived, $this->ids($highlights, $section), $section);
        }
    }
}
