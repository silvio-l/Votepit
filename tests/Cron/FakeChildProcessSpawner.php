<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use Votepit\Cron\ChildProcess;
use Votepit\Cron\ChildProcessSpawner;

/** Records spawns and hands out scripted children; no real process is started. */
final class FakeChildProcessSpawner implements ChildProcessSpawner
{
    /** @var list<array{argv: list<string>, logPath: string}> */
    public array $spawns = [];

    /** @var list<FakeChildProcess> */
    public array $children = [];

    public ?\Throwable $failWith = null;

    public function spawn(array $argv, string $logPath): ChildProcess
    {
        if ($this->failWith instanceof \Throwable) {
            throw $this->failWith;
        }

        $this->spawns[] = ['argv' => $argv, 'logPath' => $logPath];
        $child          = new FakeChildProcess(1000 + count($this->children));
        $this->children[] = $child;

        return $child;
    }
}
