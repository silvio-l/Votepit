<?php

declare(strict_types=1);

namespace Votepit\Tests\Monitoring;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Votepit\Migrations\MigrationRunner;
use Votepit\Monitoring\ReadinessCheck;

/**
 * ReadinessCheck in isolation — the unreachable-database case cannot be
 * produced through the shared in-memory test connection.
 */
final class ReadinessCheckTest extends TestCase
{
    public function test_unreachable_database_fails_only_the_database_check(): void
    {
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path'   => '/nonexistent-' . uniqid() . '/votepit.sqlite',
        ]);

        $check = new ReadinessCheck(
            $conn,
            [new MigrationRunner($conn, __DIR__ . '/../../migrations')],
            120,
        );

        // Later checks would only repeat the same connection error.
        self::assertSame([ReadinessCheck::DATABASE], $check->failures(new \DateTimeImmutable()));
    }
}
