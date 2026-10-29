<?php

declare(strict_types=1);

namespace Votepit\Cron;

/**
 * Production spawner. proc_open() with file descriptors only (no pipes), so
 * the child never blocks on a full pipe after the parent has gone and never
 * needs the parent to drain it. Logs are size-capped: a log above
 * LOG_ROTATE_BYTES is moved to "<log>.1" (one generation) before the child
 * starts.
 */
final class ProcOpenChildProcessSpawner implements ChildProcessSpawner
{
    public const LOG_ROTATE_BYTES = 1_000_000;

    public function spawn(array $argv, string $logPath): ChildProcess
    {
        $logDir = dirname($logPath);
        if (!is_dir($logDir) && !@mkdir($logDir, 0o775, true) && !is_dir($logDir)) {
            throw new \RuntimeException("cannot create cron log directory {$logDir}");
        }

        if (is_file($logPath) && (int) filesize($logPath) > self::LOG_ROTATE_BYTES) {
            @rename($logPath, $logPath . '.1');
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logPath, 'a'],
            2 => ['file', $logPath, 'a'],
        ];

        $process = @proc_open($argv, $descriptors, $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('proc_open() failed to start the cron worker.');
        }

        return new ProcOpenChildProcess($process);
    }
}
