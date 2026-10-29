<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use PHPUnit\Framework\TestCase;
use Votepit\Cron\NativeProcessRunner;

/**
 * Exercises real subprocesses (via PHP_BINARY -r '...') rather than mocking
 * proc_open() — this class exists specifically to get subprocess isolation
 * right (output capture, exit codes, and killing a hung process on timeout),
 * so a test double would defeat the point.
 */
final class NativeProcessRunnerTest extends TestCase
{
    public function test_a_successful_command_reports_exit_code_zero(): void
    {
        $result = (new NativeProcessRunner())->run([\PHP_BINARY, '-r', 'exit(0);'], 5);

        self::assertSame(0, $result->exitCode);
        self::assertFalse($result->timedOut);
        self::assertTrue($result->isSuccessful());
    }

    public function test_a_failing_command_reports_its_nonzero_exit_code(): void
    {
        $result = (new NativeProcessRunner())->run([\PHP_BINARY, '-r', 'exit(7);'], 5);

        self::assertSame(7, $result->exitCode);
        self::assertFalse($result->isSuccessful());
    }

    public function test_stdout_and_stderr_are_both_captured(): void
    {
        $result = (new NativeProcessRunner())->run(
            [\PHP_BINARY, '-r', 'fwrite(STDOUT, "out-line"); fwrite(STDERR, "err-line");'],
            5,
        );

        self::assertStringContainsString('out-line', $result->output);
        self::assertStringContainsString('err-line', $result->output);
    }

    public function test_a_command_that_runs_past_its_timeout_is_killed_and_reported_as_timed_out(): void
    {
        $start  = microtime(true);
        $result = (new NativeProcessRunner())->run([\PHP_BINARY, '-r', 'sleep(30);'], 1);
        $elapsed = microtime(true) - $start;

        self::assertTrue($result->timedOut);
        self::assertFalse($result->isSuccessful());
        // Killed close to the 1-second budget, nowhere near the full 30s sleep.
        self::assertLessThan(10.0, $elapsed);
    }

    public function test_extra_env_vars_are_visible_to_the_subprocess_alongside_the_inherited_environment(): void
    {
        $result = (new NativeProcessRunner())->run(
            [\PHP_BINARY, '-r', 'fwrite(STDOUT, getenv("VOTEPIT_TEST_VAR") . "|" . (getenv("PATH") !== false ? "has-path" : "no-path"));'],
            5,
            ['VOTEPIT_TEST_VAR' => 'hello-from-manager'],
        );

        self::assertSame('hello-from-manager|has-path', trim($result->output));
    }

    public function test_a_command_that_cannot_be_started_reports_failure_without_throwing(): void
    {
        $result = (new NativeProcessRunner())->run(['/no/such/binary-votepit-test', '--flag'], 5);

        self::assertFalse($result->isSuccessful());
    }
}
