<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\AccountRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Upgrade/downgrade/cancellation lifecycle — AccountRepository::
 * findExpiredForDeletion()/purgeExpired(), the query-and-delete pair driving
 * bin/cleanup-expired-accounts.php. Exercised directly against the
 * repository (the CLI script itself only adds config-loading/CLI-argument
 * plumbing around these two calls — see its own doc comment) via the same
 * SQLite-in-memory harness every other repository test uses.
 *
 * The "real-time-shifted" trigger test mirrors this codebase's existing
 * time-manipulation pattern (e.g. InviteAcceptActionTest/VerifyActionTest
 * setting expires_at to a past timestamp via direct DB update) rather than
 * sleeping or hardcoding time() — deletion_scheduled_at is set directly to
 * a moment in the past/future and findExpiredForDeletion(now()) is asked
 * whether that account is due.
 */
final class AccountRepositoryCleanupTest extends IntegrationTestCase
{
    private function repo(): AccountRepository
    {
        return new AccountRepository($this->conn);
    }

    /**
     * Full account-scoped fixture: a board with an idea, a vote, a comment,
     * a board_blocklist entry, board_smtp_settings, a second member, a
     * blocked_users row, an invite, an api_token and an abuse_report — one
     * row per account-scoped core table plus everything cascading
     * transitively via boards.id.
     *
     * @return array{account_id: int, owner_id: int, member_id: int, board_id: int, idea_id: int}
     */
    private function seedFullAccount(): array
    {
        $accountId = $this->insertAccount(['slug' => 'to-be-purged']);
        $ownerId   = $this->insertUser('owner-purge@example.com');
        $memberId  = $this->insertUser('member-purge@example.com');
        $this->insertAccountMember($accountId, $ownerId, 'owner');
        $this->insertAccountMember($accountId, $memberId, 'moderator');

        $boardId = $this->insertBoard('purge-board', ['account_id' => $accountId, 'is_default' => 0]);
        $ideaId  = $this->seedIdea($boardId, $ownerId);
        $this->seedVote($ideaId, $memberId, 1);
        $this->seedComment($ideaId, $memberId);

        $this->conn->insert('board_blocklist', [
            'board_id'   => $boardId,
            'word'       => 'badword',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->conn->insert('board_smtp_settings', [
            'board_id'   => $boardId,
            'host'       => 'smtp.example.com',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->conn->insert('blocked_users', [
            'account_id' => $accountId,
            'user_id'    => $memberId,
            'created_by' => $ownerId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->insertInvite($accountId, $this->insertUser('invitee-purge@example.com'), $ownerId, hash('sha256', 'token'));

        $this->insertApiToken($accountId, $boardId, $ownerId, hash('sha256', 'apitoken'));

        $this->insertAbuseReport('/purge-board/ideas/1', 'Spam', ['account_id' => $accountId, 'board_id' => $boardId]);

        return [
            'account_id' => $accountId,
            'owner_id'   => $ownerId,
            'member_id'  => $memberId,
            'board_id'   => $boardId,
            'idea_id'    => $ideaId,
        ];
    }

    // -------------------------------------------------------------------------
    // findExpiredForDeletion — real-time-shifted trigger
    // -------------------------------------------------------------------------

    public function test_account_with_past_deadline_is_found_as_expired(): void
    {
        $accountId = $this->insertAccount(['slug' => 'expired-account']);
        $pastDeadline = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $this->conn->update('accounts', ['deletion_scheduled_at' => $pastDeadline], ['id' => $accountId]);

        $expired = $this->repo()->findExpiredForDeletion(new \DateTimeImmutable());

        $ids = array_column($expired, 'id');
        self::assertContains($accountId, $ids);
    }

    public function test_account_with_future_deadline_is_not_yet_expired(): void
    {
        $accountId = $this->insertAccount(['slug' => 'not-yet-expired']);
        $futureDeadline = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $this->conn->update('accounts', ['deletion_scheduled_at' => $futureDeadline], ['id' => $accountId]);

        $expired = $this->repo()->findExpiredForDeletion(new \DateTimeImmutable());

        $ids = array_column($expired, 'id');
        self::assertNotContains($accountId, $ids);
    }

    public function test_account_with_no_deletion_scheduled_is_never_expired(): void
    {
        $accountId = $this->insertAccount(['slug' => 'never-scheduled']);

        $expired = $this->repo()->findExpiredForDeletion(new \DateTimeImmutable());

        $ids = array_column($expired, 'id');
        self::assertNotContains($accountId, $ids);
    }

    // -------------------------------------------------------------------------
    // purgeExpired — complete cascading deletion, zero orphans
    // -------------------------------------------------------------------------

    public function test_purge_expired_deletes_the_account_and_every_account_scoped_row(): void
    {
        $fixture = $this->seedFullAccount();

        $this->repo()->purgeExpired($fixture['account_id']);

        self::assertFalse($this->conn->fetchAssociative('SELECT * FROM accounts WHERE id = :id', ['id' => $fixture['account_id']]));

        // Directly account-scoped tables.
        foreach (['account_members', 'blocked_users', 'invites', 'api_tokens'] as $table) {
            self::assertSame(
                0,
                (int) $this->conn->fetchOne("SELECT COUNT(*) FROM {$table} WHERE account_id = :id", ['id' => $fixture['account_id']]),
                "Orphaned row left in {$table}",
            );
        }

        // boards + everything transitively cascading via boards.id.
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE id = :id', ['id' => $fixture['board_id']]));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :id', ['id' => $fixture['board_id']]));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id', ['id' => $fixture['idea_id']]));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM comments WHERE idea_id = :id', ['id' => $fixture['idea_id']]));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM board_blocklist WHERE board_id = :id', ['id' => $fixture['board_id']]));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM board_smtp_settings WHERE board_id = :id', ['id' => $fixture['board_id']]));

        // abuse_reports deliberately uses ON DELETE SET NULL (DSA moderation
        // record retained) — the row survives with account_id/board_id nulled
        // out rather than being deleted; assert that retention explicitly.
        $abuseRow = $this->conn->fetchAssociative(
            "SELECT account_id, board_id FROM abuse_reports WHERE target_url = '/purge-board/ideas/1'",
        );
        self::assertIsArray($abuseRow);
        self::assertNull($abuseRow['account_id']);
        self::assertNull($abuseRow['board_id']);

        // Users themselves are NOT account-scoped (identity is global — ADR
        // 0001 §2c) and must survive the purge untouched.
        self::assertNotFalse($this->conn->fetchAssociative('SELECT * FROM users WHERE id = :id', ['id' => $fixture['owner_id']]));
        self::assertNotFalse($this->conn->fetchAssociative('SELECT * FROM users WHERE id = :id', ['id' => $fixture['member_id']]));
    }

    public function test_purge_expired_does_not_touch_a_different_accounts_data(): void
    {
        $expiredFixture = $this->seedFullAccount();
        $survivingAccountId = $this->insertAccount(['slug' => 'survivor']);
        $survivingBoardId   = $this->insertBoard('survivor-board', ['account_id' => $survivingAccountId, 'is_default' => 0]);

        $this->repo()->purgeExpired($expiredFixture['account_id']);

        self::assertNotFalse($this->conn->fetchAssociative('SELECT * FROM accounts WHERE id = :id', ['id' => $survivingAccountId]));
        self::assertNotFalse($this->conn->fetchAssociative('SELECT * FROM boards WHERE id = :id', ['id' => $survivingBoardId]));
    }
}
