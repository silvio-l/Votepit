<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /{board}/ideas/{id}/moderate — reason-coded,
 * soft-deleted idea moderation with author notification
 * (board-operator-compliance issue 07). Idea-side counterpart of
 * CommentModerationActionTest — same coverage shape, plus the idea-specific
 * interaction with IdeaWithdrawAction/IdeaStatusAction/IdeaPinAction on an
 * already soft-deleted idea.
 *
 * All assertions run exclusively through the HTTP seam (AppFactory::create).
 */
final class IdeaModerationActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function postModerateIdea(
        string $slug,
        int $ideaId,
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
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/moderate')
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    private function getDetail(string $slug, int $ideaId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $slug . '/ideas/' . $ideaId);
    }

    private function getBoardHome(string $slug): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $slug);
    }

    /** @param array<string, mixed> $body */
    private function postWithCsrf(string $path, ?int $userId, array $body = []): ServerRequestInterface
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

    // -------------------------------------------------------------------------
    // reason_code required + persisted
    // -------------------------------------------------------------------------

    public function test_missing_reason_code_returns_422_and_leaves_idea_untouched(): void
    {
        $boardId = $this->insertBoard('imodreason-missing-board');
        $adminId = $this->insertUser('imodreason-missing-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId = $this->insertUser('imodreason-missing-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Please keep me');

        $response = $this->createApp()->handle(
            $this->postModerateIdea('imodreason-missing-board', $ideaId, $adminId, []),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('validation_error', $data['error']['key'] ?? null);
        self::assertArrayHasKey('reason_code', $data['error']['fields'] ?? []);

        $row = $this->conn->fetchAssociative('SELECT deleted_at FROM ideas WHERE id = :id', ['id' => $ideaId]);
        self::assertIsArray($row);
        self::assertNull($row['deleted_at']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM content_moderation_actions'));
    }

    public function test_invalid_reason_code_returns_422(): void
    {
        $boardId = $this->insertBoard('imodreason-invalid-board');
        $adminId = $this->insertUser('imodreason-invalid-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId = $this->insertUser('imodreason-invalid-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Still here');

        $response = $this->createApp()->handle(
            $this->postModerateIdea('imodreason-invalid-board', $ideaId, $adminId, ['reason_code' => 'not_a_real_reason']),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('reason_code', $data['error']['fields'] ?? []);
    }

    public function test_valid_reason_code_soft_deletes_and_records_moderation_action(): void
    {
        $boardId = $this->insertBoard('imodreason-valid-board');
        $adminId = $this->insertUser('imodreason-valid-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId = $this->insertUser('imodreason-valid-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Spammy title', ['body' => 'Spammy content here']);

        $response = $this->createApp()->handle(
            $this->postModerateIdea(
                'imodreason-valid-board',
                $ideaId,
                $adminId,
                ['reason_code' => 'illegal_copyright', 'reason_note' => 'Reposted from elsewhere without permission.'],
            ),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        $moderationActionId = $data['moderation_action_id'] ?? null;
        self::assertIsInt($moderationActionId);

        $idea = $this->conn->fetchAssociative(
            'SELECT deleted_at, moderation_action_id FROM ideas WHERE id = :id',
            ['id' => $ideaId],
        );
        self::assertIsArray($idea);
        self::assertNotNull($idea['deleted_at'], 'Idea must be soft-deleted, not hard-deleted.');
        self::assertSame($moderationActionId, (int) $idea['moderation_action_id']);

        $action = $this->conn->fetchAssociative(
            'SELECT * FROM content_moderation_actions WHERE id = :id',
            ['id' => $moderationActionId],
        );
        self::assertIsArray($action);
        self::assertSame('idea', $action['entity_type']);
        self::assertSame($ideaId, (int) $action['entity_id']);
        self::assertSame($boardId, (int) $action['board_id']);
        self::assertSame($this->defaultAccountId(), (int) $action['account_id']);
        self::assertSame($adminId, (int) $action['actor_user_id']);
        self::assertSame('board_moderator', $action['actor_tier']);
        self::assertSame('illegal_copyright', $action['reason_code']);
        self::assertSame('Reposted from elsewhere without permission.', $action['reason_note']);
        self::assertSame("Spammy title\n\nSpammy content here", $action['content_snapshot']);
        self::assertNotNull($action['created_at']);
        self::assertNotNull($action['purge_after']);
        self::assertGreaterThan((string) $action['created_at'], (string) $action['purge_after']);
    }

    public function test_moderated_idea_excluded_from_public_read_but_row_still_exists(): void
    {
        $boardId = $this->insertBoard('imodreason-hidden-board');
        $adminId = $this->insertUser('imodreason-hidden-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId = $this->insertUser('imodreason-hidden-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Visible for now');

        $app = $this->createApp();
        $app->handle($this->postModerateIdea('imodreason-hidden-board', $ideaId, $adminId));

        $detail = $app->handle($this->getDetail('imodreason-hidden-board', $ideaId));
        self::assertSame(404, $detail->getStatusCode(), 'Soft-deleted idea must not be reachable via the detail route.');

        $home = $app->handle($this->getBoardHome('imodreason-hidden-board'));
        self::assertSame(200, $home->getStatusCode());
        $homeData = json_decode((string) $home->getBody(), true);
        $ideaIds  = array_map(static fn (array $i): int => (int) $i['id'], $homeData['ideas'] ?? []);
        self::assertNotContains($ideaId, $ideaIds, 'Soft-deleted idea must not appear in the public board list.');

        $row = $this->conn->fetchAssociative('SELECT id FROM ideas WHERE id = :id', ['id' => $ideaId]);
        self::assertIsArray($row, 'Soft-deleted idea row must still exist in the DB.');
    }

    // -------------------------------------------------------------------------
    // Author notification
    // -------------------------------------------------------------------------

    public function test_author_with_confirmed_notification_email_is_notified(): void
    {
        $boardId  = $this->insertBoard('imodreason-notify-board');
        $adminId  = $this->insertUser('imodreason-notify-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId = $this->insertUser('imodreason-notify-author@example.com', [
            'notification_email' => 'author-inbox@example.com',
        ]);
        $ideaId = $this->seedIdea($boardId, $authorId, 'Off to the void');
        $mailer = new InMemoryMailer();

        $this->createApp($mailer)->handle(
            $this->postModerateIdea('imodreason-notify-board', $ideaId, $adminId, ['reason_code' => 'illegal_threat']),
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
        $boardId  = $this->insertBoard('imodreason-nonotify-board');
        $adminId  = $this->insertUser('imodreason-nonotify-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId = $this->insertUser('imodreason-nonotify-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'No inbox on file');
        $mailer   = new InMemoryMailer();

        $response = $this->createApp($mailer)->handle(
            $this->postModerateIdea('imodreason-nonotify-board', $ideaId, $adminId),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $mailer->count());
    }

    // -------------------------------------------------------------------------
    // Cross-account isolation / role gating / repeat moderation
    // -------------------------------------------------------------------------

    public function test_moderate_idea_from_other_board_returns_404(): void
    {
        $boardId1 = $this->insertBoard('imodreason-b1-board');
        $this->insertBoard('imodreason-b2-board');
        $adminId = $this->insertUser('imodreason-cross-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId = $this->seedIdea($boardId1, $adminId, 'Foreign board');

        $response = $this->createApp()->handle(
            $this->postModerateIdea('imodreason-b2-board', $ideaId, $adminId),
        );

        self::assertSame(404, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT deleted_at FROM ideas WHERE id = :id', ['id' => $ideaId]);
        self::assertIsArray($row);
        self::assertNull($row['deleted_at']);
    }

    public function test_moderate_nonexistent_idea_returns_404(): void
    {
        $this->insertBoard('imodreason-ne-board');
        $adminId = $this->insertUser('imodreason-ne-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');

        $response = $this->createApp()->handle(
            $this->postModerateIdea('imodreason-ne-board', 99999, $adminId),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_non_moderator_member_cannot_moderate_idea(): void
    {
        $boardId  = $this->insertBoard('imodreason-403-board');
        $authorId = $this->insertUser('imodreason-403-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Stays in place');

        $response = $this->createApp()->handle(
            $this->postModerateIdea('imodreason-403-board', $ideaId, $authorId),
        );

        self::assertSame(403, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT deleted_at FROM ideas WHERE id = :id', ['id' => $ideaId]);
        self::assertIsArray($row);
        self::assertNull($row['deleted_at']);
    }

    public function test_moderator_role_can_moderate_idea(): void
    {
        $boardId = $this->insertBoard('imodreason-moderator-board');
        $modId   = $this->insertUser('imodreason-moderator@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');
        $authorId = $this->insertUser('imodreason-moderator-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Removed by a moderator');

        $response = $this->createApp()->handle(
            $this->postModerateIdea('imodreason-moderator-board', $ideaId, $modId),
        );

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT deleted_at FROM ideas WHERE id = :id', ['id' => $ideaId]);
        self::assertIsArray($row);
        self::assertNotNull($row['deleted_at']);
    }

    public function test_moderating_an_already_moderated_idea_returns_404(): void
    {
        $boardId = $this->insertBoard('imodreason-double-board');
        $adminId = $this->insertUser('imodreason-double-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId = $this->insertUser('imodreason-double-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Only moderated once');

        $app   = $this->createApp();
        $first = $app->handle($this->postModerateIdea('imodreason-double-board', $ideaId, $adminId));
        self::assertSame(200, $first->getStatusCode());

        $second = $app->handle($this->postModerateIdea('imodreason-double-board', $ideaId, $adminId));
        self::assertSame(404, $second->getStatusCode());

        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM content_moderation_actions'));
    }

    public function test_moderate_idea_audit_log_is_written(): void
    {
        $boardId = $this->insertBoard('imodreason-log-board');
        $adminId = $this->insertUser('imodreason-log-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId = $this->seedIdea($boardId, $adminId, 'Audit test');

        $this->createApp()->handle($this->postModerateIdea('imodreason-log-board', $ideaId, $adminId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('idea.moderated_delete', $log);
    }

    // -------------------------------------------------------------------------
    // Interaction with other idea actions on an already soft-deleted idea
    // -------------------------------------------------------------------------

    public function test_withdrawing_an_already_moderated_idea_returns_404(): void
    {
        $boardId = $this->insertBoard('imodreason-withdraw-board');
        $adminId = $this->insertUser('imodreason-withdraw-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId = $this->insertUser('imodreason-withdraw-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Already removed');

        $app = $this->createApp();
        $app->handle($this->postModerateIdea('imodreason-withdraw-board', $ideaId, $adminId));

        $response = $app->handle(
            $this->postWithCsrf('/imodreason-withdraw-board/ideas/' . $ideaId . '/withdraw', $authorId),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_changing_status_of_an_already_moderated_idea_returns_404(): void
    {
        $boardId = $this->insertBoard('imodreason-status-board');
        $adminId = $this->insertUser('imodreason-status-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId = $this->insertUser('imodreason-status-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Already removed');

        $app = $this->createApp();
        $app->handle($this->postModerateIdea('imodreason-status-board', $ideaId, $adminId));

        $response = $app->handle(
            $this->postWithCsrf(
                '/imodreason-status-board/ideas/' . $ideaId . '/status',
                $adminId,
                ['status' => 'planned'],
            ),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_pinning_an_already_moderated_idea_returns_404(): void
    {
        $boardId = $this->insertBoard('imodreason-pin-board');
        $adminId = $this->insertUser('imodreason-pin-admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId = $this->insertUser('imodreason-pin-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Already removed');

        $app = $this->createApp();
        $app->handle($this->postModerateIdea('imodreason-pin-board', $ideaId, $adminId));

        $response = $app->handle(
            $this->postWithCsrf(
                '/imodreason-pin-board/ideas/' . $ideaId . '/pin',
                $adminId,
                ['pinned' => true],
            ),
        );

        self::assertSame(404, $response->getStatusCode());
    }
}
