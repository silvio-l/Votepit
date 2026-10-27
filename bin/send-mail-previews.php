#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — sends ONE sample of every transactional mail kind to a recipient,
 * so the owner can review the mail design in a real mail client.
 *
 * Content is fake (board "Demo Board", account "demo", dummy tokens that
 * authenticate nothing); rendering and sending use the production code
 * (Votepit\Mail\MailPreviews, SmtpConfigResolver::buildMailer() with the
 * installation's configured SMTP). Subjects are prefixed with "[Preview] ".
 *
 * Refuses to run unless the config is dev or points at a staging/test host
 * (see Votepit\Mail\MailPreviews::assertAllowed()).
 *
 * Usage:
 *   php bin/send-mail-previews.php <recipient> [--only=kind,kind] [--brand]
 *
 *   --only=kind,kind  send only these kinds (default: all)
 *   --brand           additionally send the board-branded variants of the board mails
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Votepit\Mail\MailPreviews;
use Votepit\Persistence\ConnectionFactory;

$usage = "Usage: php bin/send-mail-previews.php <recipient> [--only=kind,kind] [--brand]\n"
    . 'Kinds: ' . implode(', ', MailPreviews::kinds()) . "\n";

$recipient = '';
$only      = [];
$brand     = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--brand') {
        $brand = true;
    } elseif (str_starts_with($arg, '--only=')) {
        $only = array_values(array_filter(explode(',', substr($arg, strlen('--only='))), static fn (string $kind): bool => $kind !== ''));
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}\n" . $usage);
        exit(2);
    } elseif ($recipient === '') {
        $recipient = $arg;
    } else {
        fwrite(STDERR, $usage);
        exit(2);
    }
}

if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, $usage);
    exit(2);
}

$configPath = dirname(__DIR__) . '/config/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "Votepit is not configured yet. Copy config/config.example.php to config/config.php and fill it in.\n");
    exit(1);
}

try {
    $config = \Votepit\Config::fromArray(require $configPath);
    MailPreviews::assertAllowed($config->env, $config->appUrl);
    $conn = ConnectionFactory::create($config);
} catch (\Votepit\ConfigException $e) {
    fwrite(STDERR, 'Votepit: invalid configuration (' . $e->getMessage() . ").\n");
    exit(1);
} catch (\DomainException $e) {
    fwrite(STDERR, 'Votepit: ' . $e->getMessage() . "\n");
    exit(1);
}

$reporter     = $config->sentryDsn !== ''
    ? new \Votepit\Monitoring\SentryErrorReporter($config->sentryDsn, $config->env)
    : new \Votepit\Monitoring\NullErrorReporter();
$audit        = new \Votepit\Logging\AuditLogger(dirname(__DIR__) . '/logs/audit.log');
$rateLimiter  = new \Votepit\Security\RateLimiter($conn);

// Same resolver wiring as the weekly digest: installation-wide SMTP (no board
// context), SMTP circuit breaker and the shared mail volume monitor.
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

try {
    $result = (new MailPreviews())->send($smtpResolver->buildMailer(null), $recipient, $config->appUrl, $only, $brand);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, 'Votepit: ' . $e->getMessage() . "\n" . $usage);
    exit(2);
}

printf("Sent %d preview mail(s) to %s:\n", count($result['sent']), $recipient);
foreach ($result['sent'] as $label) {
    echo "  ✓ {$label}\n";
}
foreach ($result['failed'] as $label => $error) {
    fwrite(STDERR, "  ✗ {$label}: {$error}\n");
}

exit($result['failed'] === [] ? 0 : 1);
