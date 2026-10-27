<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Doctrine\DBAL\Connection;
use Votepit\Cron\CatchUpPolicy;
use Votepit\Cron\CronJobDefinition;
use Votepit\Persistence\CronJobRepository;
use Votepit\Persistence\CronJobState;

/**
 * Claim / fence / lease scenarios shared by the SQLite test (portable schema)
 * and the MySQL test (InnoDB, real conditional UPDATE + affected-row count).
 * The using class provides connection(): a Doctrine connection to a database
 * that already has the cron_jobs table.
 */
trait CronClaimScenarios
{
    /** The connection under test (the using class returns its own). */
    abstract protected function connection(): Connection;

    private function repo(?Connection $conn = null): CronJobRepository
    {
        return new CronJobRepository($conn ?? $this->connection());
    }

    private function stateOf(CronJobRepository $repo, string $name = 'job-a'): CronJobState
    {
        $state = $repo->find($name);
        self::assertNotNull($state);

        return $state;
    }

    private function utc(string $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
    }

    private function registerJob(CatchUpPolicy $catchUp = CatchUpPolicy::Latest): void
    {
        $this->repo()->upsertDefinition(new CronJobDefinition('job-a', ['/usr/bin/php', '/app/job.php'], '17 3 * * *', $catchUp, 60, 'core'));
    }

    public function test_only_one_of_two_claimers_wins_the_same_slot(): void
    {
        $this->registerJob();
        $repo = $this->repo();
        $slot = $this->utc('2026-10-22 03:17:00');
        $now  = $this->utc('2026-10-22 03:17:05');

        $first  = $repo->claim('job-a', $slot, str_repeat('a', 32), $now->modify('+3 minutes'), $now);
        $second = $repo->claim('job-a', $slot, str_repeat('b', 32), $now->modify('+3 minutes'), $now);

        self::assertTrue($first);
        self::assertFalse($second);
    }

    public function test_a_slot_that_already_ran_cannot_be_claimed_again_after_its_lease_ended(): void
    {
        $this->registerJob();
        $repo = $this->repo();
        $slot = $this->utc('2026-10-22 03:17:00');
        $now  = $this->utc('2026-10-22 03:17:05');

        $repo->claim('job-a', $slot, str_repeat('a', 32), $now->modify('+3 minutes'), $now);
        $repo->finish('job-a', str_repeat('a', 32), 0, $now->modify('+10 seconds'));

        self::assertFalse($repo->claim('job-a', $slot, str_repeat('c', 32), $now->modify('+1 hour'), $now->modify('+1 hour')));
    }

    public function test_a_live_lease_blocks_a_claim_for_a_later_slot(): void
    {
        $this->registerJob();
        $repo = $this->repo();
        $now  = $this->utc('2026-10-22 03:17:05');

        $repo->claim('job-a', $this->utc('2026-10-22 03:17:00'), str_repeat('a', 32), $now->modify('+5 minutes'), $now);

        // The next slot is due while the previous run still holds its lease: no concurrent run.
        $later   = $this->utc('2026-10-22 03:18:10');
        $claimed = $repo->claim('job-a', $this->utc('2026-10-22 03:18:00'), str_repeat('b', 32), $later->modify('+5 minutes'), $later);

        self::assertFalse($claimed);
    }

    public function test_an_expired_lease_can_be_reclaimed_for_a_new_slot(): void
    {
        $this->registerJob();
        $repo = $this->repo();
        $now  = $this->utc('2026-10-22 03:17:05');

        $repo->claim('job-a', $this->utc('2026-10-22 03:17:00'), str_repeat('a', 32), $now->modify('+1 minute'), $now);

        $later   = $this->utc('2026-10-23 03:17:01');
        $claimed = $repo->claim('job-a', $this->utc('2026-10-23 03:17:00'), str_repeat('b', 32), $later->modify('+1 minute'), $later);

        self::assertTrue($claimed);
    }

    public function test_a_stale_holder_cannot_finish_after_its_lease_was_reclaimed(): void
    {
        $this->registerJob();
        $repo = $this->repo();
        $now  = $this->utc('2026-10-22 03:17:05');

        $repo->claim('job-a', $this->utc('2026-10-22 03:17:00'), str_repeat('a', 32), $now->modify('+1 minute'), $now);
        $later = $this->utc('2026-10-23 03:17:01');
        $repo->claim('job-a', $this->utc('2026-10-23 03:17:00'), str_repeat('b', 32), $later->modify('+1 minute'), $later);

        self::assertFalse($repo->finish('job-a', str_repeat('a', 32), 0, $later));
        self::assertTrue($repo->finish('job-a', str_repeat('b', 32), 0, $later));
    }

    public function test_a_finish_with_the_current_token_releases_the_lease(): void
    {
        $this->registerJob();
        $repo = $this->repo();
        $now  = $this->utc('2026-10-22 03:17:05');
        $tok  = str_repeat('a', 32);

        $repo->claim('job-a', $this->utc('2026-10-22 03:17:00'), $tok, $now->modify('+1 minute'), $now);

        self::assertTrue($repo->isLeaseHeldBy('job-a', $tok, $now));
        self::assertTrue($repo->finish('job-a', $tok, 0, $now));
        self::assertFalse($repo->isLeaseHeldBy('job-a', $tok, $now));
    }

