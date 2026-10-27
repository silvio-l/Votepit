<?php

declare(strict_types=1);

namespace Votepit\Tests\Performance;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Votepit\Tests\Support\QueryCounting\QueryCountingMiddleware;
use Votepit\Tests\Support\QueryCounting\QueryLog;

/**
 * The counting middleware itself: every round trip counts, including each
 * execution of a statement that was prepared once (the N+1 shape).
 */
final class QueryCountingMiddlewareTest extends TestCase
{
    public function test_counts_exec_query_and_every_prepared_execution(): void
    {
        $log  = new QueryLog();
        $conn = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            (new Configuration())->setMiddlewares([new QueryCountingMiddleware($log)]),
        );

        $conn->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $conn->insert('t', ['v' => 'a']);
        $conn->fetchOne('SELECT COUNT(*) FROM t');
        self::assertSame(3, $log->count());

        $log->reset();
        $stmt = $conn->prepare('SELECT v FROM t WHERE id = ?');
        foreach ([1, 2, 3] as $id) {
            $stmt->bindValue(1, $id);
            $stmt->executeQuery();
        }

        self::assertSame(3, $log->count(), 'One prepare, three executions = three queries.');
        self::assertStringContainsString('SELECT v FROM t WHERE id = ?', $log->dump());
    }
}
