<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the notification-preferences opt-in/verification
 * flow:
 *   PUT    /account/notification-preferences
 *   POST   /account/notification-email
 *   GET    /account/notification-email/confirm
 *   DELETE /account/notification-email
 */
final class NotificationPreferencesActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function authedRequest(string $method, string $uri, int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withCookieParams([
                $csrf->cookieName() => $signed,
                'votepit_sess'      => $this->sessionCookie($userId),
            ])
            ->withHeader('X-CSRF-Token', $token);
    }

    // -------------------------------------------------------------------------
    // GET /account/notification-preferences
    // -------------------------------------------------------------------------

    public function test_get_preferences_reflects_defaults_for_a_fresh_user(): void
    {
        $userId = $this->insertUser('prefs-get-defaults@example.com');

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/account/notification-preferences')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'notification_email' => null,
                'idea_comment_inapp' => true,
                'idea_comment_email' => false,
                'thread_reply_inapp' => true,
                'thread_reply_email' => false,
                'idea_status_inapp'  => true,
                'idea_status_email'  => false,
                'abuse_report_inapp'   => true,
                'abuse_report_email'   => false,
                'support_ticket_inapp' => true,
                'support_ticket_email' => false,
            ],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function test_get_preferences_reflects_a_confirmed_email_and_custom_flags(): void
    {
        $userId = $this->insertUser('prefs-get-custom@example.com', [
            'notification_email'        => 'me@example.com',
            'notify_idea_comment_inapp' => 0,
            'notify_idea_comment_email' => 1,
        ]);

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/account/notification-preferences')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]),
        );

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('me@example.com', $body['notification_email']);
        self::assertFalse($body['idea_comment_inapp']);
        self::assertTrue($body['idea_comment_email']);
    }

    // -------------------------------------------------------------------------
    // PUT /account/notification-preferences
    // -------------------------------------------------------------------------

    public function test_put_preferences_persists_all_four_flags(): void
    {
        $userId = $this->insertUser('prefs-put@example.com');

        $response = $this->createApp()->handle(
            $this->authedRequest('PUT', '/account/notification-preferences', $userId)
                ->withParsedBody([
                    'idea_comment_inapp' => false,
                    'idea_comment_email' => true,
                    'thread_reply_inapp' => true,
                    'thread_reply_email' => true,
                    'idea_status_inapp'  => false,
                    'idea_status_email'  => true,
                    'abuse_report_inapp'   => true,
                    'abuse_report_email'   => true,
                    'support_ticket_inapp' => true,
                    'support_ticket_email' => true,
                ]),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(
            [
                'ok'                 => true,
                'idea_comment_inapp' => false,
                'idea_comment_email' => true,
                'thread_reply_inapp' => true,
                'thread_reply_email' => true,
                'idea_status_inapp'  => false,
                'idea_status_email'  => true,
                'abuse_report_inapp'   => true,
                'abuse_report_email'   => true,
                'support_ticket_inapp' => true,
                'support_ticket_email' => true,
            ],
            $body,
        );

        $row = $this->conn->fetchAssociative(
            'SELECT notify_idea_comment_inapp, notify_idea_comment_email, notify_thread_reply_inapp, notify_thread_reply_email, notify_idea_status_inapp, notify_idea_status_email FROM users WHERE id = :id',
            ['id' => $userId],
        );
        self::assertIsArray($row);
        self::assertSame(0, (int) $row['notify_idea_comment_inapp']);
        self::assertSame(1, (int) $row['notify_idea_comment_email']);
        self::assertSame(1, (int) $row['notify_thread_reply_inapp']);
        self::assertSame(1, (int) $row['notify_thread_reply_email']);
        self::assertSame(0, (int) $row['notify_idea_status_inapp']);
        self::assertSame(1, (int) $row['notify_idea_status_email']);
    }

    public function test_put_preferences_requires_auth(): void
    {
        // No CSRF cookie/header either → CsrfMiddleware (which runs before
        // AuthZ in the pipeline) rejects first with 403, same ordering as
        // every other unauthenticated mutating request in this codebase.
        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('PUT', '/account/notification-preferences'),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // POST /account/notification-email + GET .../confirm — the email channel
    // stays inactive (AC) until the link is clicked.
    // -------------------------------------------------------------------------

    public function test_email_is_unset_until_confirmed(): void
    {
        $userId = $this->insertUser('prefs-unconfirmed@example.com');

        $response = $this->createApp()->handle(
            $this->authedRequest('POST', '/account/notification-email', $userId)
                ->withParsedBody(['email' => 'notify-me@example.com']),
        );
        self::assertSame(200, $response->getStatusCode());

        $row = $this->conn->fetchAssociative('SELECT notification_email FROM users WHERE id = :id', ['id' => $userId]);
        self::assertIsArray($row);
        self::assertNull($row['notification_email']);
    }

    public function test_confirmation_mail_has_a_distinct_subject_from_magic_link(): void
    {
        $userId = $this->insertUser('prefs-mail-subject@example.com');
        $mailer = new InMemoryMailer();

        $this->createApp($mailer)->handle(
            $this->authedRequest('POST', '/account/notification-email', $userId)
                ->withParsedBody(['email' => 'notify-me@example.com']),
        );

        $sent = $mailer->lastSent();
        self::assertNotNull($sent);
        self::assertSame('notify-me@example.com', $sent['to']);
        self::assertSame('Confirm your Votepit notification email', $sent['subject']);
        self::assertStringNotContainsString('log in', strtolower($sent['subject']));
        self::assertStringNotContainsString('password', strtolower($sent['subject']));
    }

    public function test_rejects_syntactically_invalid_email(): void
    {
        $userId = $this->insertUser('prefs-invalid-email@example.com');

        $response = $this->createApp()->handle(
            $this->authedRequest('POST', '/account/notification-email', $userId)
                ->withParsedBody(['email' => 'not-an-email']),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_confirm_with_valid_token_sets_notification_email(): void
    {
        $userId = $this->insertUser('prefs-confirm@example.com');
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $app->handle(
            $this->authedRequest('POST', '/account/notification-email', $userId)
                ->withParsedBody(['email' => 'confirmed@example.com']),
        );

        $token = $this->extractTokenFromMail($mailer);

        $confirm = $app->handle(
            $this->authedRequest('GET', '/account/notification-email/confirm?token=' . $token, $userId),
        );
        self::assertSame(200, $confirm->getStatusCode());

        $row = $this->conn->fetchAssociative('SELECT notification_email FROM users WHERE id = :id', ['id' => $userId]);
        self::assertIsArray($row);
        self::assertSame('confirmed@example.com', $row['notification_email']);

        // Single-use: the token is gone after being consumed.
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM notification_email_verifications WHERE user_id = :id', ['id' => $userId]);
        self::assertSame(0, $count);
    }

    public function test_confirm_rejects_a_token_belonging_to_a_different_user(): void
    {
        $ownerId  = $this->insertUser('prefs-confirm-owner@example.com');
        $attacker = $this->insertUser('prefs-confirm-attacker@example.com');
        $mailer   = new InMemoryMailer();
        $app      = $this->createApp($mailer);

        $app->handle(
            $this->authedRequest('POST', '/account/notification-email', $ownerId)
                ->withParsedBody(['email' => 'owner-target@example.com']),
        );
        $token = $this->extractTokenFromMail($mailer);

        // A different logged-in user tries to consume the owner's token.
        $response = $app->handle(
            $this->authedRequest('GET', '/account/notification-email/confirm?token=' . $token, $attacker),
        );
        self::assertSame(400, $response->getStatusCode());

        $attackerRow = $this->conn->fetchAssociative('SELECT notification_email FROM users WHERE id = :id', ['id' => $attacker]);
        self::assertIsArray($attackerRow);
        self::assertNull($attackerRow['notification_email']);
    }

    public function test_confirm_rejects_invalid_token(): void
    {
        $userId = $this->insertUser('prefs-confirm-bad-token@example.com');

        $response = $this->createApp()->handle(
            $this->authedRequest('GET', '/account/notification-email/confirm?token=not-a-real-token', $userId),
        );
        self::assertSame(400, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // DELETE /account/notification-email — Story 6/7
    // -------------------------------------------------------------------------

    public function test_delete_email_clears_address_and_disables_both_email_flags_atomically(): void
    {
        $userId = $this->insertUser('prefs-delete@example.com', [
            'notification_email'        => 'to-remove@example.com',
            'notify_idea_comment_email' => 1,
            'notify_thread_reply_email' => 1,
            'notify_idea_comment_inapp' => 1,
            'notify_thread_reply_inapp' => 0,
        ]);

        $response = $this->createApp()->handle(
            $this->authedRequest('DELETE', '/account/notification-email', $userId),
        );
        self::assertSame(200, $response->getStatusCode());

        $row = $this->conn->fetchAssociative(
            'SELECT notification_email, notify_idea_comment_email, notify_thread_reply_email, notify_idea_comment_inapp, notify_thread_reply_inapp
             FROM users WHERE id = :id',
            ['id' => $userId],
        );
        self::assertIsArray($row);
        self::assertNull($row['notification_email']);
        self::assertSame(0, (int) $row['notify_idea_comment_email']);
        self::assertSame(0, (int) $row['notify_thread_reply_email']);
        // Deactivating email must NOT touch the unrelated in-app flags (Story 6/7).
        self::assertSame(1, (int) $row['notify_idea_comment_inapp']);
        self::assertSame(0, (int) $row['notify_thread_reply_inapp']);
    }

    // -------------------------------------------------------------------------
    // Cross-user isolation (review-2026-09-04-fixes item 10) — mirrors
    // CrossTenantAccountScopingTest's assertion style: every mutation is
    // scoped strictly to the session's own user, never leaks into nor is
    // reachable through another user's data, even though these routes carry
    // no target-user identifier of their own (AuthNMiddleware::ATTR_USER is
    // the only source of identity — currentUserId() can't be tricked into
    // acting on a different user via the request body/query).
    // -------------------------------------------------------------------------

    public function test_put_preferences_never_mutates_another_users_row(): void
    {
        $victimId = $this->insertUser('prefs-victim@example.com');
        $attackerId = $this->insertUser('prefs-attacker@example.com');

        $response = $this->createApp()->handle(
            $this->authedRequest('PUT', '/account/notification-preferences', $attackerId)
                ->withParsedBody([
                    'idea_comment_inapp' => false,
                    'idea_comment_email' => true,
                    'thread_reply_inapp' => false,
                    'thread_reply_email' => true,
                    // Body carries no user identifier the action reads — this
                    // just documents that even a spoofed field is ignored.
                    'user_id'            => $victimId,
                ]),
        );
        self::assertSame(200, $response->getStatusCode());

        $victimRow = $this->conn->fetchAssociative(
            'SELECT notify_idea_comment_inapp, notify_idea_comment_email, notify_thread_reply_inapp, notify_thread_reply_email FROM users WHERE id = :id',
            ['id' => $victimId],
        );
        self::assertIsArray($victimRow);
        // Untouched defaults (NotificationPreferencesActionTest::test_get_preferences_reflects_defaults_for_a_fresh_user).
        self::assertSame(1, (int) $victimRow['notify_idea_comment_inapp']);
        self::assertSame(0, (int) $victimRow['notify_idea_comment_email']);
        self::assertSame(1, (int) $victimRow['notify_thread_reply_inapp']);
        self::assertSame(0, (int) $victimRow['notify_thread_reply_email']);
    }

    public function test_get_preferences_never_reflects_another_users_confirmed_email(): void
    {
        $userA = $this->insertUser('prefs-user-a@example.com', ['notification_email' => 'a-address@example.com']);
        $userB = $this->insertUser('prefs-user-b@example.com', ['notification_email' => 'b-address@example.com']);

        $responseA = $this->createApp()->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/account/notification-preferences')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($userA)]),
        );
        $responseB = $this->createApp()->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/account/notification-preferences')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($userB)]),
        );

        self::assertSame('a-address@example.com', json_decode((string) $responseA->getBody(), true)['notification_email']);
        self::assertSame('b-address@example.com', json_decode((string) $responseB->getBody(), true)['notification_email']);
    }

    public function test_delete_email_never_clears_another_users_address(): void
    {
        $victimId = $this->insertUser('prefs-delete-victim@example.com', ['notification_email' => 'keep-me@example.com']);
        $attackerId = $this->insertUser('prefs-delete-attacker@example.com', ['notification_email' => 'attacker@example.com']);

        $response = $this->createApp()->handle(
            $this->authedRequest('DELETE', '/account/notification-email', $attackerId),
        );
        self::assertSame(200, $response->getStatusCode());

        self::assertSame(
            'keep-me@example.com',
            $this->conn->fetchOne('SELECT notification_email FROM users WHERE id = :id', ['id' => $victimId]),
        );
        self::assertNull($this->conn->fetchOne('SELECT notification_email FROM users WHERE id = :id', ['id' => $attackerId]));
    }

    /** Extracts the plaintext token from the confirm link in the last sent mail's text body. */
    private function extractTokenFromMail(InMemoryMailer $mailer): string
    {
        $sent = $mailer->lastSent();
        self::assertNotNull($sent);
        $matched = preg_match('/token=([0-9a-f]{64})/', $sent['body'], $matches);
        self::assertSame(1, $matched, 'expected the confirm link with a 64-char hex token in the mail body');

        return $matches[1];
    }
}