    public function test_failures_count_up_and_a_success_resets_the_count(): void
    {
        $this->registerJob();
        $repo = $this->repo();
        $tok  = str_repeat('a', 32);

        foreach (['2026-10-22 03:17:00', '2026-10-23 03:17:00'] as $slot) {
            $at = $this->utc($slot);
            $repo->claim('job-a', $at, $tok, $at->modify('+1 minute'), $at);
            $repo->finish('job-a', $tok, 1, $at->modify('+5 seconds'));
        }
        self::assertSame(2, $this->stateOf($repo)->consecutiveFailures);
        self::assertSame(1, $this->stateOf($repo)->lastExit);

        $at = $this->utc('2026-10-24 03:17:00');
        $repo->claim('job-a', $at, str_repeat('c', 32), $at->modify('+1 minute'), $at);
        $repo->finish('job-a', str_repeat('c', 32), 0, $at->modify('+5 seconds'));

        self::assertSame(0, $this->stateOf($repo)->consecutiveFailures);
        self::assertSame(0, $this->stateOf($repo)->lastExit);
    }

    public function test_an_expired_lease_is_reaped_as_a_timeout_and_the_old_holder_is_fenced_out(): void
    {
        $this->registerJob();
        $repo = $this->repo();
        $now  = $this->utc('2026-10-22 03:17:05');
        $tok  = str_repeat('a', 32);

        $repo->claim('job-a', $this->utc('2026-10-22 03:17:00'), $tok, $now->modify('+1 minute'), $now);

        $later = $now->modify('+2 minutes');
        $expired = $repo->expiredLeases($later);
        self::assertCount(1, $expired);
        self::assertSame('job-a', $expired[0]->jobName);
        self::assertSame($tok, $expired[0]->leaseToken);

        self::assertTrue($repo->expireLease('job-a', $tok, $later));
        self::assertFalse($repo->expireLease('job-a', $tok, $later), 'a reaped lease cannot be reaped twice');
        self::assertFalse($repo->finish('job-a', $tok, 0, $later), 'the late holder must not record its result');

        $state = $this->stateOf($repo);
        self::assertSame(-1, $state->lastExit);
        self::assertSame(1, $state->consecutiveFailures);
        self::assertNull($state->leaseToken);
    }

    public function test_a_skipped_slot_is_never_claimed_afterwards(): void
    {
        $this->registerJob(CatchUpPolicy::None);
        $repo = $this->repo();
        $slot = $this->utc('2026-10-22 03:17:00');

        self::assertTrue($repo->skipSlot('job-a', $slot));
        self::assertFalse($repo->claim('job-a', $slot, str_repeat('a', 32), $slot->modify('+1 minute'), $slot));
        self::assertSame('2026-10-22 03:17:00', $this->stateOf($repo)->lastSlot?->format('Y-m-d H:i:s'));
    }

    public function test_redefining_a_job_keeps_its_lease_and_last_slot(): void
    {
        $this->registerJob();
        $repo = $this->repo();
        $now  = $this->utc('2026-10-22 03:17:05');
        $repo->claim('job-a', $this->utc('2026-10-22 03:17:00'), str_repeat('a', 32), $now->modify('+1 minute'), $now);

        $this->registerJob();

        $state = $this->stateOf($repo);
        self::assertSame(str_repeat('a', 32), $state->leaseToken);
        self::assertSame('2026-10-22 03:17:00', $state->lastSlot?->format('Y-m-d H:i:s'));
    }

    public function test_a_lock_on_another_job_can_be_taken_only_while_it_is_free(): void
    {
        $this->repo()->upsertDefinition(new CronJobDefinition('reset-demo', ['/usr/bin/php', '/app/job.php'], '17 3 * * *', CatchUpPolicy::Latest, 60, 'core'));
        $repo = $this->repo();
        $now  = $this->utc('2026-10-22 03:17:05');

        self::assertTrue($repo->acquireLease('reset-demo', str_repeat('a', 32), $now->modify('+5 minutes'), $now));
        self::assertFalse($repo->acquireLease('reset-demo', str_repeat('b', 32), $now->modify('+5 minutes'), $now));

        self::assertFalse($repo->releaseLease('reset-demo', str_repeat('b', 32)));
        self::assertTrue($repo->releaseLease('reset-demo', str_repeat('a', 32)));
        self::assertTrue($repo->acquireLease('reset-demo', str_repeat('b', 32), $now->modify('+5 minutes'), $now));
    }

    public function test_an_unknown_job_cannot_be_claimed(): void
    {
        $now = $this->utc('2026-10-22 03:17:05');

        self::assertFalse($this->repo()->claim('nope', $now, str_repeat('a', 32), $now->modify('+1 minute'), $now));
    }
}
