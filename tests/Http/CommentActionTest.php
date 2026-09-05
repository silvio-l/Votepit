<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for comment CRUD:
 *   - POST /{board}/ideas/{id}/comments (create)
 *   - GET  /{board}/ideas/{id} includes the comment list (read)
 *   - POST /{board}/ideas/{id}/comments/{commentId}/delete (admin moderation)
 *
 * All assertions run exclusively through the HTTP seam (AppFactory::create),
 * the identical pipeline to production.
 *
 * AC coverage:
 *   AC1 — A logged-in voter can post a comment on an idea.
 *   AC2 — The comment appears for other users of the same board (detail read path).
 *   AC3 — Cross-board/cross-account isolation: foreign idea/foreign board → 404,
 *          no comment is created/appears.
 *   AC4 — An admin can remove a comment (hard delete).
 *   AC5 — Anti-spam: the same author cannot post two comments in a row on
 *          the same idea (only when nobody else has replied in between).
 *   AC6 — The author can edit their own comment within
 *          CommentUpdateAction::EDIT_WINDOW_SECONDS of posting; expired,
 *          foreign-author, anon and cross-board edits are all rejected.
 */
final class CommentActionTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postComment(
        string $slug,
        int $ideaId,
        string $body,
        ?int $userId,
    ): ServerRequestInterface {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/comments')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'body' => $body]);
    }

    private function postCommentNoCsrf(string $slug, int $ideaId, string $body, ?int $userId): ServerRequestInterface
    {
        $cookies = [];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/comments')
            ->withCookieParams($cookies)
            ->withParsedBody(['body' => $body]);
    }

    private function postDeleteComment(
        string $slug,
        int $ideaId,
        int $commentId,
        ?int $userId,
    ): ServerRequestInterface {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/comments/' . $commentId . '/delete')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token]);
    }

    private function getDetail(string $slug, int $ideaId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $slug . '/ideas/' . $ideaId);
    }

    private function postEditComment(
        string $slug,
        int $ideaId,
        int $commentId,
        string $body,
        ?int $userId,
    ): ServerRequestInterface {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/comments/' . $commentId . '/edit')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'body' => $body]);
    }

    // -------------------------------------------------------------------------
    // AC1 — A logged-in voter posts a comment
    // -------------------------------------------------------------------------

    public function test_logged_in_user_can_post_comment(): void
    {
        $boardId = $this->insertBoard('comment-create-board');
        $userId  = $this->insertUser('commenter@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle(
            $this->postComment('comment-create-board', $ideaId, 'Great idea!', $userId),
        );

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        self::assertIsInt($data['id'] ?? null);

        $row = $this->conn->fetchAssociative(
            'SELECT idea_id, author_id, body FROM comments WHERE id = :id',
            ['id' => $data['id']],
        );
        self::assertIsArray($row);
        self::assertSame($ideaId, (int) $row['idea_id']);
        self::assertSame($userId, (int) $row['author_id']);
        self::assertSame('Great idea!', $row['body']);
    }

    public function test_anon_cannot_post_comment(): void
    {
        $boardId = $this->insertBoard('comment-anon-board');
        $userId  = $this->insertUser('anon-idea-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle(
            $this->postComment('comment-anon-board', $ideaId, 'Anonymous attempt', null),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_empty_comment_body_returns_422(): void
    {
        $boardId = $this->insertBoard('comment-empty-board');
        $userId  = $this->insertUser('empty-commenter@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle(
            $this->postComment('comment-empty-board', $ideaId, '   ', $userId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('validation_error', $data['error']['key'] ?? null);
    }

    public function test_comment_too_long_returns_422(): void
    {
        $boardId = $this->insertBoard('comment-toolong-board');
        $userId  = $this->insertUser('toolong-commenter@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle(
            $this->postComment('comment-toolong-board', $ideaId, str_repeat('a', 2001), $userId),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_comment_without_csrf_returns_403_and_does_not_persist(): void
    {
        $boardId = $this->insertBoard('comment-nocsrf-board');
        $userId  = $this->insertUser('nocsrf-commenter@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle(
            $this->postCommentNoCsrf('comment-nocsrf-board', $ideaId, 'Should not arrive', $userId),
        );

        self::assertSame(403, $response->getStatusCode());
        $count = $this->conn->fetchOne('SELECT COUNT(*) FROM comments WHERE idea_id = :id', ['id' => $ideaId]);
        self::assertSame(0, (int) $count);
    }

    public function test_blocked_user_cannot_post_comment(): void
    {
        $boardId   = $this->insertBoard('comment-blocked-board');
        $blockedId = $this->insertUser('comment-blocked@example.com', ['is_blocked' => 1]);
        $ideaId    = $this->seedIdea($boardId, $blockedId);

        $response = $this->createApp()->handle(
            $this->postComment('comment-blocked-board', $ideaId, 'Should be blocked', $blockedId),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_comment_create_audit_log_is_written(): void
    {
        $boardId = $this->insertBoard('comment-log-board');
        $userId  = $this->insertUser('log-commenter@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $this->createApp()->handle($this->postComment('comment-log-board', $ideaId, 'Audit test', $userId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('comment.created', $log);
    }

    // -------------------------------------------------------------------------
    // AC2 — Comment appears in the idea detail read path (also for other users)
    // -------------------------------------------------------------------------

    public function test_comment_appears_in_idea_detail_for_other_users(): void
    {
        $boardId = $this->insertBoard('comment-detail-board');
        $authorId = $this->insertUser('detail-author@example.com');
        $readerId = $this->insertUser('detail-reader@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        $app = $this->createApp();
        $app->handle($this->postComment('comment-detail-board', $ideaId, 'Visible comment', $authorId));

        $response = $app->handle($this->getDetail('comment-detail-board', $ideaId));
        self::assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody(), true);
        $bodies = array_column($data['comments'] ?? [], 'body');
        self::assertContains('Visible comment', $bodies);

        // Also identically visible for a different (logged-in) reader (no read gate).
        unset($readerId);
    }

    public function test_multiple_comments_listed_in_chronological_order(): void
    {
        $boardId = $this->insertBoard('comment-order-board');
        $userId  = $this->insertUser('order-commenter@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $this->seedComment($ideaId, $userId, 'First comment', ['created_at' => '2026-01-01 10:00:00']);
        $this->seedComment($ideaId, $userId, 'Second comment', ['created_at' => '2026-01-01 11:00:00']);

        $response = $this->createApp()->handle($this->getDetail('comment-order-board', $ideaId));
        $data     = json_decode((string) $response->getBody(), true);
        $bodies   = array_column($data['comments'] ?? [], 'body');

        self::assertSame(['First comment', 'Second comment'], $bodies);
    }

    // -------------------------------------------------------------------------
    // AC3 — Cross-board isolation: idea from a foreign board → 404, no comment
    // -------------------------------------------------------------------------

    public function test_comment_on_idea_from_other_board_returns_404_and_does_not_persist(): void
    {
        $boardId1 = $this->insertBoard('comment-b1-board');
        $this->insertBoard('comment-b2-board');
        $userId   = $this->insertUser('cross-board-commenter@example.com');
        $ideaId   = $this->seedIdea($boardId1, $userId);

        $response = $this->createApp()->handle(
            $this->postComment('comment-b2-board', $ideaId, 'Should not arrive', $userId),
        );

        self::assertSame(404, $response->getStatusCode());
        $count = $this->conn->fetchOne('SELECT COUNT(*) FROM comments WHERE idea_id = :id', ['id' => $ideaId]);
        self::assertSame(0, (int) $count);
    }

    // -------------------------------------------------------------------------
    // AC3 — Cross-account isolation: board of a foreign account → 404
    // -------------------------------------------------------------------------

    public function test_comment_on_idea_in_foreign_account_board_returns_404(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-foreign-comments', 'name' => 'Foreign Account']);
        $foreignBoardId   = $this->insertBoard('foreign-comment-board', ['account_id' => $foreignAccountId]);
        $foreignAuthorId  = $this->insertUser('foreign-idea-author@example.com');
        $foreignIdeaId    = $this->seedIdea($foreignBoardId, $foreignAuthorId);

        // Default-account owner tries to access the foreign-account idea via
        // the default context — must structurally be 404 (no board findable
        // in the resolved account context).
        $ownerId = $this->insertUser('default-owner-comments@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle(
            $this->postComment('foreign-comment-board', $foreignIdeaId, 'Cross-account attempt', $ownerId),
        );

        self::assertSame(404, $response->getStatusCode());
        $count = $this->conn->fetchOne('SELECT COUNT(*) FROM comments WHERE idea_id = :id', ['id' => $foreignIdeaId]);
        self::assertSame(0, (int) $count);
    }

    // -------------------------------------------------------------------------
    // AC4 — Admin moderation: remove a comment
    // -------------------------------------------------------------------------

    public function test_admin_can_delete_comment(): void
    {
        $boardId = $this->insertBoard('comment-mod-board');
        $adminId = $this->insertUser('mod-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId  = $this->insertUser('mod-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Comment to be removed');

        $response = $this->createApp()->handle(
            $this->postDeleteComment('comment-mod-board', $ideaId, $commentId, $adminId),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);

        $row = $this->conn->fetchAssociative('SELECT id FROM comments WHERE id = :id', ['id' => $commentId]);
        self::assertFalse($row, 'Comment must be deleted from the DB after the moderation delete.');
    }

    public function test_non_admin_cannot_delete_comment(): void
    {
        $boardId   = $this->insertBoard('comment-mod-403-board');
        $authorId  = $this->insertUser('mod-403-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Stays in place');

        $response = $this->createApp()->handle(
            $this->postDeleteComment('comment-mod-403-board', $ideaId, $commentId, $authorId),
        );

        self::assertSame(403, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT id FROM comments WHERE id = :id', ['id' => $commentId]);
        self::assertIsArray($row, 'Comment must remain without the admin role.');
    }

    public function test_delete_comment_from_other_board_returns_404(): void
    {
        $boardId1 = $this->insertBoard('comment-mod-b1-board');
        $this->insertBoard('comment-mod-b2-board');
        $adminId = $this->insertUser('mod-cross-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId    = $this->seedIdea($boardId1, $adminId);
        $commentId = $this->seedComment($ideaId, $adminId, 'Foreign board');

        $response = $this->createApp()->handle(
            $this->postDeleteComment('comment-mod-b2-board', $ideaId, $commentId, $adminId),
        );

        self::assertSame(404, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT id FROM comments WHERE id = :id', ['id' => $commentId]);
        self::assertIsArray($row);
    }

    public function test_delete_nonexistent_comment_returns_404(): void
    {
        $boardId = $this->insertBoard('comment-mod-ne-board');
        $adminId = $this->insertUser('mod-ne-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdea($boardId, $adminId);

        $response = $this->createApp()->handle(
            $this->postDeleteComment('comment-mod-ne-board', $ideaId, 99999, $adminId),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_delete_comment_audit_log_is_written(): void
    {
        $boardId = $this->insertBoard('comment-mod-log-board');
        $adminId = $this->insertUser('mod-log-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId    = $this->seedIdea($boardId, $adminId);
        $commentId = $this->seedComment($ideaId, $adminId, 'Audit test');

        $this->createApp()->handle($this->postDeleteComment('comment-mod-log-board', $ideaId, $commentId, $adminId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('comment.moderated_delete', $log);
    }

    // -------------------------------------------------------------------------
    // AC5 — Anti-spam: no two consecutive comments by the same author
    // -------------------------------------------------------------------------

    public function test_same_user_cannot_post_two_comments_in_a_row(): void
    {
        $boardId = $this->insertBoard('comment-consecutive-board');
        $userId  = $this->insertUser('consecutive@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $app = $this->createApp();
        $first = $app->handle($this->postComment('comment-consecutive-board', $ideaId, 'First comment', $userId));
        self::assertSame(201, $first->getStatusCode());

        $second = $app->handle($this->postComment('comment-consecutive-board', $ideaId, 'Second comment', $userId));

        self::assertSame(422, $second->getStatusCode());
        $data = json_decode((string) $second->getBody(), true);
        self::assertSame('consecutive_comment', $data['error']['key'] ?? null);

        $count = $this->conn->fetchOne('SELECT COUNT(*) FROM comments WHERE idea_id = :id', ['id' => $ideaId]);
        self::assertSame(1, (int) $count);
    }

    public function test_different_user_can_comment_after_another_users_comment(): void
    {
        $boardId  = $this->insertBoard('comment-interleave-board');
        $userAId  = $this->insertUser('interleave-a@example.com');
        $userBId  = $this->insertUser('interleave-b@example.com');
        $ideaId   = $this->seedIdea($boardId, $userAId);

        $app = $this->createApp();
        $app->handle($this->postComment('comment-interleave-board', $ideaId, 'From A', $userAId));
        $response = $app->handle($this->postComment('comment-interleave-board', $ideaId, 'From B', $userBId));

        self::assertSame(201, $response->getStatusCode());
    }

    public function test_same_user_can_comment_again_after_someone_else_replied(): void
    {
        $boardId = $this->insertBoard('comment-reinterleave-board');
        $userAId = $this->insertUser('reinterleave-a@example.com');
        $userBId = $this->insertUser('reinterleave-b@example.com');
        $ideaId  = $this->seedIdea($boardId, $userAId);

        $app = $this->createApp();
        $app->handle($this->postComment('comment-reinterleave-board', $ideaId, 'From A #1', $userAId));
        $app->handle($this->postComment('comment-reinterleave-board', $ideaId, 'From B', $userBId));
        $response = $app->handle($this->postComment('comment-reinterleave-board', $ideaId, 'From A #2', $userAId));

        self::assertSame(201, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC6 — Author edit within the 60-second window
    // -------------------------------------------------------------------------

    public function test_author_can_edit_own_comment_within_window(): void
    {
        $boardId   = $this->insertBoard('comment-edit-board');
        $authorId  = $this->insertUser('edit-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Original text');

        $response = $this->createApp()->handle(
            $this->postEditComment('comment-edit-board', $ideaId, $commentId, 'Fixed typo', $authorId),
        );

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT body, edited_at FROM comments WHERE id = :id', ['id' => $commentId]);
        self::assertIsArray($row);
        self::assertSame('Fixed typo', $row['body']);
        self::assertNotNull($row['edited_at']);
    }

    public function test_edit_after_window_expires_returns_422(): void
    {
        $boardId   = $this->insertBoard('comment-edit-expired-board');
        $authorId  = $this->insertUser('edit-expired-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $oldCreatedAt = (new \DateTimeImmutable('-90 seconds'))->format('Y-m-d H:i:s');
        $commentId = $this->seedComment($ideaId, $authorId, 'Original text', ['created_at' => $oldCreatedAt]);

        $response = $this->createApp()->handle(
            $this->postEditComment('comment-edit-expired-board', $ideaId, $commentId, 'Too late', $authorId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('edit_window_expired', $data['error']['key'] ?? null);

        $row = $this->conn->fetchAssociative('SELECT body FROM comments WHERE id = :id', ['id' => $commentId]);
        self::assertIsArray($row);
        self::assertSame('Original text', $row['body']);
    }

    public function test_non_author_cannot_edit_comment(): void
    {
        $boardId   = $this->insertBoard('comment-edit-forbidden-board');
        $authorId  = $this->insertUser('edit-forbidden-author@example.com');
        $otherId   = $this->insertUser('edit-forbidden-other@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Original text');

        $response = $this->createApp()->handle(
            $this->postEditComment('comment-edit-forbidden-board', $ideaId, $commentId, 'Hijacked', $otherId),
        );

        self::assertSame(403, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT body FROM comments WHERE id = :id', ['id' => $commentId]);
        self::assertIsArray($row);
        self::assertSame('Original text', $row['body']);
    }

    public function test_anon_cannot_edit_comment(): void
    {
        $boardId   = $this->insertBoard('comment-edit-anon-board');
        $authorId  = $this->insertUser('edit-anon-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Original text');

        $response = $this->createApp()->handle(
            $this->postEditComment('comment-edit-anon-board', $ideaId, $commentId, 'Should fail', null),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_edit_comment_from_other_board_returns_404(): void
    {
        $boardId1  = $this->insertBoard('comment-edit-b1-board');
        $this->insertBoard('comment-edit-b2-board');
        $authorId  = $this->insertUser('edit-cross-board-author@example.com');
        $ideaId    = $this->seedIdea($boardId1, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Original text');

        $response = $this->createApp()->handle(
            $this->postEditComment('comment-edit-b2-board', $ideaId, $commentId, 'Should not apply', $authorId),
        );

        self::assertSame(404, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT body FROM comments WHERE id = :id', ['id' => $commentId]);
        self::assertIsArray($row);
        self::assertSame('Original text', $row['body']);
    }

    public function test_edit_comment_empty_body_returns_422(): void
    {
        $boardId   = $this->insertBoard('comment-edit-empty-board');
        $authorId  = $this->insertUser('edit-empty-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Original text');

        $response = $this->createApp()->handle(
            $this->postEditComment('comment-edit-empty-board', $ideaId, $commentId, '   ', $authorId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('validation_error', $data['error']['key'] ?? null);
    }

    public function test_edit_comment_audit_log_is_written(): void
    {
        $boardId   = $this->insertBoard('comment-edit-log-board');
        $authorId  = $this->insertUser('edit-log-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Original text');

        $this->createApp()->handle(
            $this->postEditComment('comment-edit-log-board', $ideaId, $commentId, 'Edited', $authorId),
        );

        $log = $this->readAuditLog();
        self::assertStringContainsString('comment.edited', $log);
    }
}
