<?php

declare(strict_types=1);

namespace Votepit\Tests\Cleanup;

use Votepit\Cleanup\ExpiredAccountCleaner;
use Votepit\Logging\AuditLogger;
use Votepit\Monitoring\ErrorReporter;
use Votepit\Persistence\AccountRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * review-2026-09-04-fixes item 8: bin/cleanup-expired-accounts.php's
 * per-account purge loop, extracted into ExpiredAccountCleaner so it can be
 * exercised directly (the bin script itself stays a thin, untested-by-
 * convention CLI wrapper — see AccountRepositoryCleanupTest's class doc for
 * the same reasoning applied to purgeExpired() itself).
 *
 * Forces a real purge failure on one specific account via a SQLite
 * BEFORE DELETE trigger that RAISEs — the closest in-process equivalent to
 * "an unexpected FK constraint/transient DB error" without corrupting the
 * schema other tests share.
 */
final class ExpiredAccountCleanerTest extends IntegrationTestCase
{
    /** @return array{id: int, slug: string, deletion_scheduled_at: string} */
    private function expiredAccountRow(int $accountId, string $slug): array
    {
        return [
            'id'                     => $accountId,
            'slug'                   => $slug,
            'deletion_scheduled_at'  => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
        ];
    }

    private function forcePurgeFailureFor(int $accountId): void
    {
        $this->conn->executeStatement(
            "CREATE TRIGGER fail_purge_{$accountId}
             BEFORE DELETE ON accounts
             WHEN OLD.id = {$accountId}
             BEGIN
                 SELECT RAISE(ABORT, 'simulated purge failure');
             END",
        );
    }

    public function test_erasure_pseudonymises_the_upload_terms_proof_before_the_account_is_deleted(): void
    {
        $accountId = $this->insertAccount(['slug' => 'proof-erased']);
        $this->conn->insert('upload_terms_consents', [
            'account_id'    => $accountId,
            'board_id'      => null,
            'user_id'       => null,
            'terms_version' => '2026-test-terms',
            'ui_language'   => 'en',
            'accepted_at'   => '2026-10-01 10:00:00',
        ]);
        $hasher   = new \Votepit\Security\IdentityHasher(self::identityServerKey());
        $reporter = new class () implements ErrorReporter {
            public function report(\Throwable $exception): void {}
            public function reportSecurityEvent(string $message, string $level, array $context): void {}
        };

        $cleaner = new ExpiredAccountCleaner(
            new AccountRepository($this->conn),
            new AuditLogger($this->logFile),
            $reporter,
            null,
            new \Votepit\Persistence\UploadTermsConsentRepository($this->conn),
            $hasher,
        );
        self::assertSame(['purged' => 1, 'failed' => 0], $cleaner->run([$this->expiredAccountRow($accountId, 'proof-erased')]));

        $row = $this->conn->fetchAssociative('SELECT * FROM upload_terms_consents');
        self::assertIsArray($row);
        self::assertNull($row['account_id']);
        self::assertSame($hasher->hashReference('upload-terms-account', $accountId), $row['account_pseudonym']);
        self::assertSame('en', $row['ui_language']);
        self::assertNotNull($row['pseudonymised_at']);
    }

    public function test_a_failure_on_one_account_does_not_prevent_the_others_from_being_purged(): void
    {
        $failingId = $this->insertAccount(['slug' => 'will-fail']);
        $okIdA     = $this->insertAccount(['slug' => 'will-succeed-a']);
        $okIdB     = $this->insertAccount(['slug' => 'will-succeed-b']);
        $this->forcePurgeFailureFor($failingId);

        $reporter = new class () implements ErrorReporter {
            /** @var list<\Throwable> */
            public array $reported = [];

            public function report(\Throwable $exception): void
            {
                $this->reported[] = $exception;
            }

            public function reportSecurityEvent(string $message, string $level, array $context): void {}
        };

        $cleaner = new ExpiredAccountCleaner(
            new AccountRepository($this->conn),
            new AuditLogger($this->logFile),
            $reporter,
        );

        $result = $cleaner->run([
            $this->expiredAccountRow($failingId, 'will-fail'),
            $this->expiredAccountRow($okIdA, 'will-succeed-a'),
            $this->expiredAccountRow($okIdB, 'will-succeed-b'),
        ]);

        self::assertSame(['purged' => 2, 'failed' => 1], $result);
        self::assertCount(1, $reporter->reported);

        // The failing account's row survives (the trigger aborted its
        // DELETE); the other two are gone.
        self::assertIsArray($this->conn->fetchAssociative('SELECT id FROM accounts WHERE id = :id', ['id' => $failingId]));
        self::assertFalse($this->conn->fetchAssociative('SELECT id FROM accounts WHERE id = :id', ['id' => $okIdA]));
        self::assertFalse($this->conn->fetchAssociative('SELECT id FROM accounts WHERE id = :id', ['id' => $okIdB]));

        $log = $this->readAuditLog();
        self::assertStringContainsString('account.purge_failed', $log);
        self::assertStringContainsString('"slug":"will-fail"', $log);
        self::assertStringContainsString('account.purged', $log);
        self::assertStringContainsString('"slug":"will-succeed-a"', $log);
        self::assertStringContainsString('"slug":"will-succeed-b"', $log);
    }

    public function test_account_purged_is_never_logged_for_a_failed_purge(): void
    {
        $failingId = $this->insertAccount(['slug' => 'never-actually-purged']);
        $this->forcePurgeFailureFor($failingId);

        $reporter = new class () implements ErrorReporter {
            public function report(\Throwable $exception): void {}

            public function reportSecurityEvent(string $message, string $level, array $context): void {}
        };

        $cleaner = new ExpiredAccountCleaner(
            new AccountRepository($this->conn),
            new AuditLogger($this->logFile),
            $reporter,
        );

        $cleaner->run([$this->expiredAccountRow($failingId, 'never-actually-purged')]);

        $log = $this->readAuditLog();
        self::assertStringNotContainsString('account.purged', $log);
        self::assertStringContainsString('account.purge_failed', $log);
    }
}
