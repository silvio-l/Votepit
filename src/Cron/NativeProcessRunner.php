<?php

declare(strict_types=1);

namespace Votepit\Cron;

/**
 * Production ProcessRunner: launches the given argv directly via proc_open()
 * — never through a shell, so no argument ever needs escaping and no
 * command string is ever built by concatenation (OWASP A03 command
 * injection). Polls for completion, capping captured output, and kills
 * (SIGTERM, then SIGKILL if it doesn't exit promptly) any subprocess still
 * running once its timeout budget is spent — this is what stops one hung
 * cron job from blocking the manager, and therefore every other due job,
 * indefinitely.
 */
final class NativeProcessRunner implements ProcessRunner
{
    private const POLL_MICROSECONDS          = 50_000;
    private const SIGTERM_GRACE_MICROSECONDS = 500_000;
    private const MAX_OUTPUT_BYTES           = 32_768;

    public function run(array $command, int $timeoutSeconds, array $env = []): ProcessResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // proc_open()'s $env_vars, once given at all, REPLACES the child's
        // environment rather than extending it — so start from the manager's
        // own environment and only layer the per-job overrides on top,
        // otherwise a job needing e.g. VOTEPIT_CORE_ROOT would lose PATH and
        // everything else it inherits today.
        $fullEnv = $env === [] ? null : array_merge(getenv(), $env);

        $process = @proc_open($command, $descriptors, $pipes, null, $fullEnv);
        if (!\is_resource($process)) {
            return new ProcessResult(-1, 'proc_open() failed to start the subprocess.', false);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output   = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;
        // proc_get_status()'s `exitcode` is only valid on the call where
        // `running` first flips to false — proc_close()'s return value is
        // unreliable for fast-exiting children (observed -1 in CI), so it
        // must be captured here rather than read from proc_close() below.
        $exitCode = -1;

        while (true) {
            $output .= $this->drain($pipes[1]) . $this->drain($pipes[2]);

            $status = proc_get_status($process);
            if ($status['running'] === false) {
                $exitCode = $status['exitcode'];
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, 15); // SIGTERM
                usleep(self::SIGTERM_GRACE_MICROSECONDS);
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9); // SIGKILL
                }
                break;
            }

            usleep(self::POLL_MICROSECONDS);
        }

        $output .= $this->drain($pipes[1]) . $this->drain($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (\strlen($output) > self::MAX_OUTPUT_BYTES) {
            $output = substr($output, 0, self::MAX_OUTPUT_BYTES) . "\n… (output truncated)\n";
        }

        return new ProcessResult($timedOut ? -1 : $exitCode, $output, $timedOut);
    }

    /** @param resource $pipe */
    private function drain($pipe): string
    {
        $chunk = stream_get_contents($pipe);
        return $chunk !== false ? $chunk : '';
    }
}
