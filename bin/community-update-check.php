#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit Community — the daily update check and version report (self-host only).
 *
 * Dispatched once a day by the cron manager (CronJobRegistry, self-host only).
 *   1. update check (config `update_check`, default true): asks GitHub for the
 *      latest stable release and remembers it. A newer version shows the operator
 *      banner and sends one e-mail per version to the owner (operator nudge, once).
 *   2. version report (config `version_report`, default true): one anonymous
 *      Matomo event set per day, see Votepit\Update\VersionReportService.
 *
 * Both off: nothing leaves the installation. Failures of the network calls are
 * silent by design; only configuration and database errors mark the run failed.
 *
 * Usage:
 *   php bin/community-update-check.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$root       = dirname(__DIR__);
$configPath = $root . '/config/config.php';

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

// Defence in depth: the cron registry already leaves this job out of Cloud and the demo.
try {
    $communityInstall = \Votepit\Update\CommunityEdition::runsUpdateChannel($config);
} catch (\Votepit\ConfigException $e) {
    fwrite(STDERR, 'Votepit: invalid configuration (' . $e->getMessage() . ").\n");
    exit(1);
}
if (!$communityInstall) {
    echo "Skipped: the update check and version report run on Community installations only.\n";
    exit(0);
}

$heartbeats = new \Votepit\Persistence\CronHeartbeatRepository($conn);
$state      = new \Votepit\Update\CommunityStateStore($conn);
$installed  = \Votepit\Update\InstalledVersion::fromRoot($root);
$userAgent  = 'Votepit-Community/' . $installed;
$http       = new \Votepit\Update\CurlReleaseHttp($userAgent);
$now        = new DateTimeImmutable('now', new DateTimeZone('UTC'));

try {
    if ($config->updateCheckEnabled) {
        $releases = new \Votepit\Update\CommunityReleaseClient($http, $userAgent);
        $release  = (new \Votepit\Update\UpdateCheckService($releases, $state))->run($now);

        if ($release !== null && \Votepit\Update\SemanticVersion::isNewer($release->version, $installed)) {
            $rateLimiter = new \Votepit\Security\RateLimiter($conn);
            $audit       = new \Votepit\Logging\AuditLogger($root . '/logs/audit.log');
            $smtpResolver = new \Votepit\Mail\SmtpConfigResolver(
                new \Votepit\Persistence\SmtpSettingsRepository($conn),
                new \Votepit\Persistence\BoardSmtpSettingsRepository($conn),
                new \Votepit\Security\EncryptionService($config->appKey),
                $config->smtp,
                new \Votepit\Security\SmtpHostPolicy(false),
                $rateLimiter,
                new \Votepit\Monitoring\NullErrorReporter(),
                $audit,
                new \Votepit\Resilience\CircuitBreaker($conn),
            );

            $nudges  = new \Votepit\Persistence\OperatorNudgeRepository($conn);
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

            $result = $service->updateAvailable($now, $installed, $release->version);
            echo "Update notice: {$result->sent} sent, {$result->failed} failed.\n";
        }
    }

    if ($config->versionReportEnabled) {
        (new \Votepit\Update\VersionReportService($state, $http, $installed))
            ->run($now, PHP_VERSION, \Votepit\Update\DatabaseVersion::describe($conn));
    }
} catch (\Throwable $e) {
    $heartbeats->recordFailure('community-update-check', $e->getMessage());
    fwrite(STDERR, 'Votepit: community update check failed (' . $e->getMessage() . ").\n");
    exit(1);
}

$heartbeats->recordSuccess('community-update-check', 'checked');
echo "Done.\n";
exit(0);
