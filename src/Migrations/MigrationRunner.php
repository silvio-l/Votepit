<?php

declare(strict_types=1);

namespace Votepit\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Votepit\Config;

/**
 * Versioned, forward-only migration runner (foundation for the multi-tenant
 * transition).
 *
 * Deliberately no doctrine/migrations or similar: shared-hosting-compatible
 * means no SSH requirement and no server-side `composer install` — a lean
 * homegrown solution is enough for what's needed here.
 *
 * Migration files live in $migrationsDir as NNNN_description.sql (via
 * SqlFileMigration) or NNNN_description.php (must return a Migration
 * instance: `return new SomeMigrationClass();`). Order = string sort of
 * version() (== filename without extension) — the 4-digit numeric prefix
 * thus guarantees the correct chronological order.
 *
 * Applied versions are tracked in schema_migrations (version + SHA-256
 * checksum of the source file — drift protection against migrations edited
 * after the fact). Fail-fast: if a migration fails, it is NOT tracked and
 * none of the subsequent migrations are attempted.
 *
 * discover() is evaluated exactly once per runner instance (cached):
 * .php migration files typically declare a named class inline; a second
 * `require` of the same file would cause a "Cannot redeclare class" fatal.
 * Since CLI/callers typically call pending() AND migrate() on the same
 * instance, this caching prevents that problem without callers needing to
 * know about it.
 */
final class MigrationRunner
{
    /** @var list<Migration>|null */
    private ?array $discovered = null;

    /** @var array<string, string> Version => source file path (for checksums). */
    private array $sourceFiles = [];

    /**
     * Process-wide (not instance-bound) cache: file path => already-loaded
     * .php migration. Multiple MigrationRunner instances in the same PHP
     * process (e.g. several test classes that each open their own instance
     * against the same migrations/ directory) must not `require` the same
     * .php migration file twice — the class would then already be declared
     * ("Cannot redeclare class"). The instance caching in $discovered only
     * protects repeated discover() calls on the SAME instance, not multiple
     * instances in the same process.
     *
     * @var array<string, Migration>
     */
    private static array $phpMigrationCache = [];

    /**
     * @param string $trackingTable Table that records applied versions. Core
     *        uses the default; an extension with its own migrations directory
     *        uses a separate table so the two version streams never collide.
     */
    public function __construct(
        private readonly Connection $conn,
        private readonly string $migrationsDir,
        private readonly ?Config $config = null,
        private readonly string $trackingTable = 'schema_migrations',
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $trackingTable) !== 1) {
            throw new \InvalidArgumentException("MigrationRunner: invalid tracking table name '{$trackingTable}'");
        }
    }

    /** @return list<Migration> */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        /** @var array<string, Migration> $migrations Version => Migration (duplicate protection) */
        $migrations = [];

        $sqlFiles = glob($this->migrationsDir . '/*.sql');
        foreach ($sqlFiles === false ? [] : $sqlFiles as $file) {
            $migration = new SqlFileMigration($file);
            $this->registerDiscovered($migrations, $migration, $file);
        }

        $phpFiles = glob($this->migrationsDir . '/*.php');
        foreach ($phpFiles === false ? [] : $phpFiles as $file) {
            if (!isset(self::$phpMigrationCache[$file])) {
                $migration = require $file;
                if (!$migration instanceof Migration) {
                    throw new \RuntimeException(
                        "Migration: {$file} must return a Migration instance (`return new ...;`)",
                    );
                }
                self::$phpMigrationCache[$file] = $migration;
            }
            $this->registerDiscovered($migrations, self::$phpMigrationCache[$file], $file);
        }

        ksort($migrations, SORT_STRING);

        return $this->discovered = array_values($migrations);
    }

    /** @param array<string, Migration> $migrations */
    private function registerDiscovered(array &$migrations, Migration $migration, string $file): void
    {
        $version = $migration->version();
        if (isset($migrations[$version])) {
            throw new \RuntimeException("Migration: version '{$version}' exists more than once ({$this->sourceFiles[$version]} and {$file}).");
        }

        $migrations[$version]    = $migration;
        $this->sourceFiles[$version] = $file;
    }

    /** Creates the tracking table (default: schema_migrations) if it doesn't already exist. Idempotent. */
    public function ensureTrackingTable(): void
    {
        if ($this->conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->conn->executeStatement(
                "CREATE TABLE IF NOT EXISTS {$this->trackingTable} (
                    version    VARCHAR(64) NOT NULL,
                    checksum   CHAR(64)    NOT NULL,
                    applied_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (version)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            );
        } else {
            // SQLite-compatible (tests) — same pattern as RateLimiter/SmtpSettingsRepository.
            $this->conn->executeStatement(
                "CREATE TABLE IF NOT EXISTS {$this->trackingTable} (
                    version    VARCHAR(64) NOT NULL,
                    checksum   CHAR(64)    NOT NULL,
                    applied_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (version)
                )",
            );
        }
    }

    /** @return list<Migration> */
    public function pending(): array
    {
        $this->ensureTrackingTable();

        $applied = array_flip($this->appliedVersions());

        return array_values(array_filter(
            $this->discover(),
            static fn (Migration $m): bool => !isset($applied[$m->version()]),
        ));
    }

    /**
     * Applies all pending migrations, in order. If a migration fails, an
     * exception is thrown and no further migration is attempted; the failed
     * migration leaves no schema_migrations entry.
     *
     * @return list<string> Versions of the migrations actually applied.
     */
    public function migrate(): array
    {
        $applied = [];

        foreach ($this->pending() as $migration) {
            try {
                if ($migration instanceof ConfigAwareMigration && $this->config instanceof Config) {
                    $migration->upWithConfig($this->conn, $this->config);
                } else {
                    $migration->up($this->conn);
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Migration '{$migration->version()}' failed: {$e->getMessage()}",
                    0,
                    $e,
                );
            }

            $this->conn->insert($this->trackingTable, [
                'version'    => $migration->version(),
                'checksum'   => $this->checksum($migration),
                'applied_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            $applied[] = $migration->version();
        }

        return $applied;
    }

    /** @return list<string> */
    private function appliedVersions(): array
    {
        /** @var list<string> */
        return $this->conn->fetchFirstColumn("SELECT version FROM {$this->trackingTable}");
    }

    private function checksum(Migration $migration): string
    {
        $path = $this->sourceFiles[$migration->version()] ?? null;
        if ($path === null) {
            throw new \RuntimeException("Migration '{$migration->version()}': source file for checksum unknown.");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Migration '{$migration->version()}': source file not readable: {$path}");
        }

        return hash('sha256', $contents);
    }
}
