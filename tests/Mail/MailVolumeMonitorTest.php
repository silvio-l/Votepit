<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use Votepit\Logging\AuditLogger;
use Votepit\Mail\InMemoryMailer;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailVolumeMonitor;
use Votepit\Monitoring\ErrorReporter;
use Votepit\Security\RateLimiter;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Outbound-mail-volume monitoring against a documented shared-hosting
 * account-wide send-limit estimate. Uses small thresholds (constructor
 * overrides) instead of the real 80/400
 * production defaults, so the test doesn't need to send hundreds of mails.
 */
final class MailVolumeMonitorTest extends IntegrationTestCase
{
    private function monitor(Mailer $inner, ErrorReporter $reporter, int $hourlyThreshold = 3, int $dailyThreshold = 1000): MailVolumeMonitor
    {
        return new MailVolumeMonitor(
            $inner,
            new RateLimiter($this->conn),
            $reporter,
            new AuditLogger($this->logFile),
            $hourlyThreshold,
            $dailyThreshold,
        );
    }

    public function test_sends_below_threshold_never_alert(): void
    {
        $inner    = new InMemoryMailer();
        $reporter = new RecordingErrorReporter();
        $monitor  = $this->monitor($inner, $reporter, hourlyThreshold: 5);

        $monitor->send('a@example.com', 'Subject', 'Body');
        $monitor->send('b@example.com', 'Subject', 'Body');

        self::assertCount(2, $inner->sent);
        self::assertSame([], $reporter->reported);
    }

    public function test_crossing_the_hourly_threshold_reports_exactly_once(): void
    {
        $inner    = new InMemoryMailer();
        $reporter = new RecordingErrorReporter();
        $monitor  = $this->monitor($inner, $reporter, hourlyThreshold: 2);

        // 1st/2nd sends stay at/under threshold; 3rd crosses it and alerts;
        // 4th stays over but must NOT alert again (same window).
        $monitor->send('a@example.com', 'Subject', 'Body');
        $monitor->send('b@example.com', 'Subject', 'Body');
        $monitor->send('c@example.com', 'Subject', 'Body');
        $monitor->send('d@example.com', 'Subject', 'Body');

        self::assertCount(4, $inner->sent);
        self::assertCount(1, $reporter->reported);
        self::assertStringContainsString('hourly', $reporter->reported[0]->getMessage());

        $log = $this->readAuditLog();
        self::assertSame(1, substr_count($log, 'mail.volume_alert'));
    }

    public function test_a_send_that_would_fail_never_reaches_the_threshold_check(): void
    {
        $failing = new class () implements Mailer {
            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null, ?\Votepit\Mail\InlineImage $inlineImage = null): void
            {
                throw new \RuntimeException('SMTP down');
            }
        };
        $reporter = new RecordingErrorReporter();
        $monitor  = $this->monitor($failing, $reporter, hourlyThreshold: 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP down');

        try {
            $monitor->send('a@example.com', 'Subject', 'Body');
        } finally {
            // Never blocks/masks the real send failure, and never
            // (mis)counts a mail that was never actually sent.
            self::assertSame([], $reporter->reported);
        }
    }
}
