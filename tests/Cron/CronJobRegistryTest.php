<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use PHPUnit\Framework\TestCase;
use Votepit\Config;
use Votepit\ConfigException;
use Votepit\Cron\CatchUpPolicy;
use Votepit\Cron\CoreCronJobRegistry;
use Votepit\Cron\CronJobDefinition;
use Votepit\Cron\CronJobRegistry;
use Votepit\Extension\ExtensionLoader;
use Votepit\Tests\Support\StubExtension;

final class CronJobRegistryTest extends TestCase
{
    public function test_core_jobs_come_first_then_the_jobs_of_configured_extensions(): void
    {
        $config = $this->config([StubExtension::class => ['cron_jobs' => [$this->job('demo-reset')]]]);

        $names = $this->names(CronJobRegistry::forConfig($config, '/app'));

        self::assertSame($this->names(CoreCronJobRegistry::jobs('/app')), array_slice($names, 0, -1));
        self::assertSame('demo-reset', end($names));
    }

    public function test_an_extension_without_cron_jobs_adds_nothing(): void
    {
        $config = $this->config([StubExtension::class => []]);

        self::assertSame($this->names(CoreCronJobRegistry::jobs('/app')), $this->names(CronJobRegistry::forConfig($config, '/app')));
    }

    public function test_a_job_name_already_taken_by_core_is_rejected(): void
    {
        $config = $this->config([StubExtension::class => ['cron_jobs' => [$this->job('check-db-health')]]]);

        $this->expectException(ConfigException::class);
        CronJobRegistry::forConfig($config, '/app');
    }

    public function test_extension_jobs_are_collected_in_extension_order(): void
    {
        $extensions = [
            StubExtension::fromOptions(['cron_jobs' => [$this->job('first-job')]]),
            StubExtension::fromOptions(['cron_jobs' => [$this->job('second-job'), $this->job('third-job')]]),
        ];

        self::assertSame(
            ['first-job', 'second-job', 'third-job'],
            $this->names(ExtensionLoader::cronJobs($extensions, '/app')),
        );
    }

    /** @param array<class-string, array<string, mixed>> $extensions */
    private function config(array $extensions): Config
    {
        return Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => str_repeat('b', 64),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'extensions'          => $extensions,
        ]);
    }

    /** @param non-empty-string $name */
    private function job(string $name): CronJobDefinition
    {
        return new CronJobDefinition($name, ['/bin/true'], '* * * * *', CatchUpPolicy::None, 60, 'test');
    }

    /**
     * @param list<CronJobDefinition> $jobs
     * @return list<string>
     */
    private function names(array $jobs): array
    {
        return array_map(static fn (CronJobDefinition $job): string => $job->name, $jobs);
    }
}
