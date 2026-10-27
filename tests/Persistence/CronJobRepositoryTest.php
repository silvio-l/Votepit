<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Doctrine\DBAL\Connection;
use Votepit\Tests\Support\IntegrationTestCase;

/** Claim/fence/lease semantics on the portable SQLite test schema. */
final class CronJobRepositoryTest extends IntegrationTestCase
{
    use CronClaimScenarios;

    protected function connection(): Connection
    {
        return $this->conn;
    }
}
