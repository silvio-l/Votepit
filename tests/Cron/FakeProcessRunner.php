<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use Votepit\Cron\ProcessResult;
use Votepit\Cron\ProcessRunner;

/** Test double: records every launch instead of spawning a real subprocess. */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<array{command: list<string>, timeoutSeconds: int, env: array<string, string>}> */
    public array $calls = [];

    /** @var array<string, ProcessResult> canned result, keyed by the job's script path (last argv element) */
    private array $results = [];

    /** @var array<string, \Throwable> exception to throw instead, same key */
    private array $throws = [];

    public function willReturn(string $scriptPath, ProcessResult $result): void
    {
        $this->results[$scriptPath] = $result;
    }

    public function willThrow(string $scriptPath, \Throwable $e): void
    {
        $this->throws[$scriptPath] = $e;
    }

    public function run(array $command, int $timeoutSeconds, array $env = []): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'timeoutSeconds' => $timeoutSeconds, 'env' => $env];

        $key = $command[count($command) - 1];
        if (isset($this->throws[$key])) {
            throw $this->throws[$key];
        }

        return $this->results[$key] ?? new ProcessResult(0, '', false);
    }
}
