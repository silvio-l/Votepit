<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\UserRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * UserRepository::findOrphanedNotificationEmailUserIds() /
 * clearNotificationEmail() cleanup path (deep-review-2026-09 finding j):
 * notification_email is global on `users`, not account-scoped, so it
 * previously survived a user losing membership in every account.
 */
final class UserRepositoryOrphanedNotificationEmailTest extends IntegrationTestCase
{
    public function test_user_with_no_account_membership_is_orphaned(): void
    {
        $userId = $this->insertUser('orphan@example.com', ['notification_email' => 'orphan@example.com']);

        $repo = new UserRepository($this->conn);
        self::assertSame([$userId], $repo->findOrphanedNotificationEmailUserIds());
    }

    public function test_user_with_an_active_membership_is_not_orphaned(): void
    {
        $accountId = $this->insertAccount();
        $userId    = $this->insertUser('member@example.com', ['notification_email' => 'member@example.com']);
        $this->insertAccountMember($accountId, $userId, 'owner');

        $repo = new UserRepository($this->conn);
        self::assertSame([], $repo->findOrphanedNotificationEmailUserIds());
    }

    public function test_operator_with_no_membership_is_not_orphaned(): void
    {
        $this->insertUser('operator@example.com', [
            'notification_email' => 'operator@example.com',
            'is_operator'        => 1,
        ]);

        $repo = new UserRepository($this->conn);
        self::assertSame([], $repo->findOrphanedNotificationEmailUserIds());
    }

    public function test_support_agent_with_no_membership_is_not_orphaned(): void
    {
        $this->insertUser('support@example.com', [
            'notification_email' => 'support@example.com',
            'is_support'         => 1,
        ]);

        $repo = new UserRepository($this->conn);
        self::assertSame([], $repo->findOrphanedNotificationEmailUserIds());
    }

    public function test_user_with_no_notification_email_set_is_never_reported(): void
    {
        $this->insertUser('bare@example.com');

        $repo = new UserRepository($this->conn);
        self::assertSame([], $repo->findOrphanedNotificationEmailUserIds());
    }

    public function test_clear_notification_email_removes_it_and_the_email_flags(): void
    {
        $userId = $this->insertUser('orphan2@example.com', [
            'notification_email'        => 'orphan2@example.com',
            'notify_idea_comment_email' => 1,
        ]);

        $repo = new UserRepository($this->conn);
        $repo->clearNotificationEmail($userId);

        $row = $this->conn->fetchAssociative('SELECT notification_email, notify_idea_comment_email FROM users WHERE id = :id', ['id' => $userId]);
        self::assertIsArray($row);
        self::assertNull($row['notification_email']);
        self::assertSame(0, (int) $row['notify_idea_comment_email']);
    }
}
