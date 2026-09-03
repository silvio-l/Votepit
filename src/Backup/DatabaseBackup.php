<?php

declare(strict_types=1);

namespace Votepit\Backup;

use Votepit\DbConfig;

/**
 * Produces a portable, FK/InnoDB-safe SQL dump of the configured MySQL database.
 *
 * Design decision: shell out to the `mysqldump` binary rather than write a
 * pure-PHP dumper. Rationale (flagged here, not in a separate doc, per
 * migrate.php's own doc-comment convention):
 *   - `mysqldump` already gets InnoDB consistency (--single-transaction),
 *     FK-safe ordering, routines/triggers, and charset handling exactly
 *     right; a hand-rolled PHP dumper would have to re-solve all of that
 *     (FK-respecting table order, generated columns, views) and would be a
 *     second, un-battle-tested source of restore bugs — the opposite of
 *     what a disaster-recovery tool should be.
 *   - It is the tool every hosting provider (incl. the target NAS/host)
 *     already ships, so "the dump was made with a tool nobody has ever
 *     restored with" is not a risk here.
 *   - The trade-off is that it requires the `mysqldump` binary to be present
 *     wherever this script runs, and it isn't exercisable in an environment
 *     without MySQL/mysqldump (this dev sandbox has neither) — the pure
 *     command-building/path/config logic below is unit-tested without the
 *     binary; the actual shell-out is skipped gracefully in tests. See
 *     tests/Backup/DatabaseBackupTest.php.
 *
 * Split into pure/testable pieces (buildArgs/resolveOutputPath) and the
 * actual shell-out (run()) so the former can be covered without the binary.
 */
