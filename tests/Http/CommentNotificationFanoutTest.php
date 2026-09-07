<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the comment notification fan-out: posting a comment
 * creates 'idea_comment'/'thread_reply' rows for the right recipients, with
 * no self-notifications and no duplicates for a user who is both the idea
 * author and a prior commenter.
 *
 * AC coverage:
 *   Story 8  — Commenting on your own idea creates no self-notification.
 *   Story 9  — Replying in your own thread creates no self thread_reply.
 *   Story 10 — A user who is both idea author and a prior commenter gets
 *              exactly one notification (idea_comment), not two.
 *   Story 15 — A blocked user receives no notification from that account.
 *   Cross-tenant — notifications never leak account_id from a foreign account.
 */
final class CommentNotificationFanoutTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postComment(string $slug, int $ideaId, string $body, ?int $userId): ServerRequestInterface
    {
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

    public function test_commenting_on_someone_elses_idea_notifies_the_idea_author(): void
    {
        $boardId  = $this->insertBoard('fanout-basic-board');
        $authorId = $this->insertUser('fanout-basic-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'My great idea');
        $commenterId = $this->insertUser('fanout-basic-commenter@example.com');

        $response = $this->createApp()->handle(
            $this->postComment('fanout-basic-board', $ideaId, 'Nice work!', $commenterId),
        );
        self::assertSame(201, $response->getStatusCode());

        $notes = $this->notificationsForUser($authorId);
        self::assertCount(1, $notes);
        self::assertSame('idea_comment', $notes[0]['type']);
        self::assertSame($this->defaultAccountId(), (int) $notes[0]['account_id']);
        self::assertStringContainsString('My great idea', $notes[0]['body']);
        self::assertStringContainsString('/fanout-basic-board/idea/' . $ideaId, (string) $notes[0]['link_path']);
    }

    // -------------------------------------------------------------------------
    // Story 8 — no self-notification when commenting on your own idea
    // -------------------------------------------------------------------------

    public function test_commenting_on_own_idea_creates_no_self_notification(): void
    {
        $boardId = $this->insertBoard('fanout-own-idea-board');
        $authorId = $this->insertUser('fanout-own-idea-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $authorId);

        $response = $this->createApp()->handle(
            $this->postComment('fanout-own-idea-board', $ideaId, 'Following up on my own idea', $authorId),
        );
        self::assertSame(201, $response->getStatusCode());

        self::assertSame([], $this->notificationsForUser($authorId));
    }

    // -------------------------------------------------------------------------
    // Story 9 — no self thread_reply when replying in your own thread
    // -------------------------------------------------------------------------

    public function test_replying_in_own_thread_creates_no_self_thread_reply(): void
    {
        $boardId    = $this->insertBoard('fanout-own-thread-board');
        $ideaAuthorId = $this->insertUser('fanout-own-thread-idea-author@example.com');
        $commenterId  = $this->insertUser('fanout-own-thread-commenter@example.com');
        $ideaId       = $this->seedIdea($boardId, $ideaAuthorId);

        $app = $this->createApp();
        $app->handle($this->postComment('fanout-own-thread-board', $ideaId, 'First reply', $commenterId));
        // Someone else replies in between — required by the anti-spam rule
        // (no two consecutive comments by the same author) before the same
        // commenter can post again in this thread.
        $app->handle($this->postComment('fanout-own-thread-board', $ideaId, 'Idea author chimes in', $ideaAuthorId));
        // Clear notifications from setup so far, then have the SAME commenter
        // reply again — they must not notify themselves.
        $this->conn->executeStatement('DELETE FROM notifications');

        $response = $app->handle(
            $this->postComment('fanout-own-thread-board', $ideaId, 'Second reply from same commenter', $commenterId),
        );
        self::assertSame(201, $response->getStatusCode());

        self::assertSame([], $this->notificationsForUser($commenterId));
        // The idea author (not the commenter) IS notified again for the second reply.
        $authorNotes = $this->notificationsForUser($ideaAuthorId);
        self::assertCount(1, $authorNotes);
        self::assertSame('idea_comment', $authorNotes[0]['type']);
    }

    // -------------------------------------------------------------------------
    // Story 10 — idea author who is also a prior commenter gets exactly ONE
    // notification (dedup), not both idea_comment and thread_reply.
    // -------------------------------------------------------------------------

    public function test_idea_author_who_also_commented_gets_exactly_one_notification(): void
    {
        $boardId  = $this->insertBoard('fanout-dedup-board');
        $authorId = $this->insertUser('fanout-dedup-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        $app = $this->createApp();
        // The idea author also comments on their own idea (no self-notification, per Story 8).
        $app->handle($this->postComment('fanout-dedup-board', $ideaId, 'Author follow-up', $authorId));

        $thirdPartyId = $this->insertUser('fanout-dedup-third-party@example.com');
        $response = $app->handle(
            $this->postComment('fanout-dedup-board', $ideaId, 'Third party reply', $thirdPartyId),
        );
        self::assertSame(201, $response->getStatusCode());

        $notes = $this->notificationsForUser($authorId);
        self::assertCount(1, $notes, 'Idea author must receive exactly one notification, not one per role (author + prior commenter).');
        self::assertSame('idea_comment', $notes[0]['type']);
    }

    public function test_prior_commenter_gets_thread_reply_when_someone_else_replies(): void
    {
        $boardId  = $this->insertBoard('fanout-thread-reply-board');
        $authorId = $this->insertUser('fanout-thread-reply-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);
        $firstCommenterId = $this->insertUser('fanout-thread-reply-first@example.com');
        $secondCommenterId = $this->insertUser('fanout-thread-reply-second@example.com');

        $app = $this->createApp();
        $app->handle($this->postComment('fanout-thread-reply-board', $ideaId, 'First reply', $firstCommenterId));
        $this->conn->executeStatement('DELETE FROM notifications');

        $response = $app->handle(
            $this->postComment('fanout-thread-reply-board', $ideaId, 'Second reply', $secondCommenterId),
        );
        self::assertSame(201, $response->getStatusCode());

        $firstCommenterNotes = $this->notificationsForUser($firstCommenterId);
        self::assertCount(1, $firstCommenterNotes);
        self::assertSame('thread_reply', $firstCommenterNotes[0]['type']);

        $authorNotes = $this->notificationsForUser($authorId);
        self::assertCount(1, $authorNotes);
        self::assertSame('idea_comment', $authorNotes[0]['type']);

        // The replying user themselves is never notified.
        self::assertSame([], $this->notificationsForUser($secondCommenterId));
    }

    // -------------------------------------------------------------------------
    // Story 15 — a blocked user receives no notification
    // -------------------------------------------------------------------------

    public function test_blocked_idea_author_receives_no_notification(): void
    {
        $boardId  = $this->insertBoard('fanout-blocked-board');
        $accountId = $this->defaultAccountId();
        $authorId = $this->insertUser('fanout-blocked-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);
        $commenterId = $this->insertUser('fanout-blocked-commenter@example.com');

        $this->conn->insert('blocked_users', [
            'account_id' => $accountId,
            'user_id'    => $authorId,
            'board_id'   => null,
            'created_by' => $commenterId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $response = $this->createApp()->handle(
            $this->postComment('fanout-blocked-board', $ideaId, 'Should not notify blocked author', $commenterId),
        );
        self::assertSame(201, $response->getStatusCode());

        self::assertSame([], $this->notificationsForUser($authorId));
    }

    // -------------------------------------------------------------------------
    // Cross-tenant isolation — a 'user'-scoped notification created for a
    // user in one account is never visible to a user without membership in
    // that account, even though `notifications` is one shared table
    // (structural account-scoping, see CLAUDE.md "Cross-Tenant-Leak").
    // -------------------------------------------------------------------------

    public function test_user_scoped_notification_from_own_account_carries_that_accounts_id(): void
    {
        $boardId  = $this->insertBoard('fanout-scope-board');
        $accountId = $this->defaultAccountId();
        $authorId = $this->insertUser('fanout-scope-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);
        $commenterId = $this->insertUser('fanout-scope-commenter@example.com');

        $response = $this->createApp()->handle(
            $this->postComment('fanout-scope-board', $ideaId, 'Scoped comment', $commenterId),
        );
        self::assertSame(201, $response->getStatusCode());

        $notes = $this->notificationsForUser($authorId);
        self::assertCount(1, $notes);
        self::assertSame($accountId, (int) $notes[0]['account_id']);
    }

    public function test_user_scoped_notification_never_visible_to_an_uninvolved_user(): void
    {
        $boardId  = $this->insertBoard('fanout-uninvolved-board');
        $authorId = $this->insertUser('fanout-uninvolved-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);
        $commenterId    = $this->insertUser('fanout-uninvolved-commenter@example.com');
        $uninvolvedId   = $this->insertUser('fanout-uninvolved-bystander@example.com');

        $this->createApp()->handle(
            $this->postComment('fanout-uninvolved-board', $ideaId, 'Not for the bystander', $commenterId),
        );

        $notifications = new \Votepit\Persistence\NotificationRepository($this->conn);
        $authorInbox      = $notifications->listForUser($authorId);
        $uninvolvedInbox   = $notifications->listForUser($uninvolvedId);

        self::assertCount(1, $authorInbox);
        self::assertSame([], $uninvolvedInbox, 'A user-scoped notification must never leak into an uninvolved users inbox.');

        $notificationId = (int) $authorInbox[0]['id'];
        self::assertTrue($notifications->isVisibleToUser($notificationId, $authorId));
        self::assertFalse($notifications->isVisibleToUser($notificationId, $uninvolvedId));
    }
}
