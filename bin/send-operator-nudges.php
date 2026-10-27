#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — operator nudge e-mails (onboarding, quiet board). Re-engagement,
 * owner decisions 4/5/6/7; see Votepit\Nudge\OperatorNudgeService.
 *
 * Dispatched HOURLY by the cron manager (bin/run-due-crons.php,
 * CoreCronJobRegistry) at the lowest mail priority: each run sends at most a
 * small batch, and the dispatcher stops as soon as the shared MailVolumeMonitor
 * buckets reach its own ceilings (below the digest's), so magic-link and
 * digest mail always keep their headroom. Every mail goes to the owner's
 * confirmed notification_email only, through the same mailer chain as every
 * other mail (SMTP circuit breaker + MailVolumeMonitor).
 *
 * Records a heartbeat on every run (see bin/check-cron-heartbeats.php).
 *
 * Exit code: 0 also when the budget stopped the run early (expected); 1 on
 * configuration/DB errors or when every attempted send failed.
 *
 * Usage:
 *   php bin/send-operator-nudges.php
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
    // Same resolver wiring as the digest job: installation-wide SMTP, circuit
    // breaker and volume monitor in the chain.
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

    $nudges = new \Votepit\Persistence\OperatorNudgeRepository($conn);
    $service = new \Votepit\Nudge\OperatorNudgeService(
        $nudges,
        new \Votepit\Nudge\OperatorNudgeDispatcher(
            $nudges,
            $smtpResolver->buildMailer(null),
            $rateLimiter,
            new \Votepit\Security\OperatorNudgeUnsubscribeToken($config->appKey),
            $config,
        ),
        $config,
    );

    $result = $service->run(new DateTimeImmutable());
} catch (\Throwable $e) {
    $heartbeats->recordFailure('send-operator-nudges', $e->getMessage());
    $reporter->report($e);
    fwrite(STDERR, 'Votepit: operator nudge run failed (' . $e->getMessage() . ").\n");
    exit(1);
}

$summary = sprintf(
    '%d sent, %d failed%s',
    $result->sent,
    $result->failed,
    $result->budgetExhausted ? ', budget reached (rest follows next run)' : '',
);
echo "Done — {$summary}.\n";

if ($result->failed > 0) {
    $audit->log('operator_nudge.send_failed', ['failed' => $result->failed, 'sent' => $result->sent]);
}

if ($result->failed > 0 && $result->sent === 0) {
    $heartbeats->recordFailure('send-operator-nudges', $summary . ' — last error: ' . (string) $result->lastError);
    exit(1);
}

$heartbeats->recordSuccess('send-operator-nudges', $summary);
