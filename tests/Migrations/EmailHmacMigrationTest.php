<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use Votepit\Migrations\ConfigAwareMigration;
use Votepit\Migrations\MigrationRunner;
use Votepit\Security\IdentityHasher;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Behavior comparison for 0005_backfill_email_hmac.php (ADR 0002) against the
 * SQLite test harness — analogous to AccountSchemaBehaviorTest: the
 * MySQL-specific DDL (0004/0006, ALTER ... AFTER, DROP
 * INDEX) is checked content-only in EmailHmacDdlMigrationTest; this class
 * tests the pure backfill LOGIC (0005 is portable SQL, no MySQL DDL —
 * so it can run directly against SQLite).
 *
 * Deliberately loads the migration via MigrationRunner::discover() instead of
 * its own `require` of the .php file: PHPUnit runs all test methods/classes
 * in the same process, and a second `require` of the same
 * migration file (whether from this class itself or from another
 * test class like EmailHmacDdlMigrationTest, which also discovers the real
 * migrations/ folder) would trigger "Cannot redeclare class".
 * MigrationRunner caches loaded .php migrations process-wide (see there)
 * and is therefore the only safe load path.
 */
final class EmailHmacMigrationTest extends IntegrationTestCase
{
    /**
     * This class verifies the backfill LOGIC of 0005 — which runs on the
     * TRANSITIONAL intermediate state (post-0004/pre-0006: email + nullable
     * email_hmac side by side), not on the POST-0006 end state that
     * IntegrationTestCase::applySchema() has mirrored since the AppFactory
     * changeover. So it rebuilds the users table locally to
     * the intermediate state; SQLite doesn't enforce FKs here (no
     * PRAGMA foreign_keys=ON), so DROP+CREATE is unproblematic.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->conn->executeStatement('DROP TABLE users');
        $this->conn->executeStatement(
            'CREATE TABLE users (
                id            INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                email         VARCHAR(254) NOT NULL,
                email_hmac    CHAR(64) NULL,
                is_admin      INTEGER NOT NULL DEFAULT 0,
                is_blocked    INTEGER NOT NULL DEFAULT 0,
                token_version INTEGER NOT NULL DEFAULT 0,
                verified_at   DATETIME NULL,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (email),
                UNIQUE (email_hmac)
            )',
        );
    }

    /** Seeds a user in the transitional schema (email always set, email_hmac optional). */
    private function insertLegacyUser(string $email, bool $withHmac = true): int
    {
        $this->conn->insert('users', [
            'email'         => $email,
            'email_hmac'    => $withHmac ? (new IdentityHasher(self::identityServerKey()))->hash($email) : null,
            'is_admin'      => 0,
            'is_blocked'    => 0,
            'token_version' => 0,
            'verified_at'   => null,
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->lastInsertId();
    }

    private function migration(): ConfigAwareMigration
    {
        $runner = new MigrationRunner($this->conn, dirname(__DIR__, 2) . '/migrations');

        foreach ($runner->discover() as $candidate) {
            if ($candidate->version() === '0005_backfill_email_hmac') {
                self::assertInstanceOf(ConfigAwareMigration::class, $candidate);

                return $candidate;
            }
        }

        self::fail('Migration 0005_backfill_email_hmac was not found.');
    }

    private function emailHmacOf(int $userId): ?string
    {
        $value = $this->conn->fetchOne('SELECT email_hmac FROM users WHERE id = :id', ['id' => $userId]);

        return $value === false || $value === null ? null : (string) $value;
    }

    public function test_backfill_computes_the_correct_hmac_for_an_existing_user(): void
    {
        $userId = $this->insertLegacyUser('someone@example.com', withHmac: false);

        $this->migration()->upWithConfig($this->conn, $this->testConfig());

        $expected = (new IdentityHasher(self::identityServerKey()))->hash('someone@example.com');
        self::assertSame($expected, $this->emailHmacOf($userId));
    }

    public function test_backfill_normalizes_before_hashing_case_and_whitespace(): void
    {
        $userId = $this->insertLegacyUser('  Someone@Example.COM  ', withHmac: false);

        $this->migration()->upWithConfig($this->conn, $this->testConfig());

        $expected = (new IdentityHasher(self::identityServerKey()))->hash('someone@example.com');
        self::assertSame($expected, $this->emailHmacOf($userId));
    }

    public function test_backfill_leaves_already_populated_rows_untouched(): void
    {
        $userId    = $this->insertLegacyUser('already@example.com');
        $preHashed = $this->emailHmacOf($userId);
        self::assertNotNull($preHashed, 'insertLegacyUser() should already fill email_hmac by default.');

        $this->migration()->upWithConfig($this->conn, $this->testConfig());

        self::assertSame($preHashed, $this->emailHmacOf($userId));
    }

    public function test_second_run_is_a_no_op(): void
    {
        $userId = $this->insertLegacyUser('idempotent@example.com', withHmac: false);

        $migration = $this->migration();
        $migration->upWithConfig($this->conn, $this->testConfig());
        $first = $this->emailHmacOf($userId);

        $migration->upWithConfig($this->conn, $this->testConfig());
        $second = $this->emailHmacOf($userId);

        self::assertSame($first, $second);
    }

    /**
     * Proves that the batch loop (BATCH_SIZE = 500) terminates correctly
     * across multiple rounds and covers ALL rows, not just the first round.
     */
    public function test_backfill_processes_more_rows_than_one_batch(): void
    {
        $userIds = [];
        for ($i = 0; $i < 520; ++$i) {
            $userIds[] = $this->insertLegacyUser("batch-{$i}@example.com", withHmac: false);
        }

        $this->migration()->upWithConfig($this->conn, $this->testConfig());

        $stillNull = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users WHERE email_hmac IS NULL');
        self::assertSame(0, $stillNull);

        $expected = (new IdentityHasher(self::identityServerKey()))->hash('batch-0@example.com');
        self::assertSame($expected, $this->emailHmacOf($userIds[0]));
        $expectedLast = (new IdentityHasher(self::identityServerKey()))->hash('batch-519@example.com');
        self::assertSame($expectedLast, $this->emailHmacOf($userIds[519]));
    }

    public function test_up_without_config_throws_instead_of_silently_skipping(): void
    {
        $this->insertLegacyUser('someone@example.com', withHmac: false);

        $this->expectException(\RuntimeException::class);
        $this->migration()->up($this->conn);
    }
}
