<?php

declare(strict_types=1);

namespace Votepit\Cron;

use Doctrine\DBAL\Connection;
use Votepit\Config;
use Votepit\ConfigException;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\Mailer;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Monitoring\NullErrorReporter;
use Votepit\Monitoring\SentryErrorReporter;
use Votepit\Persistence\BoardSmtpSettingsRepository;
use Votepit\Persistence\CronHeartbeatRepository;
use Votepit\Persistence\CronJobRepository;
use Votepit\Persistence\SmtpSettingsRepository;
use Votepit\Resilience\CircuitBreaker;
use Votepit\Security\EncryptionService;
use Votepit\Security\RateLimiter;
use Votepit\Security\SmtpHostPolicy;

/**
 * Entry logic shared by bin/votepit-cron.php and the edition shims.
 *
 * - No flags: one manager tick (what the single hosting-panel task runs every minute).
 * - `--run-job=<name> --token=<token>`: worker mode, spawned by the tick.
 *
 * Heartbeats: `cron-manager` is written at the START of every tick (the
 * /ready probe and the watchdog read it) and recorded as a failure when the
 * manager itself errors. `cron-manager-pass` is written at the END of a tick
 * that completed, with its summary. A failing job is not a manager failure:
 * its own state and alert mails carry that (CronAlerter).
 */
final class CronCli
{
    /** Inline mode's time budget, so the tick ends before the next minute. */
    private const INLINE_BUDGET_SECONDS = 50.0;

    private const CONCURRENCY_CAP = 3;

    /**
     * @param list<string>                  $argv
     * @param array<mixed>                  $rawConfig the config array as read from config.php (cron_dispatch, admin_emails)
     * @param \Closure(): list<CronJobDefinition> $registry builds this edition's job list
     */
    public static function run(
        array $argv,
        string $coreRoot,
        string $entryScript,
        Config $config,
        array $rawConfig,
        Connection $conn,
        \Closure $registry,
    ): int {
        $jobs      = new CronJobRepository($conn);
        $heartbeat = new CronHeartbeatRepository($conn);
        $worker    = self::workerArgs($argv);

        if ($worker !== null) {
            return self::runWorker($worker['job'], $worker['token'], $registry, $jobs);
        }

        try {
            $mode = DispatchMode::fromRaw($rawConfig['cron_dispatch'] ?? null);
        } catch (ConfigException $e) {
            fwrite(STDERR, 'Votepit: ' . $e->getMessage() . "\n");

            return 1;
        }

        $logDir = $coreRoot . '/logs/cron';
        $command = new WorkerCommand(PHP_BINARY, $entryScript);
        $spawner = new ProcOpenChildProcessSpawner();
        $dispatcher = match ($mode) {
            DispatchMode::Inline => new InlineJobDispatcher(
                $jobs,
                $spawner,
                $command,
                $logDir,
                microtime(true) + self::INLINE_BUDGET_SECONDS,
                static function (int $microseconds): void {
                    usleep($microseconds);
                },
            ),
            DispatchMode::Detached => new DetachedJobDispatcher($jobs, $spawner, $command, $logDir),
        };

        $alerter = self::alerter($config, $conn, $jobs, $rawConfig, $coreRoot);

        try {
            self::recordHeartbeat($heartbeat, 'cron-manager', true, 'tick started');
            $report = (new CronManager($jobs, $dispatcher, self::CONCURRENCY_CAP, 120, null, $alerter))->tick($registry(), new \DateTimeImmutable());
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Votepit cron manager failed: ' . $e->getMessage() . "\n");
            self::recordHeartbeat($heartbeat, 'cron-manager', false, $e->getMessage());

            return 1;
        }

        $summary = sprintf(
            'dispatched %d, deferred %d, reaped %d, failed to start %d (mode %s)',
            count($report->dispatched),
            count($report->deferred),
            count($report->reaped),
            count($report->failedToStart),
            $mode->value,
        );
        echo 'Done — ' . $summary . "\n";
        self::recordHeartbeat($heartbeat, 'cron-manager-pass', true, $summary);

        return 0;
    }

    /**
     * @param list<string> $argv
     * @return array{job: string, token: string}|null
     */
    private static function workerArgs(array $argv): ?array
    {
        $job   = null;
        $token = null;
        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--run-job=')) {
                $job = substr($arg, strlen('--run-job='));
            } elseif (str_starts_with($arg, '--token=')) {
                $token = substr($arg, strlen('--token='));
            }
        }

        return $job !== null && $token !== null ? ['job' => $job, 'token' => $token] : null;
    }

    private static function runWorker(string $jobName, string $token, \Closure $registry, CronJobRepository $jobs): int
    {
        foreach ($registry() as $definition) {
            if ($definition->name === $jobName) {
                $worker = new CronWorker($jobs, new NativeProcessRunner(), static function (string $entry): void {
                    fwrite(STDOUT, $entry);
                });

                return $worker->run($definition, $token, new \DateTimeImmutable());
            }
        }

        fwrite(STDERR, "Votepit cron: unknown job \"{$jobName}\".\n");

        return CronWorker::EXIT_LEASE_LOST;
    }

    /**
     * Builds the alert path with the same SMTP wiring as the edition's other
     * mail senders. The mailer is built lazily by CronAlerter, so a broken SMTP
     * setup fails the alert, never the tick.
     *
     * @param array<mixed> $rawConfig
     */
    private static function alerter(Config $config, Connection $conn, CronJobRepository $jobs, array $rawConfig, string $coreRoot): CronAlerter
    {
        $reporter    = $config->sentryDsn !== ''
            ? new SentryErrorReporter($config->sentryDsn, $config->env)
            : new NullErrorReporter();
        $audit       = new AuditLogger($coreRoot . '/logs/audit.log');
        $rateLimiter = new RateLimiter($conn);
        $smtp        = new SmtpConfigResolver(
            new SmtpSettingsRepository($conn),
            new BoardSmtpSettingsRepository($conn),
            new EncryptionService($config->appKey),
            $config->smtp,
            new SmtpHostPolicy($config->routingMode === 'cloud'),
            $rateLimiter,
            $reporter,
            $audit,
            new CircuitBreaker($conn),
        );

        return new CronAlerter(
            $jobs,
            static fn (): Mailer => $smtp->buildMailer(null),
            self::adminRecipients($rawConfig),
            $reporter,
            $audit,
            static function (string $line): void {
                fwrite(STDERR, $line . "\n");
            },
        );
    }

    /**
     * The installation's admin_emails in plaintext. Config keeps only their
     * HMACs (ADR 0002), so the raw array is the one place they are available.
     *
     * @param array<mixed> $rawConfig
     * @return list<string>
     */
    private static function adminRecipients(array $rawConfig): array
    {
        $emails = array_map(static fn (mixed $e): string => trim((string) $e), (array) ($rawConfig['admin_emails'] ?? []));

        return array_values(array_filter($emails, static fn (string $e): bool => $e !== ''));
    }

    private static function recordHeartbeat(CronHeartbeatRepository $heartbeat, string $job, bool $ok, string $detail): void
    {
        try {
            $ok ? $heartbeat->recordSuccess($job, $detail) : $heartbeat->recordFailure($job, $detail);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Votepit cron: heartbeat not recorded (' . $e->getMessage() . ").\n");
        }
    }
}
