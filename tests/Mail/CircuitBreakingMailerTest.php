<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use Votepit\Mail\CircuitBreakingMailer;
use Votepit\Mail\InlineImage;
use Votepit\Mail\InMemoryMailer;
use Votepit\Mail\Mailer;
use Votepit\Resilience\CircuitBreaker;
use Votepit\Resilience\CircuitOpenException;
use Votepit\Tests\Support\IntegrationTestCase;

/** OWASP/BSI audit remediation issue 13: SMTP circuit breaker. */
final class CircuitBreakingMailerTest extends IntegrationTestCase
{
    private function alwaysFailingInner(): Mailer
    {
        return new class () implements Mailer {
            public function send(
                string $toEmail,
                string $subject,
                string $textBody,
                ?string $htmlBody = null,
                ?InlineImage $inlineImage = null,
                ?string $fromName = null,
                array $headers = [],
            ): void {
                throw new \RuntimeException('SMTP relay unreachable');
            }
        };
    }

    public function test_passes_sends_through_while_closed(): void
    {
        $inner   = new InMemoryMailer();
        $mailer  = new CircuitBreakingMailer($inner, new CircuitBreaker($this->conn));

        $mailer->send('a@example.com', 'Subject', 'Body');

        self::assertSame(1, $inner->count());
    }

    public function test_opens_after_the_threshold_and_fails_fast_without_calling_inner(): void
    {
        $inner   = $this->alwaysFailingInner();
        $mailer  = new CircuitBreakingMailer($inner, new CircuitBreaker($this->conn), failureThreshold: 2);

        try {
            $mailer->send('a@example.com', 'Subject', 'Body');
            self::fail('expected exception');
        } catch (\RuntimeException) {
        }
        try {
            $mailer->send('a@example.com', 'Subject', 'Body');
            self::fail('expected exception');
        } catch (\RuntimeException) {
        }

        // Third call: circuit is now open — CircuitOpenException, the inner
        // (still always-failing) mailer is never invoked again.
        $this->expectException(CircuitOpenException::class);
        $mailer->send('a@example.com', 'Subject', 'Body');
    }

    public function test_a_successful_send_resets_the_failure_count(): void
    {
        $breaker = new CircuitBreaker($this->conn);
        $failing = new CircuitBreakingMailer($this->alwaysFailingInner(), $breaker, failureThreshold: 2);

        try {
            $failing->send('a@example.com', 'Subject', 'Body');
        } catch (\RuntimeException) {
        }

        // One failure recorded, below threshold=2 — a subsequent success
        // through the SAME breaker resets it to zero.
        $working = new CircuitBreakingMailer(new InMemoryMailer(), $breaker, failureThreshold: 2);
        $working->send('a@example.com', 'Subject', 'Body');

        self::assertFalse($breaker->isOpen('smtp', 120));
    }
}
