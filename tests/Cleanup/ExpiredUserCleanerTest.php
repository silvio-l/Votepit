<?php

declare(strict_types=1);

namespace Votepit\Tests\Cleanup;

use Doctrine\DBAL\Connection;
use Votepit\Cleanup\ExpiredUserCleaner;
use Votepit\Domain\UserDeletionGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Monitoring\NullErrorReporter;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\UserErasureRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\StubExtension;

/**
 * GDPR self-deletion purge (competitor-parity issue 12, decision 2b):
 * authored ideas/comments stay under the shared "deleted user" placeholder,
 * votes stay (counters unchanged) but are unlinked from the person, and
 * every other personal row of the user is erased.
 */
final class ExpiredUserCleanerTest extends IntegrationTestCase
{
    /** @param list<\Votepit\Extension\AppExtension> $extensions */
    private function cleaner(array $extensions = []): ExpiredUserCleaner
    {
        $users = new UserRepository($this->conn);

        return new ExpiredUserCleaner(
            $users,
            new UserErasureRepository($this->conn, $extensions),
            new UserDeletionGuard(new AccountMemberRepository($this->conn), $users),
            new AuditLogger($this->logFile),
            new NullErrorReporter(),
            $this->avatarDir,
        );
    }

    private function scheduleExpired(int $userId): void
    {
        $this->conn->update('users', [
            'deletion_scheduled_at' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
        ], ['id' => $userId]);
    }

    /** @return list<array{id: int, deletion_scheduled_at: string}> */
    private function expired(): array
    {
        return (new UserRepository($this->conn))->findExpiredForDeletion(new \DateTimeImmutable());
    }

    private function placeholderId(): int
    {
        return (int) $this->conn->fetchOne('SELECT id FROM users WHERE is_deleted_placeholder = 1');
    }

    public function test_only_users_past_their_grace_period_are_due(): void
    {
        $due     = $this->insertUser('due@example.com');
        $pending = $this->insertUser('pending@example.com', [
            'deletion_scheduled_at' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'),
        ]);
        $this->insertUser('untouched@example.com');
        $this->scheduleExpired($due);

        $ids = array_column($this->expired(), 'id');

        self::assertSame([$due], $ids);
        self::assertNotContains($pending, $ids);
    }

