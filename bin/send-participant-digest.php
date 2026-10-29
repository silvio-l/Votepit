#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — participant digest and opt-in new-ideas digest (close-the-loop
 * re-engagement C/F).
 *
 * Dispatched HOURLY by the cron manager (bin/run-due-crons.php,
 * CoreCronJobRegistry). Each run sends a small, volume-aware batch:
 *  - Votepit\Digest\ParticipantDigestService: queued status/comment/reply
 *    items, batched per recipient and cadence period;
 *  - Votepit\Digest\NewIdeasDigestService: weekly new-ideas mail for users who
 *    opted in (public boards they participated in only).
 * Both back off when the shared MailVolumeMonitor buckets leave no headroom
 * for magic-link mail, and defer rather than drop anything.
 *
 * Mail goes through the same mailer chain as every other mail
 * (SmtpConfigResolver::buildMailer(): SMTP circuit breaker + MailVolumeMonitor).
 * Records a heartbeat on every run (see bin/check-cron-heartbeats.php).
 *
 * Exit code: 0 also when the budget stopped the run early (expected); 1 on
 * configuration/DB errors or when every attempted send failed.
 *
 * Usage:
 *   php bin/send-participant-digest.php
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

$heartbeats  = new \Votepit\Persistence\CronHeartbeatRepository($conn);
$audit       = new \Votepit\Logging\AuditLogger(dirname(__DIR__) . '/logs/audit.log');
$reporter    = $config->sentryDsn !== ''
    ? new \Votepit\Monitoring\SentryErrorReporter($config->sentryDsn, $config->env)
    : new \Votepit\Monitoring\NullErrorReporter();
$rateLimiter = new \Votepit\Security\RateLimiter($conn);
$jobName     = 'send-participant-digest';

try {
    // Same resolver wiring as AppFactory (installation-wide SMTP, no board
    // context: the digests span boards).
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
    $mailer = $smtpResolver->buildMailer(null);

    $accounts   = new \Votepit\Persistence\AccountRepository($conn);
    $planPolicy = \Votepit\Extension\ExtensionLoader::planPolicy(\Votepit\Extension\ExtensionLoader::fromConfig($config)) ?? new \Votepit\Domain\UnrestrictedPlanPolicy();
    $branding   = \Votepit\Mail\BoardMailBranding::forConnection($conn, $accounts, $planPolicy);
    $router     = \Votepit\Participant\ParticipantMailRouter::forConfig($conn, $config);
    $now        = new DateTimeImmutable();

    $participant = new \Votepit\Digest\ParticipantDigestService(
        new \Votepit\Persistence\ParticipantMailRepository($conn),
        $branding,
        $mailer,
        $rateLimiter,
        new \Votepit\Security\EncryptionService($config->appKey, 'anon_vote_email'),
        $router,
        $config,
    );
    $newIdeas = new \Votepit\Digest\NewIdeasDigestService(
        new \Votepit\Persistence\ParticipantMailRepository($conn),
        new \Votepit\Persistence\IdeaFollowRepository($conn),
        new \Votepit\Persistence\IdeaRepository($conn),
        new \Votepit\Persistence\BoardRepository($conn),
        $accounts,
        $branding,
        $mailer,
        $rateLimiter,
        $router,
        $config,
    );

    $first  = $participant->run($now);
    $second = $newIdeas->run($now);
} catch (\Throwable $e) {
    $heartbeats->recordFailure($jobName, $e->getMessage());
    $reporter->report($e);
    fwrite(STDERR, 'Votepit: participant digest run failed (' . $e->getMessage() . ").\n");
    exit(1);
}

$sent    = $first->sent + $second->sent;
$failed  = $first->failed + $second->failed;
$summary = sprintf(
    '%d sent (%d participant, %d new-ideas), %d failed%s',
    $sent,
    $first->sent,
    $second->sent,
    $failed,
    ($first->budgetExhausted || $second->budgetExhausted) ? ', budget reached (rest follows next run)' : '',
);
echo "Done — {$summary}.\n";

$lastError = $first->lastError ?? $second->lastError;

if ($failed > 0) {
    $audit->log('digest.send_failed', ['failed' => $failed, 'sent' => $sent]);
}

if ($failed > 0 && $sent === 0) {
    $heartbeats->recordFailure($jobName, $summary . ' — last error: ' . (string) $lastError);
    exit(1);
}

$heartbeats->recordSuccess($jobName, $summary);
