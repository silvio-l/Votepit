<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use Votepit\Cron\CatchUpPolicy;
use Votepit\Cron\CronJobDefinition;
use Votepit\Cron\CronWorker;
use Votepit\Cron\ProcessResult;
use Votepit\Persistence\CronJobRepository;
use Votepit\Tests\Support\IntegrationTestCase;

final class CronWorkerTest extends IntegrationTestCase
{
    private const TOKEN = 'lease-fixture-aaaaaaaaaaaaaaaaaa';

    /** @param non-empty-string $name */
    private function definition(string $name = 'job-a'): CronJobDefinition
    {
        return new CronJobDefinition($name, ['/usr/bin/php', '/app/job.php'], '17 3 * * *', CatchUpPolicy::Latest, 60, 'core', ['VOTEPIT_X' => '1']);
    }

    private function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    public function test_a_worker_holding_the_lease_runs_the_job_and_records_its_exit(): void
    {
        $repo   = new CronJobRepository($this->conn);
        $job    = $this->definition();
        $repo->upsertDefinition($job);
        $slot = $this->at('2026-10-22 03:17:00');
        $repo->claim('job-a', $slot, self::TOKEN, $slot->modify('+3 minutes'), $slot);

        $runner = new FakeProcessRunner();
        $runner->willReturn('/app/job.php', new ProcessResult(0, 'ok', false));

        $exit = (new CronWorker($repo, $runner))->run($job, self::TOKEN, $this->at('2026-10-22 03:17:05'));

        self::assertSame(0, $exit);
        self::assertSame(['/usr/bin/php', '/app/job.php'], $runner->calls[0]['command']);
        self::assertSame(60, $runner->calls[0]['timeoutSeconds']);
        self::assertSame(['VOTEPIT_X' => '1'], $runner->calls[0]['env']);
        $state = $repo->find('job-a');
        self::assertNotNull($state);
        self::assertSame(0, $state->lastExit);
        self::assertNull($state->leaseToken);
    }

    public function test_the_job_output_and_exit_code_reach_the_log(): void
    {
        $repo = new CronJobRepository($this->conn);
        $job  = $this->definition();
        $repo->upsertDefinition($job);
        $slot = $this->at('2026-10-22 03:17:00');
        $repo->claim('job-a', $slot, self::TOKEN, $slot->modify('+3 minutes'), $slot);

        $runner = new FakeProcessRunner();
        $runner->willReturn('/app/job.php', new ProcessResult(1, "Votepit: run failed (boom).\n", false));
        $logged = [];

        $exit = (new CronWorker($repo, $runner, static function (string $entry) use (&$logged): void {
            $logged[] = $entry;
        }))->run($job, self::TOKEN, $this->at('2026-10-22 03:17:05'));

        self::assertSame(1, $exit);
        self::assertSame(["[2026-10-22 03:17:05] job-a exit=1\nVotepit: run failed (boom).\n"], $logged);
    }

    public function test_a_worker_whose_lease_was_lost_does_not_run_the_job(): void
    {
        $repo = new CronJobRepository($this->conn);
        $job  = $this->definition();
        $repo->upsertDefinition($job);
        $slot = $this->at('2026-10-22 03:17:00');
        $repo->claim('job-a', $slot, str_repeat('f', 32), $slot->modify('+3 minutes'), $slot);

        $runner = new FakeProcessRunner();

        $exit = (new CronWorker($repo, $runner))->run($job, self::TOKEN, $this->at('2026-10-22 03:17:05'));

        self::assertSame(2, $exit);
        self::assertSame([], $runner->calls, 'a fenced-out worker must never execute the job');
    }

    public function test_a_runner_exception_is_recorded_as_a_failure_not_thrown(): void
    {
        $repo = new CronJobRepository($this->conn);
        $job  = $this->definition();
        $repo->upsertDefinition($job);
        $slot = $this->at('2026-10-22 03:17:00');
        $repo->claim('job-a', $slot, self::TOKEN, $slot->modify('+3 minutes'), $slot);

        $runner = new FakeProcessRunner();
        $runner->willThrow('/app/job.php', new \RuntimeException('proc_open failed'));

        $exit = (new CronWorker($repo, $runner))->run($job, self::TOKEN, $this->at('2026-10-22 03:17:05'));

        self::assertSame(1, $exit);
        $state = $repo->find('job-a');
        self::assertNotNull($state);
        self::assertSame(-1, $state->lastExit);
        self::assertSame(1, $state->consecutiveFailures);
    }
}
