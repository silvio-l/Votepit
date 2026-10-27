<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /{board}/ideas/{id}/comments/{commentId}/delete
 * — reason-coded, soft-deleted comment moderation with author notification
 * (board-operator-compliance issue 06). Split out of CommentActionTest's
 * previous AC4 block (hard-delete admin moderation), same split pattern as
 * CommentNotificationEmailTest/CommentNotificationFanoutTest next to
 * CommentActionTest.
 *
 * All assertions run exclusively through the HTTP seam (AppFactory::create).
 */
final class CommentModerationActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function postDeleteComment(
        string $slug,
        int $ideaId,
        int $commentId,
        ?int $userId,
        array $body = ['reason_code' => 'community_rule'],
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
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    private function getDetail(string $slug, int $ideaId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $slug . '/ideas/' . $ideaId);
    }

    // -------------------------------------------------------------------------
    // reason_code required + persisted
    // -------------------------------------------------------------------------

    public function test_missing_reason_code_returns_422_and_leaves_comment_untouched(): void
    {
        $boardId = $this->insertBoard('modreason-missing-board');
        $adminId = $this->insertUser('modreason-missing-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId  = $this->insertUser('modreason-missing-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Please keep me');

        $response = $this->createApp()->handle(
            $this->postDeleteComment('modreason-missing-board', $ideaId, $commentId, $adminId, []),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('validation_error', $data['error']['key'] ?? null);
        self::assertArrayHasKey('reason_code', $data['error']['fields'] ?? []);

        $row = $this->conn->fetchAssociative('SELECT deleted_at FROM comments WHERE id = :id', ['id' => $commentId]);
        self::assertIsArray($row);
        self::assertNull($row['deleted_at']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM content_moderation_actions'));
    }

    public function test_invalid_reason_code_returns_422(): void
    {
        $boardId = $this->insertBoard('modreason-invalid-board');
        $adminId = $this->insertUser('modreason-invalid-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId  = $this->insertUser('modreason-invalid-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Still here');

        $response = $this->createApp()->handle(
            $this->postDeleteComment('modreason-invalid-board', $ideaId, $commentId, $adminId, ['reason_code' => 'not_a_real_reason']),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('reason_code', $data['error']['fields'] ?? []);
    }

    public function test_valid_reason_code_soft_deletes_and_records_moderation_action(): void
    {
        $boardId = $this->insertBoard('modreason-valid-board');
        $adminId = $this->insertUser('modreason-valid-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId  = $this->insertUser('modreason-valid-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Spammy content here');

        $response = $this->createApp()->handle(
            $this->postDeleteComment(
                'modreason-valid-board',
                $ideaId,
                $commentId,
                $adminId,
                ['reason_code' => 'illegal_copyright', 'reason_note' => 'Reposted from elsewhere without permission.'],
            ),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        $moderationActionId = $data['moderation_action_id'] ?? null;
        self::assertIsInt($moderationActionId);

        $comment = $this->conn->fetchAssociative(
            'SELECT deleted_at, moderation_action_id FROM comments WHERE id = :id',
            ['id' => $commentId],
        );
        self::assertIsArray($comment);
        self::assertNotNull($comment['deleted_at'], 'Comment must be soft-deleted, not hard-deleted.');
        self::assertSame($moderationActionId, (int) $comment['moderation_action_id']);

        $action = $this->conn->fetchAssociative(
            'SELECT * FROM content_moderation_actions WHERE id = :id',
            ['id' => $moderationActionId],
        );
        self::assertIsArray($action);
        self::assertSame('comment', $action['entity_type']);
        self::assertSame($commentId, (int) $action['entity_id']);
        self::assertSame($boardId, (int) $action['board_id']);
        self::assertSame($this->defaultAccountId(), (int) $action['account_id']);
        self::assertSame($adminId, (int) $action['actor_user_id']);
        self::assertSame('board_moderator', $action['actor_tier']);
        self::assertSame('illegal_copyright', $action['reason_code']);
        self::assertSame('Reposted from elsewhere without permission.', $action['reason_note']);
        self::assertSame('Spammy content here', $action['content_snapshot']);
        self::assertNotNull($action['created_at']);
        self::assertNotNull($action['purge_after']);
        self::assertGreaterThan((string) $action['created_at'], (string) $action['purge_after']);
    }

    public function test_moderated_comment_excluded_from_public_read_but_row_still_exists(): void
    {
        $boardId = $this->insertBoard('modreason-hidden-board');
        $adminId = $this->insertUser('modreason-hidden-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId  = $this->insertUser('modreason-hidden-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Visible for now');

        $app = $this->createApp();
        $app->handle($this->postDeleteComment('modreason-hidden-board', $ideaId, $commentId, $adminId));

        $detail = $app->handle($this->getDetail('modreason-hidden-board', $ideaId));
        self::assertSame(200, $detail->getStatusCode());
        $data = json_decode((string) $detail->getBody(), true);
        $commentIds = array_map(static fn (array $c): int => (int) $c['id'], $data['comments'] ?? []);
        self::assertNotContains($commentId, $commentIds, 'Soft-deleted comment must not appear in the public read path.');

        $row = $this->conn->fetchAssociative('SELECT id FROM comments WHERE id = :id', ['id' => $commentId]);
        self::assertIsArray($row, 'Soft-deleted comment row must still exist in the DB.');
    }

    // -------------------------------------------------------------------------
    // Author notification
    // -------------------------------------------------------------------------

    public function test_author_with_confirmed_notification_email_is_notified(): void
    {
        $boardId  = $this->insertBoard('modreason-notify-board');
        $adminId  = $this->insertUser('modreason-notify-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId = $this->insertUser('modreason-notify-author@example.com', [
            'notification_email' => 'author-inbox@example.com',
        ]);
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Off to the void');
        $mailer    = new InMemoryMailer();

        $this->createApp($mailer)->handle(
            $this->postDeleteComment('modreason-notify-board', $ideaId, $commentId, $adminId, ['reason_code' => 'illegal_threat']),
        );

        self::assertSame(1, $mailer->count());
        $sent = $mailer->lastSent();
        self::assertNotNull($sent);
        self::assertSame('author-inbox@example.com', $sent['to']);
        self::assertStringContainsString('removed', $sent['body']);
        self::assertStringContainsString('threat', $sent['body']);
        // board-operator-compliance issue 09: the removal mail links to the
        // appeal-submission page. Self-host → unprefixed /appeal/{id}.
        self::assertStringContainsString('/appeal/', $sent['body']);
        self::assertMatchesRegularExpression('#/appeal/\d+#', $sent['body']);
    }

    public function test_author_without_confirmed_notification_email_is_not_notified_and_still_succeeds(): void
    {
        $boardId  = $this->insertBoard('modreason-nonotify-board');
        $adminId  = $this->insertUser('modreason-nonotify-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId = $this->insertUser('modreason-nonotify-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'No inbox on file');
        $mailer    = new InMemoryMailer();

        $response = $this->createApp($mailer)->handle(
            $this->postDeleteComment('modreason-nonotify-board', $ideaId, $commentId, $adminId),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $mailer->count());
    }

    // -------------------------------------------------------------------------
    // Cross-account isolation / role gating / repeat moderation
    // -------------------------------------------------------------------------

    public function test_delete_comment_from_other_board_returns_404(): void
    {
        $boardId1 = $this->insertBoard('modreason-b1-board');
        $this->insertBoard('modreason-b2-board');
        $adminId = $this->insertUser('modreason-cross-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId    = $this->seedIdea($boardId1, $adminId);
        $commentId = $this->seedComment($ideaId, $adminId, 'Foreign board');

        $response = $this->createApp()->handle(
            $this->postDeleteComment('modreason-b2-board', $ideaId, $commentId, $adminId),
        );

        self::assertSame(404, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT deleted_at FROM comments WHERE id = :id', ['id' => $commentId]);
        self::assertIsArray($row);
        self::assertNull($row['deleted_at']);
    }

    public function test_delete_nonexistent_comment_returns_404(): void
    {
        $boardId = $this->insertBoard('modreason-ne-board');
        $adminId = $this->insertUser('modreason-ne-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdea($boardId, $adminId);

        $response = $this->createApp()->handle(
            $this->postDeleteComment('modreason-ne-board', $ideaId, 99999, $adminId),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_non_moderator_member_cannot_delete_comment(): void
    {
        $boardId   = $this->insertBoard('modreason-403-board');
        $authorId  = $this->insertUser('modreason-403-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Stays in place');

        $response = $this->createApp()->handle(
            $this->postDeleteComment('modreason-403-board', $ideaId, $commentId, $authorId),
        );

        self::assertSame(403, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT deleted_at FROM comments WHERE id = :id', ['id' => $commentId]);
        self::assertIsArray($row);
        self::assertNull($row['deleted_at']);
    }

    public function test_moderator_role_can_delete_comment(): void
    {
        $boardId = $this->insertBoard('modreason-moderator-board');
        $modId   = $this->insertUser('modreason-moderator@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');
        $authorId  = $this->insertUser('modreason-moderator-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Removed by a moderator');

        $response = $this->createApp()->handle(
            $this->postDeleteComment('modreason-moderator-board', $ideaId, $commentId, $modId),
        );

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT deleted_at FROM comments WHERE id = :id', ['id' => $commentId]);
        self::assertIsArray($row);
        self::assertNotNull($row['deleted_at']);
    }

    public function test_moderating_an_already_moderated_comment_returns_404(): void
    {
        $boardId = $this->insertBoard('modreason-double-board');
        $adminId = $this->insertUser('modreason-double-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId  = $this->insertUser('modreason-double-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Only moderated once');

        $app   = $this->createApp();
        $first = $app->handle($this->postDeleteComment('modreason-double-board', $ideaId, $commentId, $adminId));
        self::assertSame(200, $first->getStatusCode());

        $second = $app->handle($this->postDeleteComment('modreason-double-board', $ideaId, $commentId, $adminId));
        self::assertSame(404, $second->getStatusCode());

        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM content_moderation_actions'));
    }

    public function test_delete_comment_audit_log_is_written(): void
    {
        $boardId = $this->insertBoard('modreason-log-board');
        $adminId = $this->insertUser('modreason-log-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId    = $this->seedIdea($boardId, $adminId);
        $commentId = $this->seedComment($ideaId, $adminId, 'Audit test');

        $this->createApp()->handle($this->postDeleteComment('modreason-log-board', $ideaId, $commentId, $adminId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('comment.moderated_delete', $log);
    }
}
