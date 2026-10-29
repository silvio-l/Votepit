<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use Votepit\Cron\CatchUpPolicy;
use Votepit\Cron\CronJobDefinition;
use Votepit\Cron\InlineJobDispatcher;
use Votepit\Cron\WorkerCommand;
use Votepit\Persistence\CronJobRepository;
use Votepit\Tests\Support\IntegrationTestCase;

final class InlineJobDispatcherTest extends IntegrationTestCase
{
    private function definition(): CronJobDefinition
    {
        return new CronJobDefinition('job-a', ['/usr/bin/php', '/app/job.php'], '17 3 * * *', CatchUpPolicy::Latest, 60, 'core');
    }

    public function test_dispatch_spawns_the_worker_with_argv_and_no_shell(): void
    {
        $spawner    = new FakeChildProcessSpawner();
        $dispatcher = new InlineJobDispatcher(
            new CronJobRepository($this->conn),
            $spawner,
            new WorkerCommand('/usr/bin/php', '/app/bin/votepit-cron.php'),
            '/app/logs/cron',
            microtime(true) + 50,
            static fn (): null => null,
        );

        $dispatcher->dispatch($this->definition(), str_repeat('a', 32));

        self::assertSame(
            ['/usr/bin/php', '/app/bin/votepit-cron.php', '--run-job=job-a', '--token=' . str_repeat('a', 32)],
            $spawner->spawns[0]['argv'],
        );
        self::assertSame('/app/logs/cron/job-a.log', $spawner->spawns[0]['logPath']);
    }

    public function test_await_all_waits_for_children_then_returns(): void
    {
        $spawner    = new FakeChildProcessSpawner();
        $dispatcher = new InlineJobDispatcher(
            new CronJobRepository($this->conn),
            $spawner,
            new WorkerCommand('/usr/bin/php', '/app/bin/votepit-cron.php'),
            '/app/logs/cron',
            microtime(true) + 50,
            static function () use ($spawner): void {
                foreach ($spawner->children as $child) {
                    $child->finish();
                }
            },
        );
        $dispatcher->dispatch($this->definition(), str_repeat('a', 32));

        $dispatcher->awaitAll();

        self::assertFalse($spawner->children[0]->terminated);
    }

    public function test_children_still_running_at_the_tick_deadline_are_terminated(): void
    {
        $spawner    = new FakeChildProcessSpawner();
        $dispatcher = new InlineJobDispatcher(
            new CronJobRepository($this->conn),
            $spawner,
            new WorkerCommand('/usr/bin/php', '/app/bin/votepit-cron.php'),
            '/app/logs/cron',
            microtime(true) - 1, // deadline already passed
            static fn (): null => null,
        );
        $dispatcher->dispatch($this->definition(), str_repeat('a', 32));

        $dispatcher->awaitAll();

        self::assertTrue($spawner->children[0]->terminated);
    }
}
