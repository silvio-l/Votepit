<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the tag CRUD HTTP seam:
 *   GET  /{board}/tags
 *   POST /{board}/tags
 *   POST /{board}/tags/{id}
 *   POST /{board}/tags/{id}/delete
 *
 * All assertions run through the HTTP seam (AppFactory::create), the
 * identical pipeline to production: Session → AuthN → AuthZ accountModerate
 * → BlockCheck → CSRF → route.
 */
final class TagActionTest extends IntegrationTestCase
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

    private function get(string $path, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        if ($userId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
        }

        return $request;
    }

    private function makeModerator(): int
    {
        $moderatorId = $this->insertUser('moderator-' . bin2hex(random_bytes(4)) . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $moderatorId, 'moderator');

        return $moderatorId;
    }

    // -------------------------------------------------------------------------
    // GET /{board}/tags — public, anon
    // -------------------------------------------------------------------------

    public function test_anon_can_list_tags(): void
    {
        $boardId = $this->insertBoard('tag-list');
        $this->insertTag($boardId, 'Bug');
        $this->insertTag($boardId, 'Feature');

        $response = $this->createApp()->handle($this->get('/tag-list/tags', null));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(2, $data['tags']);
    }

    public function test_list_tags_unknown_board_returns_404(): void
    {
        $response = $this->createApp()->handle($this->get('/no-such-board/tags', null));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // POST /{board}/tags — create (moderator required)
    // -------------------------------------------------------------------------

    public function test_moderator_creates_tag_returns_201(): void
    {
        $boardId     = $this->insertBoard('tag-create');
        $moderatorId = $this->makeModerator();

        $response = $this->createApp()->handle(
            $this->post('/tag-create/tags', ['name' => 'Bug', 'color' => '#ff0000'], $moderatorId),
        );

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('Bug', $data['tag']['name']);
        self::assertSame('#ff0000', $data['tag']['color']);

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tags WHERE board_id = :b', ['b' => $boardId]);
        self::assertSame(1, $count);
    }

    public function test_create_tag_defaults_color_when_omitted(): void
    {
        $this->insertBoard('tag-create-default-color');
        $moderatorId = $this->makeModerator();

        $response = $this->createApp()->handle(
            $this->post('/tag-create-default-color/tags', ['name' => 'Bug'], $moderatorId),
        );

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('#3b82f6', $data['tag']['color']);
    }

    public function test_create_tag_empty_name_returns_422(): void
    {
        $this->insertBoard('tag-create-empty');
        $moderatorId = $this->makeModerator();

        $response = $this->createApp()->handle(
            $this->post('/tag-create-empty/tags', ['name' => '   '], $moderatorId),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_create_tag_invalid_color_returns_422(): void
    {
        $this->insertBoard('tag-create-bad-color');
        $moderatorId = $this->makeModerator();

        $response = $this->createApp()->handle(
            $this->post('/tag-create-bad-color/tags', ['name' => 'Bug', 'color' => 'not-a-color'], $moderatorId),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_create_duplicate_tag_name_returns_422(): void
    {
        $boardId     = $this->insertBoard('tag-create-dup');
        $moderatorId = $this->makeModerator();
        $this->insertTag($boardId, 'Bug');

        $response = $this->createApp()->handle(
            $this->post('/tag-create-dup/tags', ['name' => 'Bug'], $moderatorId),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_create_tag_anon_returns_401(): void
    {
        $this->insertBoard('tag-create-anon');

        $response = $this->createApp()->handle(
            $this->post('/tag-create-anon/tags', ['name' => 'Bug'], null),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_create_tag_non_member_returns_403(): void
    {
        $this->insertBoard('tag-create-403');
        $userId = $this->insertUser('nomember@example.com');

        $response = $this->createApp()->handle(
            $this->post('/tag-create-403/tags', ['name' => 'Bug'], $userId),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_create_tag_unknown_board_returns_404(): void
    {
        $moderatorId = $this->makeModerator();

        $response = $this->createApp()->handle(
            $this->post('/no-such-board/tags', ['name' => 'Bug'], $moderatorId),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // POST /{board}/tags/{id} — rename/recolor
    // -------------------------------------------------------------------------

    public function test_moderator_renames_tag(): void
    {
        $boardId     = $this->insertBoard('tag-rename');
        $moderatorId = $this->makeModerator();
        $tagId       = $this->insertTag($boardId, 'Bug');

        $response = $this->createApp()->handle(
            $this->post('/tag-rename/tags/' . $tagId, ['name' => 'Defect', 'color' => '#00ff00'], $moderatorId),
        );

        self::assertSame(200, $response->getStatusCode());
        $name = $this->conn->fetchOne('SELECT name FROM idea_tags WHERE id = :id', ['id' => $tagId]);
        self::assertSame('Defect', $name);
    }

    public function test_rename_tag_from_foreign_board_returns_404_no_mutation(): void
    {
        $boardA      = $this->insertBoard('tag-rename-a');
        $this->insertBoard('tag-rename-b');
        $moderatorId = $this->makeModerator();
        $tagId       = $this->insertTag($boardA, 'Bug');

        $response = $this->createApp()->handle(
            $this->post('/tag-rename-b/tags/' . $tagId, ['name' => 'Hacked'], $moderatorId),
        );

        self::assertSame(404, $response->getStatusCode());
        $name = $this->conn->fetchOne('SELECT name FROM idea_tags WHERE id = :id', ['id' => $tagId]);
        self::assertSame('Bug', $name);
    }

    public function test_rename_tag_anon_returns_401(): void
    {
        $boardId = $this->insertBoard('tag-rename-anon');
        $tagId   = $this->insertTag($boardId, 'Bug');

        $response = $this->createApp()->handle(
            $this->post('/tag-rename-anon/tags/' . $tagId, ['name' => 'Hacked'], null),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // POST /{board}/tags/{id}/delete
    // -------------------------------------------------------------------------

    public function test_moderator_deletes_tag(): void
    {
        $boardId     = $this->insertBoard('tag-delete');
        $moderatorId = $this->makeModerator();
        $tagId       = $this->insertTag($boardId, 'Bug');

        $response = $this->createApp()->handle(
            $this->post('/tag-delete/tags/' . $tagId . '/delete', [], $moderatorId),
        );

        self::assertSame(200, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tags WHERE id = :id', ['id' => $tagId]);
        self::assertSame(0, $count);
    }

    public function test_delete_tag_cascades_idea_assignments(): void
    {
        $boardId     = $this->insertBoard('tag-delete-cascade');
        $moderatorId = $this->makeModerator();
        $ideaId      = $this->seedIdea($boardId, $moderatorId);
        $tagId       = $this->insertTag($boardId, 'Bug');
        $this->assignTag($ideaId, $tagId);

        $this->createApp()->handle($this->post('/tag-delete-cascade/tags/' . $tagId . '/delete', [], $moderatorId));

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tag_assignments WHERE tag_id = :id', ['id' => $tagId]);
        self::assertSame(0, $count);
    }

    public function test_delete_tag_from_foreign_board_returns_404_no_mutation(): void
    {
        $boardA      = $this->insertBoard('tag-del-a');
        $this->insertBoard('tag-del-b');
        $moderatorId = $this->makeModerator();
        $tagId       = $this->insertTag($boardA, 'Bug');

        $response = $this->createApp()->handle(
            $this->post('/tag-del-b/tags/' . $tagId . '/delete', [], $moderatorId),
        );

        self::assertSame(404, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tags WHERE id = :id', ['id' => $tagId]);
        self::assertSame(1, $count);
    }

    public function test_delete_tag_non_member_returns_403(): void
    {
        $boardId = $this->insertBoard('tag-del-403');
        $tagId   = $this->insertTag($boardId, 'Bug');
        $userId  = $this->insertUser('nomember-del@example.com');

        $response = $this->createApp()->handle(
            $this->post('/tag-del-403/tags/' . $tagId . '/delete', [], $userId),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Cross-tenant: a tag in a board of a FOREIGN account is unreachable
    // even for an owner/moderator of the default account.
    // -------------------------------------------------------------------------

    public function test_tag_in_foreign_account_board_is_unreachable(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-tags-foreign']);
        $foreignBoardId   = $this->insertBoard('shared-tag-slug', ['account_id' => $foreignAccountId]);
        $foreignTagId     = $this->insertTag($foreignBoardId, 'Secret');

        // Same slug also exists in the default account.
        $this->insertBoard('shared-tag-slug', ['account_id' => $this->defaultAccountId()]);
        $ownerId = $this->makeModerator();

        // GET resolves the DEFAULT-account board under this slug — the
        // foreign tag must not appear.
        $listResponse = $this->createApp()->handle($this->get('/shared-tag-slug/tags', null));
        self::assertSame(200, $listResponse->getStatusCode());
        $data = json_decode((string) $listResponse->getBody(), true);
        self::assertSame([], $data['tags']);

        // Attempting to rename the foreign tag via the default-account-resolved
        // board slug must 404, not touch the foreign row.
        $renameResponse = $this->createApp()->handle(
            $this->post('/shared-tag-slug/tags/' . $foreignTagId, ['name' => 'Hacked'], $ownerId),
        );
        self::assertSame(404, $renameResponse->getStatusCode());
        $name = $this->conn->fetchOne('SELECT name FROM idea_tags WHERE id = :id', ['id' => $foreignTagId]);
        self::assertSame('Secret', $name);
    }
}
