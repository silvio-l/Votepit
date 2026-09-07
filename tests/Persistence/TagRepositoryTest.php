<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\TagRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Repository-level tests for TagRepository (migrations/0051_create_idea_tags.sql).
 *
 * Board-scoping: idea_tags carries no account_id column, only board_id — see
 * the class doc on TagRepository for the chokepoint reasoning. These tests
 * verify the SQL-level guarantees directly (WHERE board_id = ...), the HTTP
 * seam tests (TagActionTest, IdeaTagAssignActionTest) additionally cover the
 * AuthZ + board-lookup chokepoint.
 */
final class TagRepositoryTest extends IntegrationTestCase
{
    private function repo(): TagRepository
    {
        return new TagRepository($this->conn);
    }

    public function test_create_and_list_by_board(): void
    {
        $boardId = $this->insertBoard('tags-list');
        $repo    = $this->repo();

        $tagId = $repo->create($boardId, 'Bug', '#ff0000');

        self::assertIsInt($tagId);
        $tags = $repo->listByBoard($boardId);
        self::assertCount(1, $tags);
        self::assertSame('Bug', $tags[0]['name']);
        self::assertSame('#ff0000', $tags[0]['color']);
    }

    public function test_create_duplicate_name_in_same_board_returns_null(): void
    {
        $boardId = $this->insertBoard('tags-dup');
        $repo    = $this->repo();

        $repo->create($boardId, 'Bug', '#ff0000');
        $second = $repo->create($boardId, 'Bug', '#00ff00');

        self::assertNull($second);
        self::assertCount(1, $repo->listByBoard($boardId));
    }

    public function test_same_name_allowed_across_different_boards(): void
    {
        $boardA = $this->insertBoard('tags-a');
        $boardB = $this->insertBoard('tags-b');
        $repo   = $this->repo();

        $idA = $repo->create($boardA, 'Bug', '#ff0000');
        $idB = $repo->create($boardB, 'Bug', '#ff0000');

        self::assertIsInt($idA);
        self::assertIsInt($idB);
        self::assertNotSame($idA, $idB);
    }

    public function test_find_in_board_returns_null_for_foreign_board(): void
    {
        $boardA = $this->insertBoard('tags-find-a');
        $boardB = $this->insertBoard('tags-find-b');
        $repo   = $this->repo();

        $tagId = $repo->create($boardA, 'Bug', '#ff0000');
        self::assertIsInt($tagId);

        self::assertNotNull($repo->findInBoard($boardA, $tagId));
        self::assertNull($repo->findInBoard($boardB, $tagId));
    }

    public function test_rename_updates_name_and_color(): void
    {
        $boardId = $this->insertBoard('tags-rename');
        $repo    = $this->repo();
        $tagId   = $repo->create($boardId, 'Bug', '#ff0000');
        self::assertIsInt($tagId);

        $result = $repo->rename($boardId, $tagId, 'Defect', '#00ff00');

        self::assertTrue($result);
        $tag = $repo->findInBoard($boardId, $tagId);
        self::assertIsArray($tag);
        self::assertSame('Defect', $tag['name']);
        self::assertSame('#00ff00', $tag['color']);
    }

    public function test_rename_to_existing_name_returns_null(): void
    {
        $boardId = $this->insertBoard('tags-rename-collision');
        $repo    = $this->repo();
        $repo->create($boardId, 'Bug', '#ff0000');
        $secondId = $repo->create($boardId, 'Feature', '#00ff00');
        self::assertIsInt($secondId);

        $result = $repo->rename($boardId, $secondId, 'Bug', '#00ff00');

        self::assertNull($result);
        // Unchanged.
        $tag = $repo->findInBoard($boardId, $secondId);
        self::assertIsArray($tag);
        self::assertSame('Feature', $tag['name']);
    }

    public function test_rename_foreign_board_returns_false_no_mutation(): void
    {
        $boardA = $this->insertBoard('tags-rename-a');
        $boardB = $this->insertBoard('tags-rename-b');
        $repo   = $this->repo();
        $tagId  = $repo->create($boardA, 'Bug', '#ff0000');
        self::assertIsInt($tagId);

        $result = $repo->rename($boardB, $tagId, 'Hacked', '#000000');

        self::assertFalse($result);
        $tag = $repo->findInBoard($boardA, $tagId);
        self::assertIsArray($tag);
        self::assertSame('Bug', $tag['name']);
    }

