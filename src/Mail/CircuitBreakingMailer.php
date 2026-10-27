<?php

declare(strict_types=1);

namespace Votepit\Mail;

use Votepit\Resilience\CircuitBreaker;
use Votepit\Resilience\CircuitOpenException;

/**
 * Mailer decorator (OWASP/BSI audit remediation issue 13): if the SMTP
 * relay has failed $failureThreshold sends in a row, the circuit opens and
 * every further send() fails fast with CircuitOpenException for
 * $cooldownSeconds instead of blocking on (and eventually timing out
 * against) a relay that is very likely still down — the same
 * graceful-degradation shape MailVolumeMonitor already established for a
 * different concern (volume, not availability). Wrap innermost, i.e.
 * BEFORE MailVolumeMonitor in the decorator chain (AppFactory), so a
 * skipped send never counts toward the volume buckets.
 */
final readonly class CircuitBreakingMailer implements Mailer
{
    private const SERVICE = 'smtp';
    private const DEFAULT_FAILURE_THRESHOLD = 3;
    private const DEFAULT_COOLDOWN_SECONDS  = 120;

    public function __construct(
        private Mailer $inner,
        private CircuitBreaker $breaker,
        private int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD,
        private int $cooldownSeconds = self::DEFAULT_COOLDOWN_SECONDS,
    ) {}

    public function send(
        string $toEmail,
        string $subject,
        string $textBody,
        ?string $htmlBody = null,
        ?InlineImage $inlineImage = null,
        ?string $fromName = null,
        array $headers = [],
    ): void {
        if ($this->breaker->isOpen(self::SERVICE, $this->cooldownSeconds)) {
            throw new CircuitOpenException(self::SERVICE);
        }

        try {
            $this->inner->send($toEmail, $subject, $textBody, $htmlBody, $inlineImage, $fromName, $headers);
        } catch (\Throwable $e) {
            $this->breaker->recordFailure(self::SERVICE, $this->failureThreshold);
            throw $e;
        }

        $this->breaker->recordSuccess(self::SERVICE);
    }
}
