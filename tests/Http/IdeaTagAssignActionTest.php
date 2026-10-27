<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for:
 *   POST /{board}/ideas/{id}/tags — assign a tag to an idea
 *   POST /{board}/ideas/{id}/tags/{tagId}/remove — remove a tag from an idea
 *
 * All assertions run through the HTTP seam (AppFactory::create).
 */
final class IdeaTagAssignActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, ?int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    private function makeModerator(): int
    {
        $moderatorId = $this->insertUser('moderator-' . bin2hex(random_bytes(4)) . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $moderatorId, 'moderator');

        return $moderatorId;
    }

    // -------------------------------------------------------------------------
    // Assign
    // -------------------------------------------------------------------------

    public function test_moderator_assigns_tag_to_idea(): void
    {
        $boardId     = $this->insertBoard('assign-ok');
        $moderatorId = $this->makeModerator();
        $ideaId      = $this->seedIdea($boardId, $moderatorId);
        $tagId       = $this->insertTag($boardId, 'Bug');

        $response = $this->createApp()->handle(
            $this->post('/assign-ok/ideas/' . $ideaId . '/tags', ['tag_id' => $tagId], $moderatorId),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $data['tags']);
        self::assertSame('Bug', $data['tags'][0]['name']);

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM idea_tag_assignments WHERE idea_id = :i AND tag_id = :t',
            ['i' => $ideaId, 't' => $tagId],
        );
        self::assertSame(1, $count);
    }

    public function test_assigning_same_tag_twice_is_idempotent(): void
    {
        $boardId     = $this->insertBoard('assign-idem');
        $moderatorId = $this->makeModerator();
        $ideaId      = $this->seedIdea($boardId, $moderatorId);
        $tagId       = $this->insertTag($boardId, 'Bug');

        $this->createApp()->handle($this->post('/assign-idem/ideas/' . $ideaId . '/tags', ['tag_id' => $tagId], $moderatorId));
        $response = $this->createApp()->handle($this->post('/assign-idem/ideas/' . $ideaId . '/tags', ['tag_id' => $tagId], $moderatorId));

        self::assertSame(200, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tag_assignments WHERE idea_id = :i', ['i' => $ideaId]);
        self::assertSame(1, $count);
    }

    public function test_assign_invalid_tag_id_returns_422(): void
    {
        $boardId     = $this->insertBoard('assign-422');
        $moderatorId = $this->makeModerator();
        $ideaId      = $this->seedIdea($boardId, $moderatorId);

        $response = $this->createApp()->handle(
            $this->post('/assign-422/ideas/' . $ideaId . '/tags', ['tag_id' => 0], $moderatorId),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_assign_unknown_tag_returns_404_no_mutation(): void
    {
        $boardId     = $this->insertBoard('assign-unknown-tag');
        $moderatorId = $this->makeModerator();
        $ideaId      = $this->seedIdea($boardId, $moderatorId);

        $response = $this->createApp()->handle(
            $this->post('/assign-unknown-tag/ideas/' . $ideaId . '/tags', ['tag_id' => 999999], $moderatorId),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_assign_tag_from_foreign_board_returns_404_no_mutation(): void
    {
        $boardA      = $this->insertBoard('assign-cross-a');
        $boardB      = $this->insertBoard('assign-cross-b');
        $moderatorId = $this->makeModerator();
        $ideaInB     = $this->seedIdea($boardB, $moderatorId);
        $tagInA      = $this->insertTag($boardA, 'Bug');

        $response = $this->createApp()->handle(
            $this->post('/assign-cross-b/ideas/' . $ideaInB . '/tags', ['tag_id' => $tagInA], $moderatorId),
        );

        self::assertSame(404, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM idea_tag_assignments WHERE idea_id = :i AND tag_id = :t',
            ['i' => $ideaInB, 't' => $tagInA],
        );
        self::assertSame(0, $count);
    }

    public function test_assign_idea_from_foreign_board_returns_404(): void
    {
        $boardA      = $this->insertBoard('assign-idea-cross-a');
        $boardB      = $this->insertBoard('assign-idea-cross-b');
        $moderatorId = $this->makeModerator();
        $ideaInA     = $this->seedIdea($boardA, $moderatorId);
        $tagInB      = $this->insertTag($boardB, 'Bug');

        $response = $this->createApp()->handle(
            $this->post('/assign-idea-cross-b/ideas/' . $ideaInA . '/tags', ['tag_id' => $tagInB], $moderatorId),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_assign_tag_anon_returns_401(): void
    {
        $boardId = $this->insertBoard('assign-anon');
        $userId  = $this->insertUser('assign-anon-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $tagId   = $this->insertTag($boardId, 'Bug');

        $response = $this->createApp()->handle(
            $this->post('/assign-anon/ideas/' . $ideaId . '/tags', ['tag_id' => $tagId], null),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_assign_tag_non_member_returns_403(): void
    {
        $boardId = $this->insertBoard('assign-403');
        $userId  = $this->insertUser('assign-403-user@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $tagId   = $this->insertTag($boardId, 'Bug');

        $response = $this->createApp()->handle(
            $this->post('/assign-403/ideas/' . $ideaId . '/tags', ['tag_id' => $tagId], $userId),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Remove
    // -------------------------------------------------------------------------

    public function test_moderator_removes_tag_from_idea(): void
    {
        $boardId     = $this->insertBoard('remove-ok');
        $moderatorId = $this->makeModerator();
        $ideaId      = $this->seedIdea($boardId, $moderatorId);
        $tagId       = $this->insertTag($boardId, 'Bug');
        $this->assignTag($ideaId, $tagId);

        $response = $this->createApp()->handle(
            $this->post('/remove-ok/ideas/' . $ideaId . '/tags/' . $tagId . '/remove', [], $moderatorId),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([], $data['tags']);
    }

    public function test_remove_unassigned_tag_is_idempotent_noop(): void
    {
        $boardId     = $this->insertBoard('remove-noop');
        $moderatorId = $this->makeModerator();
        $ideaId      = $this->seedIdea($boardId, $moderatorId);
        $tagId       = $this->insertTag($boardId, 'Bug');

        $response = $this->createApp()->handle(
            $this->post('/remove-noop/ideas/' . $ideaId . '/tags/' . $tagId . '/remove', [], $moderatorId),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_remove_tag_from_foreign_board_idea_returns_404(): void
    {
        $boardA      = $this->insertBoard('remove-cross-a');
        $boardB      = $this->insertBoard('remove-cross-b');
        $moderatorId = $this->makeModerator();
        $ideaInB     = $this->seedIdea($boardB, $moderatorId);
        $tagInA      = $this->insertTag($boardA, 'Bug');

        $response = $this->createApp()->handle(
            $this->post('/remove-cross-b/ideas/' . $ideaInB . '/tags/' . $tagInA . '/remove', [], $moderatorId),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_remove_tag_anon_returns_401(): void
    {
        $boardId = $this->insertBoard('remove-anon');
        $userId  = $this->insertUser('remove-anon-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $tagId   = $this->insertTag($boardId, 'Bug');
        $this->assignTag($ideaId, $tagId);

        $response = $this->createApp()->handle(
            $this->post('/remove-anon/ideas/' . $ideaId . '/tags/' . $tagId . '/remove', [], null),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Cross-tenant: an idea/tag pair in a FOREIGN account's board is
    // unreachable via the default account's resolved board, even under an
    // identical slug.
    // -------------------------------------------------------------------------

    public function test_idea_and_tag_in_foreign_account_are_unreachable(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-idea-tags-foreign']);
        $foreignBoardId   = $this->insertBoard('shared-idea-tag-slug', ['account_id' => $foreignAccountId]);
        $foreignUserId    = $this->insertUser('foreign-author@example.com');
        $foreignIdeaId    = $this->seedIdea($foreignBoardId, $foreignUserId);
        $foreignTagId     = $this->insertTag($foreignBoardId, 'Secret');

        $this->insertBoard('shared-idea-tag-slug', ['account_id' => $this->defaultAccountId()]);
        $moderatorId = $this->makeModerator();

        $response = $this->createApp()->handle(
            $this->post(
                '/shared-idea-tag-slug/ideas/' . $foreignIdeaId . '/tags',
                ['tag_id' => $foreignTagId],
                $moderatorId,
            ),
        );

        self::assertSame(404, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM idea_tag_assignments WHERE idea_id = :i AND tag_id = :t',
            ['i' => $foreignIdeaId, 't' => $foreignTagId],
        );
        self::assertSame(0, $count);
    }
}
