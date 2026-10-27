<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * As part of the upgrade/downgrade/cancellation lifecycle: the cancellation
 * export-reminder mail is piggy-backed onto the account owner's next
 * POST /login request — the only moment this codebase ever holds the
 * plaintext email (ADR 0002) — see AccountMemberRepository::
 * ownedAccountsPendingReminder() and AppFactory's POST /login handler.
 *
 * Mirrors LoginActionTest's request-building idiom.
 */
final class DeletionReminderLoginTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postLogin(string $email): \Psr\Http\Message\ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())->createServerRequest('POST', '/login')
            ->withCookieParams([$csrf->cookieName() => $signed])
            ->withParsedBody(['email' => $email, '_csrf' => $token]);
    }

    public function test_first_login_after_cancellation_sends_reminder_mail_and_marks_it_sent(): void
    {
        $email     = 'owner-cancelled@example.com';
        $userId    = $this->insertUser($email);
        $accountId = $this->defaultAccountId();
        $this->insertAccountMember($accountId, $userId, 'owner');

        $deadline = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
        $this->conn->update('accounts', ['deletion_scheduled_at' => $deadline], ['id' => $accountId]);

        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $response = $app->handle($this->postLogin($email));

        self::assertSame(200, $response->getStatusCode());

        // Two mails: the login link + the separate deletion reminder.
        self::assertCount(2, $mailer->sent);
        $subjects = array_map(static fn (array $m): string => $m['subject'], $mailer->sent);
        self::assertContains('Your Votepit login link', $subjects);
        self::assertContains('Your Votepit account will be deleted soon', $subjects);

        // Reminder is multipart: both text + HTML carry the deadline + core message.
        $expectedDate = (new \DateTimeImmutable($deadline))->format('Y-m-d');
        foreach ($mailer->sent as $sent) {
            if ($sent['subject'] !== 'Your Votepit account will be deleted soon') {
                continue;
            }

            self::assertStringContainsString($expectedDate, $sent['body']);
            self::assertStringContainsString('permanently', $sent['body']);
            self::assertIsString($sent['html']);
            self::assertStringContainsString($expectedDate, $sent['html']);
        }

        $reminderSentAt = $this->conn->fetchOne(
            'SELECT deletion_reminder_sent_at FROM accounts WHERE id = :id',
            ['id' => $accountId],
        );
        self::assertNotNull($reminderSentAt);

        $logContent = $this->readAuditLog();
        self::assertStringContainsString('account.deletion_reminder_sent', $logContent);
        self::assertStringNotContainsString($email, $logContent);
    }

    public function test_reminder_is_not_resent_on_a_second_login(): void
    {
        $email     = 'owner-cancelled-2@example.com';
        $userId    = $this->insertUser($email);
        $accountId = $this->defaultAccountId();
        $this->insertAccountMember($accountId, $userId, 'owner');

        $deadline = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
        $this->conn->update('accounts', ['deletion_scheduled_at' => $deadline], ['id' => $accountId]);

        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $app->handle($this->postLogin($email));
        self::assertSame(2, $mailer->count());

        // Second login: only the login-link mail, no repeated reminder.
        $app->handle($this->postLogin($email));
        self::assertSame(3, $mailer->count());
        $subjects = array_map(static fn (array $m): string => $m['subject'], $mailer->sent);
        self::assertCount(1, array_filter($subjects, static fn (string $s): bool => $s === 'Your Votepit account will be deleted soon'));
    }

    public function test_no_reminder_when_no_deletion_is_scheduled(): void
    {
        $email     = 'owner-active@example.com';
        $userId    = $this->insertUser($email);
        $accountId = $this->defaultAccountId();
        $this->insertAccountMember($accountId, $userId, 'owner');

        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $app->handle($this->postLogin($email));

        self::assertCount(1, $mailer->sent);
        self::assertSame('Your Votepit login link', $mailer->sent[0]['subject']);
    }

    public function test_non_owner_member_gets_no_reminder(): void
    {
        $email     = 'moderator-cancelled@example.com';
        $userId    = $this->insertUser($email);
        $accountId = $this->defaultAccountId();
        $this->insertAccountMember($accountId, $userId, 'moderator');

        $deadline = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
        $this->conn->update('accounts', ['deletion_scheduled_at' => $deadline], ['id' => $accountId]);

        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $app->handle($this->postLogin($email));

        self::assertCount(1, $mailer->sent);
        self::assertSame('Your Votepit login link', $mailer->sent[0]['subject']);
    }
}
