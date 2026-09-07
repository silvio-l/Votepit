<?php

declare(strict_types=1);

namespace Votepit\Mail;

use Votepit\Logging\AuditLogger;
use Votepit\Monitoring\ErrorReporter;
use Votepit\Security\RateLimiter;

/**
 * Mailer decorator tracking outbound mail volume against a documented
 * shared-hosting account-wide send-limit estimate (~100 recipients/hour,
 * 500/day — a typical shared-hosting provider limit, shared across every
 * other project on the account).
 *
 * Never blocks a send — the magic-link critical path must not start
 * failing because of this monitor itself (fail-open by design, unlike
 * RateLimiter's request-facing buckets, which fail closed). Uses
 * RateLimiter purely as a counting primitive (RateLimiter::count(), the
 * same fixed-window `rate_limits` table every other rate-limited action
 * already uses — no new infrastructure) against two dedicated buckets
 * ("mail:volume:hourly"/"mail:volume:daily"), distinct from any
 * per-action/per-IP bucket.
 *
 * Alert threshold is set BELOW the documented cap (80% for both windows)
 * so an operator has headroom to react (switch SMTP relay, see ADR 0001
 * §8's documented trigger) before mail actually starts bouncing. Fires
 * through ErrorReporter (Sentry in prod, NullErrorReporter in self-host —
 * same seam every other background/cron alert in this codebase uses) at
 * most once per window: a second, limit=1 RateLimiter bucket per window
 * gates the actual report so a mail burst doesn't spam Sentry with one
 * event per send once past the threshold.
 */
final readonly class MailVolumeMonitor implements Mailer
{
    private const HOURLY_WINDOW_SECONDS = 3600;
    private const DAILY_WINDOW_SECONDS  = 86400;

    /** Documented shared-hosting estimate: ~100/hour. Alert at 80%. */
    private const HOURLY_ALERT_THRESHOLD = 80;

    /** Documented shared-hosting estimate: ~500/day. Alert at 80%. */
    private const DAILY_ALERT_THRESHOLD = 400;

    public function __construct(
        private Mailer $inner,
        private RateLimiter $rateLimiter,
        private ErrorReporter $reporter,
        private AuditLogger $audit,
        private int $hourlyAlertThreshold = self::HOURLY_ALERT_THRESHOLD,
        private int $dailyAlertThreshold = self::DAILY_ALERT_THRESHOLD,
    ) {}

    public function send(
        string $toEmail,
        string $subject,
        string $textBody,
        ?string $htmlBody = null,
        ?InlineImage $inlineImage = null,
    ): void {
        $this->inner->send($toEmail, $subject, $textBody, $htmlBody, $inlineImage);

        $this->checkThreshold('mail:volume:hourly', self::HOURLY_WINDOW_SECONDS, $this->hourlyAlertThreshold, 'hourly');
        $this->checkThreshold('mail:volume:daily', self::DAILY_WINDOW_SECONDS, $this->dailyAlertThreshold, 'daily');
    }

    private function checkThreshold(string $bucket, int $windowSeconds, int $threshold, string $window): void
    {
        $count = $this->rateLimiter->count($bucket, $windowSeconds);
        if ($count < $threshold) {
            return;
        }

        // Fires exactly once per window: the first crossing hits a fresh
        // limit=1 bucket (count becomes 1, 1 <= 1 → true); every further
        // send in the same window pushes that bucket's count above 1 (false).
        if (!$this->rateLimiter->hit($bucket . ':alerted', 1, $windowSeconds)) {
            return;
        }

        $this->reporter->report(new \RuntimeException(
            "Outbound mail volume approaching the shared-hosting account-wide send-limit estimate ({$window}): "
            . "{$count} sent, alert threshold {$threshold}.",
        ));
        $this->audit->log('mail.volume_alert', ['window' => $window, 'count' => $count, 'threshold' => $threshold]);
    }
}
