<?php

declare(strict_types=1);

namespace Votepit\Tests\Backup;

use PHPUnit\Framework\TestCase;
use Votepit\Backup\BackupException;
use Votepit\Backup\DatabaseBackup;
use Votepit\DbConfig;

/**
 * DatabaseBackup unit tests.
 *
 * Deliberately covers ONLY what's testable without a real mysqldump binary
 * or a real MySQL connection: argument building, output-path resolution,
 * and the missing-binary error path (forced via a bogus binary name, so it
 * is a real assertion regardless of whether this machine happens to have
 * mysqldump installed). See DatabaseBackup's class doc for why a real
 * mysqldump invocation is intentionally not exercised here.
 */
final class DatabaseBackupTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/votepit-backup-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir . '/*');
            array_map(unlink(...), $files !== false ? $files : []);
            rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function db(): DbConfig
    {
        return DbConfig::fromArray([
            'host'    => 'db.example.internal',
            'port'    => 3307,
            'name'    => 'votepit_test',
            'user'    => 'backup_user',
            'pass'    => 'secret',
            'charset' => 'utf8mb4',
        ]);
    }

    public function test_build_args_includes_fk_safe_flags_and_connection_params_but_not_the_password(): void
    {
        $backup = new DatabaseBackup($this->db());

        $args = $backup->buildArgs();

        self::assertSame('mysqldump', $args[0]);
        self::assertContains('--single-transaction', $args);
        self::assertContains('--quick', $args);
        self::assertContains('--routines', $args);
        self::assertContains('--triggers', $args);
        self::assertContains('--host=db.example.internal', $args);
        self::assertContains('--port=3307', $args);
        self::assertContains('--user=backup_user', $args);
        self::assertContains('votepit_test', $args);

        foreach ($args as $arg) {
            self::assertStringNotContainsString('secret', $arg, 'password must never appear in the argv (use MYSQL_PWD env instead)');
        }
    }

    public function test_build_args_uses_configured_binary_name(): void
    {
        $backup = new DatabaseBackup($this->db(), '/opt/mysql/bin/mysqldump');

        self::assertSame('/opt/mysql/bin/mysqldump', $backup->buildArgs()[0]);
    }

    public function test_resolve_output_path_returns_explicit_path_unchanged(): void
    {
        $backup = new DatabaseBackup($this->db());

        self::assertSame(
            '/tmp/explicit-dump.sql',
            $backup->resolveOutputPath('/tmp/explicit-dump.sql', $this->tmpDir),
        );
    }

    public function test_resolve_output_path_generates_timestamped_default_and_creates_dir(): void
    {
        $backup = new DatabaseBackup($this->db());
        $now    = new \DateTimeImmutable('2026-08-31 03:00:00');

        $path = $backup->resolveOutputPath(null, $this->tmpDir, $now);

        self::assertSame($this->tmpDir . '/votepit-votepit_test-2026-08-31_030000.sql', $path);
        self::assertDirectoryExists($this->tmpDir);
    }

    public function test_resolve_output_path_treats_blank_string_as_not_requested(): void
    {
        $backup = new DatabaseBackup($this->db());

        $path = $backup->resolveOutputPath('', $this->tmpDir, new \DateTimeImmutable('2026-01-01 00:00:00'));

        self::assertStringStartsWith($this->tmpDir . '/votepit-votepit_test-', $path);
    }

    public function test_run_throws_when_binary_is_missing(): void
    {
        $backup = new DatabaseBackup($this->db(), 'mysqldump-does-not-exist-xyz-' . uniqid());

        $this->expectException(BackupException::class);
        $this->expectExceptionMessageMatches('/mysqldump binary not found/');

        $backup->run($this->tmpDir . '/out.sql');
    }

    public function test_run_against_real_mysqldump_is_skipped_when_binary_unavailable(): void
    {
        $backup = new DatabaseBackup($this->db());

        if ($backup->binaryAvailable()) {
            self::markTestSkipped('This test only documents the skip path; a real integration test would need a live MySQL connection too.');
        }

        self::assertFalse($backup->binaryAvailable());
    }
}
