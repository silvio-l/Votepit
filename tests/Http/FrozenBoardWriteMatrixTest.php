<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Behavioural proof for the P-21 write-route policy (see
 * BoardWriteRoutePolicy): every GUARDED route answers 423 board_frozen on a
 * frozen board and writes nothing; the deliberately EXEMPT moderation and
 * delete routes stay open (owner rule Q1=b, W9 decisions).
 *
 * Covers the GUARDED routes that the other frozen-board suites do not reach
 * yet (tags, comment edit/react, guest withdraw, moderation queue, issue
 * links, import, logo asset, the API and MCP-backed writes). Settings writes
 * and the core content writes are covered by FrozenBoardSettingsGuardTest and
 * BoardFreezeGuardTest.
 */
final class FrozenBoardWriteMatrixTest extends IntegrationTestCase
{
    private const SLUG = 'matrix-board';

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function form(string $method, string $path, ?int $userId, array $body = []): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $cookies = [$csrf->cookieName() => $csrf->sign($token)];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    private function freeze(int $boardId): void
    {
        $this->conn->update('boards', ['frozen_at' => '2026-01-01 00:00:00'], ['id' => $boardId]);
    }

    /**
     * Board + owner (account owner, so every AuthZ level passes) + frozen.
     *
     * @return array{0: int, 1: int} [boardId, ownerId]
     */
    private function frozenBoardWithOwner(): array
    {
        $boardId = $this->insertBoard(self::SLUG);
        $ownerId = $this->insertUser('matrix-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $this->freeze($boardId);

        return [$boardId, $ownerId];
    }

    private function assertFrozenAndUnchanged(ResponseInterface $response): void
    {
        self::assertSame(423, $response->getStatusCode(), (string) $response->getBody());
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertSame('board_frozen', $decoded['error']['key'] ?? null);
    }

    /** @param array<string, mixed> $params */
    private function rowCount(string $sql, array $params): int
    {
        return (int) $this->conn->fetchOne($sql, $params);
    }

    // -------------------------------------------------------------------------
    // Participant writes
    // -------------------------------------------------------------------------

    public function test_comment_edit_is_rejected_and_keeps_the_text(): void
    {
        [$boardId, ] = $this->frozenBoardWithOwner();
        $authorId = $this->insertUser('matrix-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Original text.');

        $response = $this->createApp()->handle($this->form(
            'POST',
            '/' . self::SLUG . "/ideas/{$ideaId}/comments/{$commentId}/edit",
            $authorId,
            ['body' => 'Changed text.'],
        ));

        $this->assertFrozenAndUnchanged($response);
        self::assertSame('Original text.', $this->conn->fetchOne('SELECT body FROM comments WHERE id = :id', ['id' => $commentId]));
    }

    public function test_comment_reaction_is_rejected(): void
    {
        [$boardId, ] = $this->frozenBoardWithOwner();
        $authorId  = $this->insertUser('matrix-react-author@example.com');
        $reactorId = $this->insertUser('matrix-reactor@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId);

        $response = $this->createApp()->handle($this->form(
            'POST',
            '/' . self::SLUG . "/ideas/{$ideaId}/comments/{$commentId}/react",
            $reactorId,
            ['reaction' => '👍'],
        ));

        $this->assertFrozenAndUnchanged($response);
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM comment_reactions WHERE comment_id = :id', ['id' => $commentId]));
    }

    public function test_guest_withdraw_is_rejected_and_idea_stays(): void
    {
        [$boardId, ] = $this->frozenBoardWithOwner();
        // Guest flows need anonymous submission switched on for the board.
        $this->conn->update('boards', ['anonymous_submission_enabled' => 1], ['id' => $boardId]);
        $authorId = $this->insertUser('matrix-guest-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        $response = $this->createApp()->handle($this->form(
            'POST',
            '/' . self::SLUG . "/ideas/{$ideaId}/guest-withdraw",
            null,
        ));

        $this->assertFrozenAndUnchanged($response);
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM ideas WHERE id = :id', ['id' => $ideaId]));
    }

    // -------------------------------------------------------------------------
    // Tags and tag assignments (moderator writes)
    // -------------------------------------------------------------------------

    public function test_tag_create_is_rejected(): void
    {
        [, $ownerId] = $this->frozenBoardWithOwner();

        $response = $this->createApp()->handle($this->form('POST', '/' . self::SLUG . '/tags', $ownerId, ['name' => 'New tag']));

        $this->assertFrozenAndUnchanged($response);
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM idea_tags WHERE name = :name', ['name' => 'New tag']));
    }

    public function test_tag_update_is_rejected(): void
    {
        [$boardId, $ownerId] = $this->frozenBoardWithOwner();
        $tagId = $this->insertTag($boardId, 'Bug');

        $response = $this->createApp()->handle($this->form('POST', '/' . self::SLUG . "/tags/{$tagId}", $ownerId, ['name' => 'Renamed']));

        $this->assertFrozenAndUnchanged($response);
        self::assertSame('Bug', $this->conn->fetchOne('SELECT name FROM idea_tags WHERE id = :id', ['id' => $tagId]));
    }

    public function test_tag_delete_is_rejected(): void
    {
        [$boardId, $ownerId] = $this->frozenBoardWithOwner();
        $tagId = $this->insertTag($boardId, 'Bug');

        $response = $this->createApp()->handle($this->form('POST', '/' . self::SLUG . "/tags/{$tagId}/delete", $ownerId));

        $this->assertFrozenAndUnchanged($response);
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM idea_tags WHERE id = :id', ['id' => $tagId]));
    }

    public function test_tag_assign_and_remove_are_rejected(): void
    {
        [$boardId, $ownerId] = $this->frozenBoardWithOwner();
        $ideaId = $this->seedIdea($boardId, $ownerId);
        $tagId  = $this->insertTag($boardId, 'Bug');
        $other  = $this->insertTag($boardId, 'Feature');

        $assign = $this->createApp()->handle($this->form('POST', '/' . self::SLUG . "/ideas/{$ideaId}/tags", $ownerId, ['tag_id' => $other]));
        $this->assertFrozenAndUnchanged($assign);
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM idea_tag_assignments WHERE idea_id = :id AND tag_id = :tag', ['id' => $ideaId, 'tag' => $other]));

        $this->assignTag($ideaId, $tagId);
        $remove = $this->createApp()->handle($this->form('POST', '/' . self::SLUG . "/ideas/{$ideaId}/tags/{$tagId}/remove", $ownerId));
        $this->assertFrozenAndUnchanged($remove);
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM idea_tag_assignments WHERE idea_id = :id AND tag_id = :tag', ['id' => $ideaId, 'tag' => $tagId]));
    }

    // -------------------------------------------------------------------------
    // Moderation queue, issue links, import, logo (admin writes)
    // -------------------------------------------------------------------------

    public function test_moderation_queue_writes_are_rejected(): void
    {
        [$boardId, $ownerId] = $this->frozenBoardWithOwner();
        $authorId = $this->insertUser('matrix-queue-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        foreach (['queue/approve', 'queue/approve-and-trust', 'moderation/guest-reject-all'] as $path) {
            $response = $this->createApp()->handle($this->form(
                'POST',
                '/admin/boards/' . self::SLUG . '/' . $path,
                $ownerId,
                ['idea_id' => (string) $ideaId, 'ideas' => [(string) $ideaId]],
            ));
            $this->assertFrozenAndUnchanged($response);
        }
    }

    public function test_issue_link_create_and_delete_are_rejected(): void
    {
        [$boardId, $ownerId] = $this->frozenBoardWithOwner();
        $ideaId = $this->seedIdea($boardId, $ownerId);

        $post = $this->createApp()->handle($this->form(
            'POST',
            '/admin/boards/' . self::SLUG . "/ideas/{$ideaId}/issue-links/github",
            $ownerId,
        ));
        $this->assertFrozenAndUnchanged($post);

        $delete = $this->createApp()->handle($this->form(
            'DELETE',
            '/admin/boards/' . self::SLUG . "/ideas/{$ideaId}/issue-links/github",
            $ownerId,
        ));
        $this->assertFrozenAndUnchanged($delete);
    }

    public function test_board_import_is_rejected(): void
    {
        [, $ownerId] = $this->frozenBoardWithOwner();

        $response = $this->createApp()->handle($this->form('POST', '/admin/boards/' . self::SLUG . '/import', $ownerId));

        $this->assertFrozenAndUnchanged($response);
    }

    public function test_logo_upload_and_delete_are_rejected(): void
    {
        [$boardId, $ownerId] = $this->frozenBoardWithOwner();

        $upload = $this->createApp()->handle($this->form('POST', '/admin/boards/' . self::SLUG . '/assets/logo', $ownerId));
        $this->assertFrozenAndUnchanged($upload);

        $delete = $this->createApp()->handle($this->form('POST', '/admin/boards/' . self::SLUG . '/assets/logo/delete', $ownerId));
        $this->assertFrozenAndUnchanged($delete);

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM board_assets WHERE board_id = :id', ['id' => $boardId]));
    }

    // -------------------------------------------------------------------------
    // API and MCP-backed writes (token auth, delegate to the guarded actions)
    // -------------------------------------------------------------------------

    /** @param array<string, mixed>|null $body */
    private function bearer(string $method, string $path, string $token, ?array $body = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withHeader('Authorization', 'Bearer ' . $token);

        return $body !== null ? $request->withHeader('Content-Type', 'application/json')->withParsedBody($body) : $request;
    }

    public function test_api_idea_writes_are_rejected_on_a_frozen_board(): void
    {
        [$boardId, $ownerId] = $this->frozenBoardWithOwner();
        $accountId = $this->defaultAccountId();
        $ideaId    = $this->seedIdea($boardId, $ownerId, 'Api idea');
        $otherId   = $this->seedIdea($boardId, $ownerId, 'Api target');
        $tagId     = $this->insertTag($boardId, 'Bug');
        $this->assignTag($ideaId, $tagId);

        $pair  = (new TokenVault())->generate();
        $this->insertApiToken($accountId, $boardId, $ownerId, $pair['hash'], 'Matrix moderate', scope: 'moderate');
        $token = $pair['token'];

        $calls = [
            ['POST', "/api/v1/ideas/{$ideaId}/vote", ['value' => 'up']],
            ['POST', "/api/v1/ideas/{$ideaId}/comments", ['body' => 'From the agent.']],
            ['POST', "/api/v1/ideas/{$ideaId}/status", ['status' => 'planned']],
            ['POST', "/api/v1/ideas/{$ideaId}/tags", ['tag_id' => $this->insertTag($boardId, 'Feature')]],
            ['DELETE', "/api/v1/ideas/{$ideaId}/tags/{$tagId}", null],
            ['POST', "/api/v1/ideas/{$ideaId}/merge", ['target_id' => $otherId]],
            ['POST', "/api/v1/ideas/{$ideaId}/unmerge", null],
        ];

        $app = $this->createApp();
        foreach ($calls as [$method, $path, $body]) {
            $response = $app->handle($this->bearer($method, $path, $token, $body));
            self::assertSame(423, $response->getStatusCode(), $method . ' ' . $path . ' ' . $response->getBody());
        }

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM votes WHERE idea_id = :id', ['id' => $ideaId]));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM comments WHERE idea_id = :id', ['id' => $ideaId]));
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM idea_tag_assignments WHERE idea_id = :id AND tag_id = :tag', ['id' => $ideaId, 'tag' => $tagId]));
        self::assertSame('open', $this->conn->fetchOne('SELECT status FROM ideas WHERE id = :id', ['id' => $ideaId]));
    }

    public function test_api_idea_create_is_rejected_via_mcp_path_too(): void
    {
        [$boardId, $ownerId] = $this->frozenBoardWithOwner();
        $pair  = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $ownerId, $pair['hash'], 'Matrix write', scope: 'write');

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'create_idea',
                'arguments' => ['title' => 'Agent idea', 'body' => 'Long enough description.'],
            ],
        ]);
        $request = $this->bearer('POST', '/api/v1/mcp', $pair['token'])
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream((string) $payload));
        $response = $this->createApp()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('board_frozen', (string) $response->getBody());
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM ideas WHERE board_id = :id', ['id' => $boardId]));
    }

    // -------------------------------------------------------------------------
    // Deliberately open on a frozen board (owner rule and W9 decisions)
    // -------------------------------------------------------------------------

    public function test_owner_can_still_delete_a_frozen_board(): void
    {
        [, $ownerId] = $this->frozenBoardWithOwner();

        $response = $this->createApp()->handle($this->form('POST', '/admin/boards/' . self::SLUG . '/delete', $ownerId, ['confirm_slug' => self::SLUG]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertFalse($this->conn->fetchOne('SELECT id FROM boards WHERE slug = :slug', ['slug' => self::SLUG]));
    }

    public function test_moderator_takedown_of_a_comment_stays_open_on_a_frozen_board(): void
    {
        [$boardId, $ownerId] = $this->frozenBoardWithOwner();
        $authorId  = $this->insertUser('matrix-takedown-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Spammy comment.');

        $response = $this->createApp()->handle($this->form(
            'POST',
            '/' . self::SLUG . "/ideas/{$ideaId}/comments/{$commentId}/delete",
            $ownerId,
            ['reason_code' => 'illegal_copyright', 'reason_note' => 'Reposted without permission.'],
        ));

        self::assertNotSame(423, $response->getStatusCode(), 'Takedown stays open (W9); owner confirmation pending (D21).');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_moderator_takedown_of_an_idea_stays_open_on_a_frozen_board(): void
    {
        [$boardId, $ownerId] = $this->frozenBoardWithOwner();
        $authorId = $this->insertUser('matrix-idea-takedown-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        $response = $this->createApp()->handle($this->form(
            'POST',
            '/' . self::SLUG . "/ideas/{$ideaId}/moderate",
            $ownerId,
            ['reason_code' => 'illegal_copyright', 'reason_note' => 'Reposted without permission.'],
        ));

        self::assertNotSame(423, $response->getStatusCode(), 'Takedown stays open (W9); owner confirmation pending (D21).');
    }

    public function test_user_block_on_a_frozen_board_stays_open(): void
    {
        [, $ownerId] = $this->frozenBoardWithOwner();
        $targetId = $this->insertUser('matrix-block-target@example.com');

        $response = $this->createApp()->handle($this->form(
            'POST',
            '/admin/boards/' . self::SLUG . '/block',
            $ownerId,
            ['user_id' => (string) $targetId, 'blocked' => '1', 'scope' => 'board'],
        ));

        self::assertNotSame(423, $response->getStatusCode(), 'User block is a takedown; stays open (W9); owner confirmation pending (D21).');
    }
}
