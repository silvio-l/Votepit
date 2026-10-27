#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — weekly owner digest e-mail (competitor-parity issue 14).
 *
 * Dispatched HOURLY by the cron manager (bin/run-due-crons.php,
 * CoreCronJobRegistry) — the digest itself is weekly per user; every run
 * only sends a small, volume-aware batch (Votepit\Digest\WeeklyDigestService:
 * per-run cap + backs off when the shared MailVolumeMonitor buckets leave no
 * headroom for magic-link mail), the rest follows on later runs.
 *
 * Mail goes only to a user's confirmed notification_email, through the same
 * mailer chain as every other mail (SmtpConfigResolver::buildMailer(): SMTP
 * circuit breaker + MailVolumeMonitor). Records a heartbeat via
 * CronHeartbeatRepository on every run (see bin/check-cron-heartbeats.php).
 *
 * Exit code: 0 also when the budget stopped the run early (expected, not a
 * failure); 1 on configuration/DB errors or when every attempted send failed.
 *
 * Usage:
 *   php bin/send-weekly-digest.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$configPath = dirname(__DIR__) . '/config/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "Votepit is not configured yet. Copy config/config.example.php to config/config.php and fill it in.\n");
    exit(1);
}

try {
    $config = \Votepit\Config::fromArray(require $configPath);
    $conn   = \Votepit\Persistence\ConnectionFactory::create($config);
} catch (\Votepit\ConfigException $e) {
    fwrite(STDERR, 'Votepit: invalid configuration (' . $e->getMessage() . ").\n");
    exit(1);
}

$heartbeats = new \Votepit\Persistence\CronHeartbeatRepository($conn);
$audit      = new \Votepit\Logging\AuditLogger(dirname(__DIR__) . '/logs/audit.log');
$reporter   = $config->sentryDsn !== ''
    ? new \Votepit\Monitoring\SentryErrorReporter($config->sentryDsn, $config->env)
    : new \Votepit\Monitoring\NullErrorReporter();
$rateLimiter = new \Votepit\Security\RateLimiter($conn);

try {
    // Same resolver wiring as AppFactory: installation-wide SMTP (no board
    // context — the digest spans boards), circuit breaker + volume monitor.
    $smtpResolver = new \Votepit\Mail\SmtpConfigResolver(
        new \Votepit\Persistence\SmtpSettingsRepository($conn),
        new \Votepit\Persistence\BoardSmtpSettingsRepository($conn),
        new \Votepit\Security\EncryptionService($config->appKey),
        $config->smtp,
        new \Votepit\Security\SmtpHostPolicy($config->routingMode === 'cloud'),
        $rateLimiter,
        $reporter,
        $audit,
        new \Votepit\Resilience\CircuitBreaker($conn),
    );

    $service = new \Votepit\Digest\WeeklyDigestService(
        new \Votepit\Persistence\UserRepository($conn),
        new \Votepit\Persistence\AccountMemberRepository($conn),
        new \Votepit\Persistence\BoardRepository($conn),
        new \Votepit\Persistence\IdeaRepository($conn),
        $smtpResolver->buildMailer(null),
        $rateLimiter,
        new \Votepit\Security\DigestUnsubscribeToken($config->appKey),
        $config,
    );

    $result = $service->run(new DateTimeImmutable());
} catch (\Throwable $e) {
    $heartbeats->recordFailure('send-weekly-digest', $e->getMessage());
    $reporter->report($e);
    fwrite(STDERR, 'Votepit: weekly digest run failed (' . $e->getMessage() . ").\n");
    exit(1);
}

$summary = sprintf(
    '%d sent, %d empty week(s) skipped, %d failed%s',
    $result->sent,
    $result->skippedEmpty,
    $result->failed,
    $result->budgetExhausted ? ', budget reached (rest follows next run)' : '',
);
echo "Done — {$summary}.\n";

if ($result->failed > 0) {
    $audit->log('digest.send_failed', ['failed' => $result->failed, 'sent' => $result->sent]);
}

if ($result->failed > 0 && $result->sent === 0) {
    $heartbeats->recordFailure('send-weekly-digest', $summary . ' — last error: ' . (string) $result->lastError);
    exit(1);
}

$heartbeats->recordSuccess('send-weekly-digest', $summary);
