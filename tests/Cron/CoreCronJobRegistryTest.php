<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use PHPUnit\Framework\TestCase;
use Votepit\Cron\CatchUpPolicy;
use Votepit\Cron\CoreCronJobRegistry;

final class CoreCronJobRegistryTest extends TestCase
{
    public function test_every_core_job_is_registered_with_its_utc_slot_and_script_path(): void
    {
        $byName = [];
        foreach (CoreCronJobRegistry::jobs('/var/www/votepit') as $job) {
            $byName[$job->name] = $job;
        }

        self::assertSame(
            [
                'cleanup-expired-accounts',
                'cleanup-rate-limits',
                'cleanup-old-abuse-reports',
                'cleanup-orphaned-notification-emails',
                'purge-moderated-content',
                'rotate-audit-log',
                'check-db-health',
                'send-weekly-digest',
                'send-participant-digest',
                'send-operator-nudges',
            ],
            array_keys($byName),
        );

        $expectedCron = [
            'cleanup-expired-accounts'             => '17 3 * * *',
            'cleanup-rate-limits'                  => '23 3 * * *',
            'cleanup-old-abuse-reports'            => '29 3 * * *',
            'cleanup-orphaned-notification-emails' => '37 3 * * *',
            'purge-moderated-content'              => '41 3 * * *',
            'rotate-audit-log'                     => '47 3 * * *',
            'check-db-health'                      => '5 * * * *',
            'send-weekly-digest'                   => '12 * * * *',
            'send-participant-digest'              => '17 * * * *',
            'send-operator-nudges'                 => '42 * * * *',
        ];

        foreach ($byName as $name => $job) {
            self::assertSame($expectedCron[$name], $job->cronExpr, "{$name} has an unexpected schedule");
            self::assertSame('core', $job->source);
            self::assertSame(\PHP_BINARY, $job->command[0]);
            self::assertSame("/var/www/votepit/bin/{$name}.php", $job->command[1]);
        }
    }

    public function test_cleanups_run_in_the_quiet_window_and_catch_up_while_hourly_checks_do_not(): void
    {
        $byName = [];
        foreach (CoreCronJobRegistry::jobs('/var/www/votepit') as $job) {
            $byName[$job->name] = $job;
        }

        self::assertSame(CatchUpPolicy::Latest, $byName['cleanup-expired-accounts']->catchUp);
        self::assertSame(CatchUpPolicy::None, $byName['check-db-health']->catchUp);
        self::assertSame(CatchUpPolicy::None, $byName['send-weekly-digest']->catchUp);
    }

    public function test_check_cron_heartbeats_is_deliberately_not_included(): void
    {
        foreach (CoreCronJobRegistry::jobs('/var/www/votepit') as $job) {
            self::assertNotSame('check-cron-heartbeats', $job->name);
        }
    }
}
