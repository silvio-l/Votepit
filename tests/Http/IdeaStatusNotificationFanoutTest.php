<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the idea-status-changed notification fan-out: an
 * admin-triggered status change creates 'idea_status_changed' rows for
 * the idea's author + every distinct voter + every distinct commenter,
 * deduplicated, excluding the triggering admin — same "no central event
 * bus" fan-out pattern as CommentNotificationFanoutTest.
 */
final class IdeaStatusNotificationFanoutTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postStatus(string $slug, int $ideaId, string $status, ?int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/status')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'status' => $status]);
    }

    /** @return list<array<string, mixed>> */
    private function notificationsForUser(int $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            "SELECT scope, account_id, user_id, type, title, body, link_path
             FROM notifications
             WHERE scope = 'user' AND user_id = :user_id
             ORDER BY id ASC",
            ['user_id' => $userId],
        );

        return $rows;
    }

    private function seedAdmin(string $email): int
    {
        $adminId = $this->insertUser($email, ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');

        return $adminId;
    }

    // -------------------------------------------------------------------------
    // Author, voter, commenter all get notified
    // -------------------------------------------------------------------------

    public function test_status_change_notifies_author_voter_and_commenter(): void
    {
        $boardId = $this->insertBoard('status-fanout-basic');
        $adminId = $this->seedAdmin('status-fanout-admin@example.com');
        $authorId = $this->insertUser('status-fanout-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $authorId, 'Great idea');

        $voterId     = $this->insertUser('status-fanout-voter@example.com');
        $commenterId = $this->insertUser('status-fanout-commenter@example.com');
        $this->seedVote($ideaId, $voterId, 1);
        $this->seedComment($ideaId, $commenterId, 'Nice!');

        $response = $this->createApp()->handle(
            $this->postStatus('status-fanout-basic', $ideaId, 'planned', $adminId),
        );
        self::assertSame(200, $response->getStatusCode());

        foreach ([$authorId, $voterId, $commenterId] as $userId) {
            $notes = $this->notificationsForUser($userId);
            self::assertCount(1, $notes, "user {$userId} must receive exactly one notification");
            self::assertSame('idea_status_changed', $notes[0]['type']);
            self::assertSame($this->defaultAccountId(), (int) $notes[0]['account_id']);
            self::assertStringContainsString('Great idea', $notes[0]['body']);
            self::assertStringContainsString('/status-fanout-basic/idea/' . $ideaId, (string) $notes[0]['link_path']);
        }
    }

    // -------------------------------------------------------------------------
    // Dedup — a user with multiple roles (author + voter + commenter) gets
    // exactly one notification, not one per role.
    // -------------------------------------------------------------------------

    public function test_user_with_multiple_roles_gets_exactly_one_notification(): void
    {
        $boardId = $this->insertBoard('status-fanout-dedup');
        $adminId = $this->seedAdmin('status-fanout-dedup-admin@example.com');
        $authorId = $this->insertUser('status-fanout-dedup-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $authorId);

        // The author also votes on and comments on their own idea.
        $this->seedVote($ideaId, $authorId, 1);
        $this->seedComment($ideaId, $authorId, 'My own follow-up');

        $response = $this->createApp()->handle(
            $this->postStatus('status-fanout-dedup', $ideaId, 'planned', $adminId),
        );
        self::assertSame(200, $response->getStatusCode());

        $notes = $this->notificationsForUser($authorId);
        self::assertCount(1, $notes, 'A user with multiple roles must receive exactly one notification, not one per role.');
        self::assertSame('idea_status_changed', $notes[0]['type']);
    }

    // -------------------------------------------------------------------------
    // Self-trigger — the admin who changes the status is never notified
    // themselves, even if they are also the author/voter/commenter.
    // -------------------------------------------------------------------------

    public function test_triggering_admin_receives_no_self_notification(): void
    {
        $boardId = $this->insertBoard('status-fanout-self');
        $adminId = $this->seedAdmin('status-fanout-self-admin@example.com');
        $ideaId  = $this->seedIdea($boardId, $adminId);
        $this->seedVote($ideaId, $adminId, 1);
        $this->seedComment($ideaId, $adminId, 'Admin also commented');

        $response = $this->createApp()->handle(
            $this->postStatus('status-fanout-self', $ideaId, 'planned', $adminId),
        );
        self::assertSame(200, $response->getStatusCode());

        self::assertSame([], $this->notificationsForUser($adminId));
    }

    public function test_triggering_admin_not_notified_even_as_third_party_author(): void
    {
        $boardId = $this->insertBoard('status-fanout-self2');
        $adminId = $this->seedAdmin('status-fanout-self2-admin@example.com');
        $authorId = $this->insertUser('status-fanout-self2-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $authorId);
        // The admin also voted/commented, in addition to triggering the change.
        $this->seedVote($ideaId, $adminId, 1);
        $this->seedComment($ideaId, $adminId, 'Admin note');

        $this->createApp()->handle(
            $this->postStatus('status-fanout-self2', $ideaId, 'planned', $adminId),
        );

        self::assertSame([], $this->notificationsForUser($adminId));
        self::assertCount(1, $this->notificationsForUser($authorId));
    }

    // -------------------------------------------------------------------------
    // No-op — a self→self status change creates zero notification rows.
    // -------------------------------------------------------------------------

    public function test_noop_status_change_creates_no_notifications(): void
    {
        $boardId = $this->insertBoard('status-fanout-noop');
        $adminId = $this->seedAdmin('status-fanout-noop-admin@example.com');
        $authorId = $this->insertUser('status-fanout-noop-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $authorId, 'Test idea', ['status' => 'planned']);
        $voterId = $this->insertUser('status-fanout-noop-voter@example.com');
        $this->seedVote($ideaId, $voterId, 1);

        $response = $this->createApp()->handle(
            $this->postStatus('status-fanout-noop', $ideaId, 'planned', $adminId),
        );
        self::assertSame(200, $response->getStatusCode());

        self::assertSame([], $this->notificationsForUser($authorId));
        self::assertSame([], $this->notificationsForUser($voterId));
    }

    // -------------------------------------------------------------------------
    // Invalid transition — 422, no DB write, no notifications.
    // -------------------------------------------------------------------------

    public function test_invalid_transition_creates_no_notifications(): void
    {
        $boardId = $this->insertBoard('status-fanout-invalid');
        $adminId = $this->seedAdmin('status-fanout-invalid-admin@example.com');
        $authorId = $this->insertUser('status-fanout-invalid-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $authorId, 'Test idea', ['status' => 'declined']);
        $voterId = $this->insertUser('status-fanout-invalid-voter@example.com');
        $this->seedVote($ideaId, $voterId, 1);

        // declined -> done is not a valid transition (see StatusService).
        $response = $this->createApp()->handle(
            $this->postStatus('status-fanout-invalid', $ideaId, 'done', $adminId),
        );
        self::assertSame(422, $response->getStatusCode());

        self::assertSame([], $this->notificationsForUser($authorId));
        self::assertSame([], $this->notificationsForUser($voterId));
    }

    // -------------------------------------------------------------------------
    // Preference gating — notify_idea_status_inapp = 0 suppresses the in-app row.
    // -------------------------------------------------------------------------

    public function test_recipient_with_inapp_flag_off_receives_no_row(): void
    {
        $boardId = $this->insertBoard('status-fanout-pref-off');
        $adminId = $this->seedAdmin('status-fanout-pref-off-admin@example.com');
        $authorId = $this->insertUser('status-fanout-pref-off-author@example.com', [
            'notify_idea_status_inapp' => 0,
        ]);
        $ideaId = $this->seedIdea($boardId, $authorId);

        $response = $this->createApp()->handle(
            $this->postStatus('status-fanout-pref-off', $ideaId, 'planned', $adminId),
        );
        self::assertSame(200, $response->getStatusCode());

        self::assertSame([], $this->notificationsForUser($authorId));
    }
}
