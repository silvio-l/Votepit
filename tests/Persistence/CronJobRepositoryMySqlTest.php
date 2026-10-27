<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Votepit\Cron\CatchUpPolicy;
use Votepit\Cron\CronJobDefinition;

/**
 * The same claim/fence/lease scenarios against real MySQL/InnoDB, where the
 * conditional UPDATE and its affected-row count are what enforce exactly-once.
 *
 * Opt-in: runs only when VOTEPIT_TEST_MYSQL_URL points at a DISPOSABLE
 * database (e.g. mysql://user:pass@127.0.0.1/votepit_cron_test). The table is
 * dropped and recreated before every test. Skipped otherwise, so the default
 * `composer test` needs no MySQL server.
 */
final class CronJobRepositoryMySqlTest extends TestCase
{
    use CronClaimScenarios;

    private Connection $conn;

    /** A second, independent connection — two managers racing on one table. */
    private Connection $other;

    protected function setUp(): void
    {
        parent::setUp();

        $url = getenv('VOTEPIT_TEST_MYSQL_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('VOTEPIT_TEST_MYSQL_URL not set (disposable MySQL database required).');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            self::fail('VOTEPIT_TEST_MYSQL_URL must look like mysql://user:pass@host:port/database.');
        }
        $params = [
            'driver'   => 'pdo_mysql',
            'host'     => $parts['host'],
            'port'     => $parts['port'] ?? 3306,
            'user'     => rawurldecode($parts['user'] ?? ''),
            'password' => rawurldecode($parts['pass'] ?? ''),
            'dbname'   => ltrim($parts['path'], '/'),
        ];

        $this->conn  = DriverManager::getConnection($params);
        $this->other = DriverManager::getConnection($params);

        $this->conn->executeStatement('DROP TABLE IF EXISTS cron_jobs');
        $migration = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0090_add_cron_jobs.sql');
        $this->conn->executeStatement($migration);
    }

    protected function connection(): Connection
    {
        return $this->conn;
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            $this->conn->executeStatement('DROP TABLE IF EXISTS cron_jobs');
        }
        parent::tearDown();
    }

    public function test_two_connections_racing_for_one_slot_produce_exactly_one_winner(): void
    {
        $this->registerJob(CatchUpPolicy::Latest);
        $slot = $this->utc('2026-10-22 03:17:00');
        $now  = $this->utc('2026-10-22 03:17:05');

        $winners = 0;
        foreach ([$this->conn, $this->other] as $i => $conn) {
            $token = str_repeat((string) $i, 32);
            if ($this->repo($conn)->claim('job-a', $slot, $token, $now->modify('+3 minutes'), $now)) {
                $winners++;
            }
        }

        self::assertSame(1, $winners);
    }

    public function test_redefining_a_job_on_mysql_keeps_its_lease(): void
    {
        $this->registerJob();
        $repo = $this->repo();
        $now  = $this->utc('2026-10-22 03:17:05');
        $repo->claim('job-a', $this->utc('2026-10-22 03:17:00'), str_repeat('a', 32), $now->modify('+1 minute'), $now);

        $repo->upsertDefinition(new CronJobDefinition('job-a', ['/usr/bin/php', '/app/job.php'], '5 * * * *', CatchUpPolicy::None, 120, 'core'));

        self::assertSame(str_repeat('a', 32), $repo->find('job-a')?->leaseToken);
        self::assertSame('5 * * * *', $this->conn->fetchOne("SELECT cron_expr FROM cron_jobs WHERE job_name = 'job-a'"));
    }
}
