<?php

declare(strict_types=1);

namespace Votepit\Backup;

use Votepit\DbConfig;
use Votepit\Migrations\MigrationRunner;
use Votepit\Persistence\ConnectionFactory;

/**
 * Rehearsal pipeline: prod dump -> restore into a THROWAWAY
 * database -> migrations-runner pending() dry-run against the restored
 * copy. Mirrors bin/migrate.php's use of MigrationRunner; never calls
 * migrate() — a rehearsal only ever reports, it never mutates.
 *
 * Hard safety invariant (non-negotiable per the task): this class must NEVER
 * be pointed at the app's own configured production database. guardTarget()
 * refuses to proceed if the target DB name equals the configured production
 * DB name — an operator restoring a rehearsal copy must pick a distinct
 * name (e.g. "votepit_restore_test"), which is exactly the kind of
 * fat-fingered mistake ("restore prod dump into prod") this check exists to
 * catch. This is a name check, not a full connection-identity check,
 * deliberately: reusing the same host with a differently-named throwaway DB
 * is the expected/common case and must stay allowed.
 */
final readonly class RestoreRehearsal
{
    public function __construct(
        private string $mysqlBinary = 'mysql',
    ) {}

    /**
     * @throws BackupException if $target is (or looks like) the configured
     *                          production database.
     */
    public function guardTarget(DbConfig $configuredProduction, DbConfig $target): void
    {
        if ($target->name === $configuredProduction->name) {
            throw new BackupException(
                "Safety check: target DB name '{$target->name}' is identical to the configured "
                . "production DB. The restore-rehearsal target DB MUST have its own, distinct name "
                . '(e.g. "votepit_restore_test") — never the configured production DB itself.',
            );
        }
    }

    /** True if the configured mysql-client binary is resolvable on PATH. */
    public function binaryAvailable(): bool
    {
        $found = shell_exec('command -v ' . escapeshellarg($this->mysqlBinary) . ' 2>/dev/null');
        return is_string($found) && trim($found) !== '';
    }

    /**
     * Restores $dumpFile into $target (CREATE DATABASE IF NOT EXISTS + load
     * the dump). Never touches anything but $target — the caller is
     * responsible for having already run guardTarget() first.
     *
     * @throws BackupException
     */
    public function restore(string $dumpFile, DbConfig $target): void
    {
        if (!is_file($dumpFile)) {
            throw new BackupException("Dump file not found: {$dumpFile}");
        }

        if (!$this->binaryAvailable()) {
            throw new BackupException(
                "mysql binary not found ({$this->mysqlBinary}). Is mysql-client installed and on PATH?",
            );
        }

        $env = array_merge($_ENV, ['MYSQL_PWD' => $target->pass]);

        $this->exec([
            $this->mysqlBinary,
            '--host=' . $target->host,
            '--port=' . $target->port,
            '--user=' . $target->user,
            '--execute=CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $target->name) . '`',
        ], $env);

        $dumpHandle = fopen($dumpFile, 'rb');
        if ($dumpHandle === false) {
            throw new BackupException("Dump file not readable: {$dumpFile}");
        }

        $this->exec([
            $this->mysqlBinary,
            '--host=' . $target->host,
            '--port=' . $target->port,
            '--user=' . $target->user,
            $target->name,
        ], $env, $dumpHandle);
    }

    /**
     * Connects to the restored target and runs the migrations-runner's
     * pending-migrations check (dry-run only — never migrate()). Returns the
     * list of pending migration versions found on the restored copy (a
     * non-empty list is NOT itself a failure — it just means the dump
     * predates some migrations, the normal case for a rolling nightly
     * rehearsal). Any thrown exception (connection failure, corrupt
     * restore, ...) IS the failure signal this method's caller should act on.
     *
     * @return list<string>
     * @throws BackupException
     */
    public function dryRunPendingMigrations(DbConfig $target, string $migrationsDir): array
    {
        try {
            $conn   = ConnectionFactory::createForDb($target);
            $runner = new MigrationRunner($conn, $migrationsDir);

            return array_map(
                static fn (\Votepit\Migrations\Migration $m): string => $m->version(),
                $runner->pending(),
            );
        } catch (\Throwable $e) {
            throw new BackupException(
                'Migrations dry-run against the restored copy failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * @param list<string>              $args
     * @param array<string, string|false> $env
     * @throws BackupException
     */
    private function exec(array $args, array $env, mixed $stdin = null): void
    {
        $descriptors = [
            0 => $stdin ?? ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($args, $descriptors, $pipes, null, $env);

        if (!is_resource($process)) {
            throw new BackupException('mysql client could not be started (proc_open failed).');
        }

        if ($stdin === null) {
            fclose($pipes[0]);
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new BackupException(
                "mysql client failed (exit {$exitCode}): " . trim($stderr . $stdout),
            );
        }
    }
}