    public function test_delete_removes_tag_and_cascades_assignments(): void
    {
        $boardId = $this->insertBoard('tags-delete');
        $adminId = $this->insertUser('admin-delete@example.com');
        $ideaId  = $this->seedIdea($boardId, $adminId);
        $repo    = $this->repo();
        $tagId   = $repo->create($boardId, 'Bug', '#ff0000');
        self::assertIsInt($tagId);

        $repo->assignToIdea($ideaId, $tagId);
        self::assertCount(1, $repo->tagsForIdea($ideaId));

        $deleted = $repo->delete($boardId, $tagId);

        self::assertTrue($deleted);
        self::assertNull($repo->findInBoard($boardId, $tagId));
        self::assertCount(0, $repo->tagsForIdea($ideaId));
    }

    public function test_delete_foreign_board_returns_false_no_mutation(): void
    {
        $boardA = $this->insertBoard('tags-del-a');
        $boardB = $this->insertBoard('tags-del-b');
        $repo   = $this->repo();
        $tagId  = $repo->create($boardA, 'Bug', '#ff0000');
        self::assertIsInt($tagId);

        $deleted = $repo->delete($boardB, $tagId);

        self::assertFalse($deleted);
        self::assertNotNull($repo->findInBoard($boardA, $tagId));
    }

    public function test_assign_to_idea_is_idempotent(): void
    {
        $boardId = $this->insertBoard('tags-assign-idem');
        $userId  = $this->insertUser('assign-idem@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $repo    = $this->repo();
        $tagId   = $repo->create($boardId, 'Bug', '#ff0000');
        self::assertIsInt($tagId);

        $repo->assignToIdea($ideaId, $tagId);
        $repo->assignToIdea($ideaId, $tagId);

        self::assertCount(1, $repo->tagsForIdea($ideaId));
    }

    public function test_remove_from_idea_unassigns(): void
    {
        $boardId = $this->insertBoard('tags-remove');
        $userId  = $this->insertUser('remove@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $repo    = $this->repo();
        $tagId   = $repo->create($boardId, 'Bug', '#ff0000');
        self::assertIsInt($tagId);

        $repo->assignToIdea($ideaId, $tagId);
        $repo->removeFromIdea($ideaId, $tagId);

        self::assertCount(0, $repo->tagsForIdea($ideaId));
    }

    public function test_remove_from_idea_unknown_assignment_is_noop(): void
    {
        $boardId = $this->insertBoard('tags-remove-noop');
        $userId  = $this->insertUser('remove-noop@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $repo    = $this->repo();

        // No exception, no error.
        $repo->removeFromIdea($ideaId, 999999);

        self::assertCount(0, $repo->tagsForIdea($ideaId));
    }

    public function test_tags_for_ideas_batches_across_multiple_ideas(): void
    {
        $boardId = $this->insertBoard('tags-batch');
        $userId  = $this->insertUser('batch@example.com');
        $idea1   = $this->seedIdea($boardId, $userId, 'Idea 1');
        $idea2   = $this->seedIdea($boardId, $userId, 'Idea 2');
        $idea3   = $this->seedIdea($boardId, $userId, 'Idea 3 (untagged)');
        $repo    = $this->repo();

        $bugId     = $repo->create($boardId, 'Bug', '#ff0000');
        $featureId = $repo->create($boardId, 'Feature', '#00ff00');
        self::assertIsInt($bugId);
        self::assertIsInt($featureId);

        $repo->assignToIdea($idea1, $bugId);
        $repo->assignToIdea($idea1, $featureId);
        $repo->assignToIdea($idea2, $bugId);

        $grouped = $repo->tagsForIdeas([$idea1, $idea2, $idea3]);

        self::assertCount(2, $grouped[$idea1]);
        self::assertCount(1, $grouped[$idea2]);
        self::assertArrayNotHasKey($idea3, $grouped);
    }

    public function test_tags_for_ideas_empty_list_returns_empty_array(): void
    {
        self::assertSame([], $this->repo()->tagsForIdeas([]));
    }
}
