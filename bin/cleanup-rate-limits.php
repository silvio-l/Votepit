#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — cleanup CLI for expired rate_limits buckets (security review
 * finding: the table otherwise grows unbounded — one bucket per unique
 * IP/email/user combination and action ever seen, PRIMARY KEY on `bucket`
 * only prevents duplicates, not growth).
 *
 * Deletes, via RateLimiter::pruneExpired(), every bucket whose window (with
 * a 2x safety margin) has definitely expired — independent of the size of
 * the configured window, no hardcoded retention window needed.
 *
 * IMPORTANT — no cron/scheduler is part of this script itself (analogous to
 * bin/cleanup-expired-accounts.php). It is cron-callable (exit code 0 on
 * success, non-zero on failure), but must be registered as a scheduled task
 * by the operator.
 *
 * Usage:
 *   php bin/cleanup-rate-limits.php
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

$deleted = (new \Votepit\Security\RateLimiter($conn))->pruneExpired();

echo sprintf("Done — %d expired rate_limits bucket(s) deleted.\n", $deleted);
