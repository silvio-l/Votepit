<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use Votepit\Config;
use Votepit\Cron\CronCli;
use Votepit\Persistence\CronHeartbeatRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/** Heartbeats written by one manager run (readiness and the watchdog read them). */
final class CronCliTest extends IntegrationTestCase
{
    /** @param \Closure(): list<\Votepit\Cron\CronJobDefinition> $registry */
    private function runTick(\Closure $registry): int
    {
        $config = Config::fromArray($this->testConfigArray());
        ob_start();
        try {
            return CronCli::run(['votepit-cron.php'], dirname(__DIR__, 2), __FILE__, $config, $this->testConfigArray(), $this->conn, $registry);
        } finally {
            ob_end_clean();
        }
    }

    public function test_a_tick_records_a_start_heartbeat_and_a_pass_heartbeat(): void
    {
        self::assertSame(0, $this->runTick(static fn (): array => []));

        $all = (new CronHeartbeatRepository($this->conn))->all();
        self::assertSame('success', $all['cron-manager']['status'] ?? null);
        self::assertSame('success', $all['cron-manager-pass']['status'] ?? null);
    }

    public function test_a_tick_that_errors_records_a_failure_on_the_manager_heartbeat(): void
    {
        $exit = $this->runTick(static function (): array {
            throw new \RuntimeException('registry broken');
        });

        self::assertSame(1, $exit);
        $all = (new CronHeartbeatRepository($this->conn))->all();
        self::assertSame('failure', $all['cron-manager']['status'] ?? null);
        self::assertArrayNotHasKey('cron-manager-pass', $all);
    }
}
