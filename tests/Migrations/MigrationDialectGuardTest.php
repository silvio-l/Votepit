<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;

/**
 * Migrations must run on MySQL 8.0.19+ and MariaDB 10.6+. `DROP CHECK` is
 * MySQL-only (MariaDB rejects it); `DROP CONSTRAINT` works on both.
 */
final class MigrationDialectGuardTest extends TestCase
{
    public function test_no_migration_uses_mysql_only_drop_check(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/migrations/*.{sql,php}', GLOB_BRACE);
        self::assertNotEmpty($files);

        $offenders = [];
        foreach ($files as $file) {
            if (preg_match('/\bDROP\s+CHECK\b/i', (string) file_get_contents($file)) === 1) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'Use DROP CONSTRAINT instead of DROP CHECK (MariaDB).');
    }
}
