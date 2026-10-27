<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use Votepit\Cron\CatchUpPolicy;
use Votepit\Cron\CronJobDefinition;
use Votepit\Cron\CronManager;
use Votepit\Persistence\CronJobRepository;
use Votepit\Tests\Support\IntegrationTestCase;

final class CronManagerTest extends IntegrationTestCase
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string $expr
     */
    private function definition(string $name, CatchUpPolicy $catchUp = CatchUpPolicy::Latest, string $expr = '17 3 * * *'): CronJobDefinition
    {
        return new CronJobDefinition($name, ['/usr/bin/php', '/app/' . $name . '.php'], $expr, $catchUp, 60, 'core');
    }

    private function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /** @param list<int> $signalled receives every pid the manager asks to kill */
    private function manager(FakeJobDispatcher $dispatcher, int $cap = 3, array &$signalled = []): CronManager
    {
        return new CronManager(
            new CronJobRepository($this->conn),
            $dispatcher,
            $cap,
            120,
            static function (int $pid) use (&$signalled): bool {
                $signalled[] = $pid;

                return true;
            },
        );
    }

    public function test_a_due_job_is_dispatched_once_per_slot(): void
    {
        $dispatcher = new FakeJobDispatcher();
        $manager    = $this->manager($dispatcher);
        $jobs       = [$this->definition('cleanup-a')];

        $first = $manager->tick($jobs, $this->at('2026-10-22 03:17:20'));
        $again = $manager->tick($jobs, $this->at('2026-10-22 03:17:45'));

        self::assertSame(['cleanup-a'], $first->dispatched);
        self::assertSame([], $again->dispatched);
        self::assertCount(1, $dispatcher->dispatched);
        self::assertSame(2, $dispatcher->awaited, 'each tick waits for its dispatcher exactly once');
    }

    public function test_the_concurrency_cap_defers_jobs_beyond_it(): void
    {
        $dispatcher = new FakeJobDispatcher();
        $manager    = $this->manager($dispatcher, 3);
        $jobs       = [$this->definition('job-a'), $this->definition('job-b'), $this->definition('job-c'), $this->definition('job-d')];

        $report = $manager->tick($jobs, $this->at('2026-10-22 03:17:20'));

        self::assertCount(3, $report->dispatched);
        self::assertSame(['job-d'], $report->deferred);
    }

    public function test_live_leases_held_by_earlier_ticks_count_against_the_cap(): void
    {
        $repo = new CronJobRepository($this->conn);
        $now  = $this->at('2026-10-22 03:17:20');
        foreach (['held-1', 'held-2'] as $name) {
            $repo->upsertDefinition($this->definition($name));
            $repo->acquireLease($name, bin2hex(random_bytes(16)), $now->modify('+10 minutes'), $now);
        }
        $dispatcher = new FakeJobDispatcher();

        $report = $this->manager($dispatcher, 3)->tick([$this->definition('job-a'), $this->definition('job-b')], $now);

        self::assertSame(['job-a'], $report->dispatched);
        self::assertSame(['job-b'], $report->deferred);
    }

    public function test_an_expired_lease_is_reaped_as_a_failure_and_its_pid_is_signalled(): void
    {
        $repo = new CronJobRepository($this->conn);
        $repo->upsertDefinition($this->definition('job-a'));
        $slot = $this->at('2026-10-22 03:17:00');
        $repo->claim('job-a', $slot, str_repeat('a', 32), $slot->modify('+2 minutes'), $slot);
        $repo->attachPid('job-a', str_repeat('a', 32), 4242);

        $signalled = [];
        $report    = $this->manager(new FakeJobDispatcher(), 3, $signalled)->tick([], $this->at('2026-10-22 03:30:00'));

        self::assertSame(['job-a'], $report->reaped);
        self::assertSame([4242], $signalled);
        $state = $repo->find('job-a');
        self::assertNotNull($state);
        self::assertSame(-1, $state->lastExit);
        self::assertNull($state->leaseToken);
    }

    public function test_a_dispatch_that_cannot_start_is_recorded_as_a_failed_run(): void
    {
        $dispatcher = new FakeJobDispatcher();
        $dispatcher->throws['job-a'] = new \RuntimeException('proc_open failed');
        $manager    = $this->manager($dispatcher);

        $report = $manager->tick([$this->definition('job-a'), $this->definition('job-b')], $this->at('2026-10-22 03:17:20'));

        self::assertSame(['job-a'], $report->failedToStart);
        self::assertSame(['job-b'], $report->dispatched, 'one job failing to start must not block the others');
        $state = (new CronJobRepository($this->conn))->find('job-a');
        self::assertNotNull($state);
        self::assertSame(-1, $state->lastExit);
        self::assertNull($state->leaseToken);
    }

    public function test_a_no_catch_up_job_that_is_too_late_is_skipped_not_run(): void
    {
        $dispatcher = new FakeJobDispatcher();
        $manager    = $this->manager($dispatcher);

        $report = $manager->tick([$this->definition('demo-reset', CatchUpPolicy::None, '0 * * * *')], $this->at('2026-10-22 05:40:00'));

        self::assertSame([], $report->dispatched);
        self::assertSame([], $dispatcher->dispatched);
    }
}
