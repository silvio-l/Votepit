<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use Votepit\Logging\AuditLogger;
use Votepit\Mail\MailVolumeMonitor;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Mail\SymfonyMailerAdapter;
use Votepit\Monitoring\ErrorReporter;
use Votepit\Persistence\BoardSmtpSettingsRepository;
use Votepit\Persistence\SmtpSettingsRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\RateLimiter;
use Votepit\SmtpConfig;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * review-2026-09-04-fixes item 15: SmtpConfigResolver::buildMailer() is the
 * single choke point every mail-sending action's `$this->mailer ?? ...`
 * fallback now uses, so outbound-mail-volume monitoring applies uniformly.
 * Monitoring only activates when the resolver was actually given the three
 * monitoring dependencies — every pre-existing resolver construction
 * (SmtpConfigResolverRebindingTest and friends) keeps working unwrapped.
 */
final class SmtpConfigResolverBuildMailerTest extends IntegrationTestCase
{
    private function fallback(): SmtpConfig
    {
        return SmtpConfig::fromArray(['host' => 'fallback.example.com', 'port' => 587, 'from_email' => 'a@b.c']);
    }

    private function noopReporter(): ErrorReporter
    {
        return new class () implements ErrorReporter {
            public function report(\Throwable $exception): void {}
        };
    }

    public function test_build_mailer_returns_bare_adapter_without_monitoring_dependencies(): void
    {
        $resolver = new SmtpConfigResolver(
            new SmtpSettingsRepository($this->conn),
            new BoardSmtpSettingsRepository($this->conn),
            new EncryptionService(str_repeat('a', 64)),
            $this->fallback(),
        );

        self::assertInstanceOf(SymfonyMailerAdapter::class, $resolver->buildMailer(null));
    }

    public function test_build_mailer_wraps_with_the_volume_monitor_when_fully_wired(): void
    {
        $resolver = new SmtpConfigResolver(
            new SmtpSettingsRepository($this->conn),
            new BoardSmtpSettingsRepository($this->conn),
            new EncryptionService(str_repeat('a', 64)),
            $this->fallback(),
            rateLimiter: new RateLimiter($this->conn),
            reporter: $this->noopReporter(),
            audit: new AuditLogger($this->logFile),
        );

        self::assertInstanceOf(MailVolumeMonitor::class, $resolver->buildMailer(null));
    }
}
