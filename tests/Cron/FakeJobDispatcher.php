<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use Votepit\Cron\CronJobDefinition;
use Votepit\Cron\JobDispatcher;

final class FakeJobDispatcher implements JobDispatcher
{
    /** @var list<array{job: string, token: string}> */
    public array $dispatched = [];

    public int $awaited = 0;

    /** @var array<string, \Throwable> job name => exception to throw from dispatch() */
    public array $throws = [];

    public function dispatch(CronJobDefinition $job, string $token): void
    {
        if (isset($this->throws[$job->name])) {
            throw $this->throws[$job->name];
        }
        $this->dispatched[] = ['job' => $job->name, 'token' => $token];
    }

    public function awaitAll(): void
    {
        $this->awaited++;
    }
}