final readonly class DatabaseBackup
{
    public function __construct(
        private DbConfig $db,
        private string $mysqldumpBinary = 'mysqldump',
    ) {}

    /**
     * Builds the mysqldump argv (NOT a shell string — always passed to
     * proc_open() as an array, so no shell-escaping/injection surface).
     * The password is deliberately NOT included here: it is passed via the
     * MYSQL_PWD environment variable by run(), so it never shows up in
     * `ps`/process-list output (unlike `mysqldump -p...`, which does).
     *
     * @return list<string>
     */
    public function buildArgs(): array
    {
        $args = [
            $this->mysqldumpBinary,
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
        ];

        // --set-gtid-purged is a MySQL-only flag (GTID metadata in the dump
        // header); MariaDB's mysqldump rejects unknown flags outright ("unknown
        // variable"), so only add it against a genuine MySQL server — otherwise
        // the whole backup fails, both for self-hosters and Cloud when either
        // runs MariaDB (as this project's own production host does).
        if (!$this->isMariaDb()) {
            $args[] = '--set-gtid-purged=OFF';
        }

        return [
            ...$args,
            '--default-character-set=' . $this->db->charset,
            '--host=' . $this->db->host,
            '--port=' . $this->db->port,
            '--user=' . $this->db->user,
            $this->db->name,
        ];
    }

    private function isMariaDb(): bool
    {
        $version = shell_exec(escapeshellarg($this->mysqldumpBinary) . ' --version 2>/dev/null');
        return is_string($version) && stripos($version, 'MariaDB') !== false;
    }

    /**
     * Resolves the dump's output path: an explicit `$requested` path wins;
     * otherwise a timestamped default filename is generated inside
     * `$defaultDir` (created if missing). Kept pure (no I/O beyond the
     * directory create) so it's testable without mysqldump.
     */
    public function resolveOutputPath(?string $requested, string $defaultDir, ?\DateTimeImmutable $now = null): string
    {
        if ($requested !== null && trim($requested) !== '') {
            return $requested;
        }

        $now ??= new \DateTimeImmutable();

        if (!is_dir($defaultDir) && !mkdir($defaultDir, 0o770, true) && !is_dir($defaultDir)) {
            throw new BackupException("Backup directory could not be created: {$defaultDir}");
        }

        return rtrim($defaultDir, '/') . '/' . sprintf(
            'votepit-%s-%s.sql',
            $this->db->name,
            $now->format('Y-m-d_His'),
        );
    }

    /** True if the configured mysqldump binary is resolvable on PATH. */
    public function binaryAvailable(): bool
    {
        $found = shell_exec('command -v ' . escapeshellarg($this->mysqldumpBinary) . ' 2>/dev/null');
        return is_string($found) && trim($found) !== '';
    }

    /**
     * Runs mysqldump, writing the dump to $outputPath. Throws BackupException
     * if the binary is missing (a real config error — fail loud) or falls
     * back to a PDO-based dump (dumpViaPdo()) if the binary exists but the
     * invocation itself fails. That fallback exists because a MariaDB-built
     * mysqldump client cannot authenticate against a MySQL 8 server's default
     * `caching_sha2_password` accounts at all (observed on this project's own
     * production host) — no password, flag, or grant fixes that from
     * the client side, and it can hit any self-hoster on a similarly mixed
     * MariaDB-client/MySQL-server stack too. Without the fallback, backups
     * would silently never run on such hosts.
     *
     * @throws BackupException
     */
    public function run(string $outputPath): void
    {
        if (!$this->binaryAvailable()) {
            throw new BackupException(
                "mysqldump binary not found ({$this->mysqldumpBinary}). Is mysql-client installed and on PATH?",
            );
        }

        try {
            $this->runViaMysqldump($outputPath);
        } catch (BackupException $e) {
            error_log('[votepit-backup] mysqldump failed, falling back to PDO dump: ' . $e->getMessage());
            $this->dumpViaPdo($outputPath);
        }
    }

    /** @throws BackupException */
    private function runViaMysqldump(string $outputPath): void
    {
        $outFile = fopen($outputPath, 'wb');
        if ($outFile === false) {
            throw new BackupException("Backup output file not writable: {$outputPath}");
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => $outFile,
            2 => ['pipe', 'w'],
        ];

        $env            = array_merge($_ENV, ['MYSQL_PWD' => $this->db->pass]);
        $process        = proc_open($this->buildArgs(), $descriptors, $pipes, null, $env);

        if (!is_resource($process)) {
            fclose($outFile);
            throw new BackupException('mysqldump could not be started (proc_open failed).');
        }

        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        fclose($outFile);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new BackupException(
                "mysqldump failed (exit {$exitCode}): " . trim((string) $stderr),
            );
        }
    }

    /**
     * Pure-PHP fallback dump via PDO (mysqlnd, which — unlike a MariaDB-built
     * mysqldump — does support caching_sha2_password). Deliberately simple:
     * disables FK checks around the whole restore instead of solving FK-safe
     * table ordering, so per-table dump order doesn't matter. Not a full
     * mysqldump replacement (no routines/triggers/views) — those are rare in
     * this schema and can be added if that ever changes.
     *
     * @throws BackupException
     */
    private function dumpViaPdo(string $outputPath): void
    {
        try {
            $pdo = new \PDO(
                "mysql:host={$this->db->host};port={$this->db->port};dbname={$this->db->name};charset={$this->db->charset}",
                $this->db->user,
                $this->db->pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\PDOException $e) {
            throw new BackupException('PDO fallback backup: connection failed: ' . $e->getMessage(), $e->getCode(), $e);
        }

        $outFile = fopen($outputPath, 'wb');
        if ($outFile === false) {
            throw new BackupException("Backup output file not writable: {$outputPath}");
        }

        try {
            fwrite($outFile, "-- votepit PDO-fallback dump (mysqldump unavailable/incompatible)\n");
            fwrite($outFile, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            $tables = $this->pdoQuery($pdo, 'SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $createRow = $this->pdoQuery($pdo, 'SHOW CREATE TABLE `' . $table . '`')->fetch(\PDO::FETCH_NUM);
                fwrite($outFile, "DROP TABLE IF EXISTS `{$table}`;\n{$createRow[1]};\n\n");

                $stmt = $this->pdoQuery($pdo, 'SELECT * FROM `' . $table . '`');
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    $columns = implode('`,`', array_keys($row));
                    $values  = implode(',', array_map(
                        static fn (mixed $v): string => $v === null ? 'NULL' : $pdo->quote((string) $v),
                        array_values($row),
                    ));
                    fwrite($outFile, "INSERT INTO `{$table}` (`{$columns}`) VALUES ({$values});\n");
                }
                fwrite($outFile, "\n");
            }

            fwrite($outFile, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($outFile);
        }
    }

    /** @throws BackupException */
    private function pdoQuery(\PDO $pdo, string $sql): \PDOStatement
    {
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            throw new BackupException("PDO fallback backup: query failed: {$sql}");
        }

        return $stmt;
    }
}