    public function test_content_is_reattributed_to_the_placeholder_and_votes_are_anonymised(): void
    {
        $boardId = $this->insertBoard('erase-board');
        $userId  = $this->insertUser('leaver@example.com', ['username' => 'Leaver', 'username_lower' => 'leaver']);
        $other   = $this->insertUser('stayer@example.com');

        $ownIdea     = $this->seedIdea($boardId, $userId, 'Leaver idea');
        $otherIdea   = $this->seedIdea($boardId, $other, 'Stayer idea');
        $ownComment  = $this->seedComment($otherIdea, $userId, 'Leaver comment');
        $otherCmt    = $this->seedComment($ownIdea, $other, 'Stayer comment');
        $this->seedVote($otherIdea, $userId, 1);
        $this->seedVote($ownIdea, $other, -1);
        $this->seedReaction($otherCmt, $userId);
        $this->scheduleExpired($userId);

        $before = $this->conn->fetchAssociative('SELECT score_cache, up_count, down_count FROM ideas WHERE id = :id', ['id' => $otherIdea]);

        $result = $this->cleaner()->run($this->expired());

        self::assertSame(['purged' => 1, 'failed' => 0, 'skipped' => 0], $result);
        self::assertFalse($this->conn->fetchOne('SELECT id FROM users WHERE id = :id', ['id' => $userId]));

        $placeholder = $this->placeholderId();
        self::assertGreaterThan(0, $placeholder);
        self::assertSame($placeholder, (int) $this->conn->fetchOne('SELECT author_id FROM ideas WHERE id = :id', ['id' => $ownIdea]));
        self::assertSame($placeholder, (int) $this->conn->fetchOne('SELECT author_id FROM comments WHERE id = :id', ['id' => $ownComment]));
        self::assertSame('Leaver idea', $this->conn->fetchOne('SELECT title FROM ideas WHERE id = :id', ['id' => $ownIdea]));

        // The vote survives (same value, counters untouched) but is no longer linked to anyone.
        $votes = $this->conn->fetchAllAssociative('SELECT user_id, voter_token_hash, voter_email_enc, value FROM votes WHERE idea_id = :id', ['id' => $otherIdea]);
        self::assertCount(1, $votes);
        self::assertNull($votes[0]['user_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $votes[0]['voter_token_hash']);
        self::assertNull($votes[0]['voter_email_enc']);
        self::assertSame(1, (int) $votes[0]['value']);
        self::assertSame($before, $this->conn->fetchAssociative('SELECT score_cache, up_count, down_count FROM ideas WHERE id = :id', ['id' => $otherIdea]));

        // Other people's data is untouched.
        self::assertSame($other, (int) $this->conn->fetchOne('SELECT author_id FROM comments WHERE id = :id', ['id' => $otherCmt]));
        self::assertSame($other, (int) $this->conn->fetchOne('SELECT user_id FROM votes WHERE idea_id = :id', ['id' => $ownIdea]));
        // Reactions are personal data without a counter to preserve — gone.
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM comment_reactions'));

        self::assertStringContainsString('user.purged', $this->readAuditLog());
    }

    public function test_personal_rows_are_erased(): void
    {
        $accountId = $this->insertAccount(['slug' => 'member-of']);
        $userId    = $this->insertUser('personal@example.com', ['notification_email' => 'personal@example.com']);
        $this->insertAccountMember($accountId, $userId, 'member');
        $this->conn->insert('oauth_identities', ['user_id' => $userId, 'provider' => 'github', 'provider_user_id' => 'gh-1']);
        $this->conn->insert('login_tokens', [
            'user_id'    => $userId,
            'token_hash' => str_repeat('c', 64),
            'expires_at' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'),
        ]);
        $this->scheduleExpired($userId);

        $this->cleaner()->run($this->expired());

        foreach (['account_members', 'oauth_identities', 'login_tokens'] as $table) {
            self::assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM {$table} WHERE user_id = :id", ['id' => $userId]), $table);
        }
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users WHERE notification_email IS NOT NULL'));
    }

    public function test_anonymous_votes_linked_to_the_user_lose_their_email_and_token(): void
    {
        $boardId = $this->insertBoard('anon-link');
        $author  = $this->insertUser('anon-author@example.com');
        $userId  = $this->insertUser('anon-linked@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $token   = str_repeat('d', 64);
        $this->seedVote($ideaId, 0, 1, ['user_id' => null, 'voter_token_hash' => $token, 'voter_email_enc' => 'ciphertext']);
        $this->conn->insert('voter_identity_links', ['voter_token_hash' => $token, 'user_id' => $userId]);
        $this->scheduleExpired($userId);

        $this->cleaner()->run($this->expired());

        $vote = $this->conn->fetchAssociative('SELECT voter_token_hash, voter_email_enc, value FROM votes WHERE idea_id = :id', ['id' => $ideaId]);
        self::assertIsArray($vote);
        self::assertNotSame($token, $vote['voter_token_hash']);
        self::assertNull($vote['voter_email_enc']);
        self::assertSame(1, (int) $vote['value']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM voter_identity_links'));
    }

    public function test_restrict_references_are_reattributed_so_the_delete_succeeds(): void
    {
        $accountId = $this->insertAccount(['slug' => 'mod-account']);
        $boardId   = $this->insertBoard('mod-board', ['account_id' => $accountId]);
        $ownerId   = $this->insertUser('mod-owner@example.com');
        $modId     = $this->insertUser('moderator@example.com');
        $target    = $this->insertUser('target@example.com');
        $this->insertAccountMember($accountId, $ownerId, 'owner');
        $this->insertAccountMember($accountId, $modId, 'admin');
        $ideaId = $this->seedIdea($boardId, $target);

        $actionId = $this->seedModerationAction('idea', $ideaId, $boardId, $accountId, $modId);
        $this->insertInvite($accountId, $target, $modId, str_repeat('e', 64));
        $this->conn->insert('blocked_users', ['account_id' => $accountId, 'user_id' => $target, 'created_by' => $modId]);
        $tokenId = $this->insertApiToken($accountId, $boardId, $modId, str_repeat('f', 64));
        $this->scheduleExpired($modId);

        $result = $this->cleaner()->run($this->expired());

        self::assertSame(1, $result['purged']);
        $placeholder = $this->placeholderId();
        self::assertSame($placeholder, (int) $this->conn->fetchOne('SELECT actor_user_id FROM content_moderation_actions WHERE id = :id', ['id' => $actionId]));
        self::assertSame($placeholder, (int) $this->conn->fetchOne('SELECT invited_by FROM invites'));
        self::assertSame($placeholder, (int) $this->conn->fetchOne('SELECT created_by FROM blocked_users'));
        self::assertSame($placeholder, (int) $this->conn->fetchOne('SELECT created_by_user_id FROM api_tokens WHERE id = :id', ['id' => $tokenId]));
    }

    public function test_the_placeholder_is_shared_and_can_never_log_in_or_be_notified(): void
    {
        $boardId = $this->insertBoard('two-leavers');
        $a       = $this->insertUser('leaver-a@example.com');
        $b       = $this->insertUser('leaver-b@example.com');
        $this->seedIdea($boardId, $a, 'A');
        $this->seedIdea($boardId, $b, 'B');
        $this->scheduleExpired($a);
        $this->scheduleExpired($b);

        $this->cleaner()->run($this->expired());

        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users WHERE is_deleted_placeholder = 1'));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(DISTINCT author_id) FROM ideas'));

        $placeholder = $this->conn->fetchAssociative('SELECT * FROM users WHERE is_deleted_placeholder = 1');
        self::assertIsArray($placeholder);
        self::assertDoesNotMatchRegularExpression('/^[0-9a-f]{64}$/', (string) $placeholder['email_hmac']);
        foreach ($placeholder as $column => $value) {
            if (str_starts_with($column, 'notify_')) {
                self::assertSame(0, (int) $value, $column);
            }
        }
        self::assertNull($placeholder['notification_email']);
        self::assertNull($placeholder['deletion_scheduled_at']);
    }

    public function test_a_user_who_became_an_owner_during_the_grace_period_is_skipped(): void
    {
        $accountId = $this->insertAccount(['slug' => 'became-owner']);
        $userId    = $this->insertUser('became-owner@example.com');
        $this->insertAccountMember($accountId, $userId, 'owner');
        $this->scheduleExpired($userId);

        $result = $this->cleaner()->run($this->expired());

        self::assertSame(['purged' => 0, 'failed' => 0, 'skipped' => 1], $result);
        self::assertSame($userId, (int) $this->conn->fetchOne('SELECT id FROM users WHERE id = :id', ['id' => $userId]));
        self::assertStringContainsString('user.purge_skipped', $this->readAuditLog());
    }

    public function test_the_avatar_file_is_removed(): void
    {
        mkdir($this->avatarDir, 0o777, true);
        $file = 'abc123.webp';
        file_put_contents($this->avatarDir . '/' . $file, 'img');
        $userId = $this->insertUser('avatar-leaver@example.com', ['avatar_filename' => $file]);
        $this->scheduleExpired($userId);

        $this->cleaner()->run($this->expired());

        self::assertFileDoesNotExist($this->avatarDir . '/' . $file);
    }

    public function test_extensions_reassign_their_references_inside_the_erasure_before_the_delete(): void
    {
        $accountId = $this->insertAccount(['slug' => 'ext-account']);
        $boardId   = $this->insertBoard('ext-board', ['account_id' => $accountId]);
        $userId    = $this->insertUser('ext@example.com');
        $this->seedIdea($boardId, $userId);
        $this->scheduleExpired($userId);

        $calls     = [];
        $extension = new StubExtension(['reassign_user_references' => function (Connection $conn, int $user, int $placeholder) use (&$calls): void {
            $calls[] = [
                'user'           => $user,
                'placeholder'    => $placeholder,
                'in_transaction' => $conn->isTransactionActive(),
                'user_exists'    => (int) $conn->fetchOne('SELECT COUNT(*) FROM users WHERE id = :id', ['id' => $user]),
            ];
        }]);

        $result = $this->cleaner([$extension])->run($this->expired());

        self::assertSame(1, $result['purged']);
        self::assertSame([[
            'user'           => $userId,
            'placeholder'    => $this->placeholderId(),
            'in_transaction' => true,
            'user_exists'    => 1,
        ]], $calls);
    }

    public function test_a_failing_extension_hook_rolls_the_erasure_back_and_keeps_the_user(): void
    {
        $accountId = $this->insertAccount(['slug' => 'fail-account']);
        $boardId   = $this->insertBoard('fail-board', ['account_id' => $accountId]);
        $userId    = $this->insertUser('fail@example.com');
        $ideaId    = $this->seedIdea($boardId, $userId);
        $this->scheduleExpired($userId);

        $extension = new StubExtension(['reassign_user_references' => static function (): never {
            throw new \RuntimeException('evidence table unavailable');
        }]);

        $result = $this->cleaner([$extension])->run($this->expired());

        self::assertSame(['purged' => 0, 'failed' => 1, 'skipped' => 0], $result);
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users WHERE id = :id', ['id' => $userId]));
        self::assertSame($userId, (int) $this->conn->fetchOne('SELECT author_id FROM ideas WHERE id = :id', ['id' => $ideaId]));
        self::assertStringContainsString('user.purge_failed', (string) file_get_contents($this->logFile));
        self::assertSame([$userId], array_column($this->expired(), 'id'), 'the user stays due and is retried on the next run');
    }
}
