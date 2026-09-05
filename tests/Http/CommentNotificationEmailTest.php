<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the comment-notification EMAIL channel — the part
 * of the comment fan-out (CommentCreateAction) that sends an actual email,
 * distinct from the in-app row already covered by
 * CommentNotificationFanoutTest (issue 01).
 */
final class CommentNotificationEmailTest extends IntegrationTestCase
{
    protected function testConfig(): Config
    {
        return Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'rate_limits'         => [
                'comment:user'              => ['limit' => 100, 'window' => 3600],
                'notification-mail:global'  => ['limit' => 1, 'window' => 3600],
            ],
        ]);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postComment(string $slug, int $ideaId, string $body, int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/comments')
            ->withCookieParams([
                $csrf->cookieName() => $signed,
                'votepit_sess'      => $this->sessionCookie($userId),
            ])
            ->withParsedBody(['_csrf' => $token, 'body' => $body]);
    }

    public function test_email_sent_to_idea_author_with_verified_email_and_flag_on(): void
    {
        $boardId  = $this->insertBoard('mail-basic-board');
        $authorId = $this->insertUser('mail-basic-author@example.com', [
            'notification_email'        => 'author-inbox@example.com',
            'notify_idea_comment_email' => 1,
        ]);
        $ideaId      = $this->seedIdea($boardId, $authorId, 'My mailed idea');
        $commenterId = $this->insertUser('mail-basic-commenter@example.com');
        $mailer      = new InMemoryMailer();

        $response = $this->createApp($mailer)->handle(
            $this->postComment('mail-basic-board', $ideaId, 'Nice work!', $commenterId),
        );
        self::assertSame(201, $response->getStatusCode());

        self::assertSame(1, $mailer->count());
        $sent = $mailer->lastSent();
        self::assertNotNull($sent);
        self::assertSame('author-inbox@example.com', $sent['to']);
        self::assertStringContainsString('My mailed idea', $sent['body']);
    }

    public function test_no_email_when_email_flag_is_off_despite_verified_address(): void
    {
        $boardId  = $this->insertBoard('mail-flag-off-board');
        $authorId = $this->insertUser('mail-flag-off-author@example.com', [
            'notification_email'        => 'author-inbox@example.com',
            'notify_idea_comment_email' => 0,
        ]);
        $ideaId      = $this->seedIdea($boardId, $authorId);
        $commenterId = $this->insertUser('mail-flag-off-commenter@example.com');
        $mailer      = new InMemoryMailer();

        $this->createApp($mailer)->handle(
            $this->postComment('mail-flag-off-board', $ideaId, 'Nice work!', $commenterId),
        );

        self::assertSame(0, $mailer->count());
    }

    public function test_no_email_when_flag_on_but_address_not_yet_confirmed(): void
    {
        $boardId  = $this->insertBoard('mail-unconfirmed-board');
        // Flag on, but notification_email is NULL — never confirmed.
        $authorId = $this->insertUser('mail-unconfirmed-author@example.com', [
            'notify_idea_comment_email' => 1,
        ]);
        $ideaId      = $this->seedIdea($boardId, $authorId);
        $commenterId = $this->insertUser('mail-unconfirmed-commenter@example.com');
        $mailer      = new InMemoryMailer();

        $response = $this->createApp($mailer)->handle(
            $this->postComment('mail-unconfirmed-board', $ideaId, 'Nice work!', $commenterId),
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(0, $mailer->count());
        // In-app notification is unaffected by the email channel being inactive.
        $notes = $this->conn->fetchAllAssociative(
            "SELECT type FROM notifications WHERE scope = 'user' AND user_id = :id",
            ['id' => $authorId],
        );
        self::assertCount(1, $notes);
    }

    public function test_in_app_notification_suppressed_when_inapp_flag_is_off(): void
    {
        $boardId  = $this->insertBoard('mail-inapp-off-board');
        $authorId = $this->insertUser('mail-inapp-off-author@example.com', [
            'notify_idea_comment_inapp' => 0,
        ]);
        $ideaId      = $this->seedIdea($boardId, $authorId);
        $commenterId = $this->insertUser('mail-inapp-off-commenter@example.com');

        $this->createApp()->handle(
            $this->postComment('mail-inapp-off-board', $ideaId, 'Nice work!', $commenterId),
        );

        $notes = $this->conn->fetchAllAssociative(
            "SELECT type FROM notifications WHERE scope = 'user' AND user_id = :id",
            ['id' => $authorId],
        );
        self::assertSame([], $notes);
    }

    public function test_rate_limit_exhaustion_skips_email_but_in_app_notification_still_lands(): void
    {
        // notification-mail:global = 1/window (see testConfig()) — the
        // SECOND eligible email in this test run must be skipped, while
        // BOTH in-app notifications still get created.
        $boardId = $this->insertBoard('mail-ratelimit-board');
        $authorA = $this->insertUser('mail-ratelimit-author-a@example.com', [
            'notification_email'        => 'author-a@example.com',
            'notify_idea_comment_email' => 1,
        ]);
        $authorB = $this->insertUser('mail-ratelimit-author-b@example.com', [
            'notification_email'        => 'author-b@example.com',
            'notify_idea_comment_email' => 1,
        ]);
        $ideaA = $this->seedIdea($boardId, $authorA, 'Idea A');
        $ideaB = $this->seedIdea($boardId, $authorB, 'Idea B');
        $commenterId = $this->insertUser('mail-ratelimit-commenter@example.com');
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $first = $app->handle($this->postComment('mail-ratelimit-board', $ideaA, 'First comment', $commenterId));
        self::assertSame(201, $first->getStatusCode());
        self::assertSame(1, $mailer->count());

        $second = $app->handle($this->postComment('mail-ratelimit-board', $ideaB, 'Second comment', $commenterId));
        self::assertSame(201, $second->getStatusCode(), 'the comment itself must succeed even though the notification mail is rate-limited');
        self::assertSame(1, $mailer->count(), 'the second notification email must be skipped, not queued');

        // Both idea authors still received their in-app notification.
        foreach ([$authorA, $authorB] as $authorId) {
            $notes = $this->conn->fetchAllAssociative(
                "SELECT type FROM notifications WHERE scope = 'user' AND user_id = :id",
                ['id' => $authorId],
            );
            self::assertCount(1, $notes);
        }
    }
}
