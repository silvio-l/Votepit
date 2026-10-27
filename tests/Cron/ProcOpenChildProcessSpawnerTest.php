<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use PHPUnit\Framework\TestCase;
use Votepit\Cron\ProcOpenChildProcessSpawner;

final class ProcOpenChildProcessSpawnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/votepit-spawn-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir . '/*');
        foreach (is_array($files) ? $files : [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_child_runs_detached_from_pipes_and_its_output_lands_in_the_log(): void
    {
        $log     = $this->dir . '/job-a.log';
        $child   = (new ProcOpenChildProcessSpawner())->spawn(
            [PHP_BINARY, '-r', 'echo "hello from child\n"; exit(3);'],
            $log,
        );

        $deadline = microtime(true) + 10;
        while ($child->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }

        self::assertFalse($child->isRunning());
        self::assertSame(3, $child->exitCode());
        self::assertIsInt($child->pid());
        self::assertStringContainsString('hello from child', (string) file_get_contents($log));
    }

    public function test_a_log_above_the_size_cap_is_rotated_before_the_child_starts(): void
    {
        mkdir($this->dir, 0o775, true);
        $log = $this->dir . '/job-a.log';
        file_put_contents($log, str_repeat('x', 1_100_000));

        $child = (new ProcOpenChildProcessSpawner())->spawn([PHP_BINARY, '-r', 'exit(0);'], $log);
        $deadline = microtime(true) + 10;
        while ($child->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }

        self::assertFileExists($log . '.1');
        self::assertLessThan(1_000, (int) filesize($log));
    }
}
