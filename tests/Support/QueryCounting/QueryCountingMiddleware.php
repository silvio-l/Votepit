<?php

declare(strict_types=1);

namespace Votepit\Tests\Support\QueryCounting;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Test-only DBAL driver middleware that records every statement sent to the
 * database into a QueryLog (query budgets, issue M2).
 *
 * Counted: Connection::query(), Connection::exec() and every execute() of a
 * prepared statement (a statement prepared once and executed N times counts
 * N times — that is exactly the N+1 shape the budgets must catch).
 * Not counted: transaction control (begin/commit/rollback) and quote().
 */
final readonly class QueryCountingMiddleware implements Middleware
{
    public function __construct(private QueryLog $log) {}

    public function wrap(Driver $driver): Driver
    {
        $log = $this->log;

        return new class ($driver, $log) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly QueryLog $log)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                $log = $this->log;

                return new class (parent::connect($params), $log) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly QueryLog $log)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $log = $this->log;

                        return new class (parent::prepare($sql), $log, $sql) extends AbstractStatementMiddleware {
                            public function __construct(
                                Statement $statement,
                                private readonly QueryLog $log,
                                private readonly string $sql,
                            ) {
                                parent::__construct($statement);
                            }

                            public function execute(): Result
                            {
                                $this->log->record($this->sql);

                                return parent::execute();
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        $this->log->record($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->log->record($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
