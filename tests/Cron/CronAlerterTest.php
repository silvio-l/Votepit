<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use Votepit\Cron\CatchUpPolicy;
use Votepit\Cron\CronAlerter;
use Votepit\Cron\CronJobDefinition;
use Votepit\Cron\CronManager;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\InMemoryMailer;
use Votepit\Mail\Mailer;
use Votepit\Persistence\CronJobRepository;
use Votepit\Tests\Mail\RecordingErrorReporter;
use Votepit\Tests\Support\IntegrationTestCase;

final class CronAlerterTest extends IntegrationTestCase
{
    private const RECIPIENTS = ['admin@example.com', 'ops@example.com'];

    private string $auditFile = '';

    /** @var list<string> */
    private array $stderr = [];

    protected function setUp(): void
    {
        parent::setUp();
        $tmp             = tempnam(sys_get_temp_dir(), 'cron-alert-audit-');
        $this->auditFile = $tmp === false ? '' : $tmp;
        $this->stderr    = [];
    }

    protected function tearDown(): void
    {
        if ($this->auditFile !== '' && is_file($this->auditFile)) {
            unlink($this->auditFile);
        }
        parent::tearDown();
    }

    private function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $expr
     */
    private function definition(string $name, string $expr = '* * * * *'): CronJobDefinition
    {
        return new CronJobDefinition($name, ['/usr/bin/php', '/app/' . $name . '.php'], $expr, CatchUpPolicy::None, 60, 'core');
    }

    /** Claims and finishes one run of $name for the slot at $slot, with $exit as its outcome. */
    private function runJob(CronJobRepository $repo, string $name, string $slot, int $exit): void
    {
        $token = bin2hex(random_bytes(16));
        $at    = $this->at($slot);
        self::assertTrue($repo->claim($name, $at, $token, $at->modify('+5 minutes'), $at));
        $repo->finish($name, $token, $exit, $at->modify('+2 seconds'));
    }

    private function alerter(CronJobRepository $repo, ?Mailer $sink = null, ?RecordingErrorReporter $reporter = null, ?\Closure $factory = null): CronAlerter
    {
        $inMemory = $sink ?? new InMemoryMailer();

        return new CronAlerter(
            $repo,
            $factory ?? static fn (): Mailer => $inMemory,
            self::RECIPIENTS,
            $reporter ?? new RecordingErrorReporter(),
            new AuditLogger($this->auditFile),
            function (string $line): void {
                $this->stderr[] = $line;
            },
        );
    }

    public function test_alert_goes_out_at_the_threshold_once_per_recipient(): void
    {
        $repo   = new CronJobRepository($this->conn);
        $defs   = [$this->definition('check-demo-abuse')];
        $repo->upsertDefinition($defs[0]);
        $mailer   = new InMemoryMailer();
        $reporter = new RecordingErrorReporter();
        $alerter  = $this->alerter($repo, sink: $mailer, reporter: $reporter);

        $this->runJob($repo, 'check-demo-abuse', '2026-10-22 12:00:00', 1);
        $this->runJob($repo, 'check-demo-abuse', '2026-10-22 12:01:00', 1);
        $alerter->evaluate($defs, $this->at('2026-10-22 12:02:00'));
        self::assertSame(0, $this->sentCount($mailer), 'two failures stay below the threshold of three');

        $this->runJob($repo, 'check-demo-abuse', '2026-10-22 12:02:00', 1);
        $alerter->evaluate($defs, $this->at('2026-10-22 12:03:00'));

        self::assertSame(2, $this->sentCount($mailer));
        self::assertSame(['admin@example.com', 'ops@example.com'], [$mailer->sent[0]['to'], $mailer->sent[1]['to']]);
        self::assertStringContainsString('check-demo-abuse', $mailer->sent[0]['subject']);
        self::assertStringContainsString('3 consecutive', $mailer->sent[0]['body']);
        self::assertStringContainsString('exit code 1', $mailer->sent[0]['body']);
        self::assertCount(1, $reporter->reported, 'Sentry sees the state change, not every mail');
        self::assertSame('failing', $repo->find('check-demo-abuse')?->alertState);
    }

    public function test_no_repeat_mail_inside_six_hours_and_one_re_alert_after(): void
    {
        $repo    = new CronJobRepository($this->conn);
        $defs    = [$this->definition('check-demo-abuse')];
        $repo->upsertDefinition($defs[0]);
        $mailer  = new InMemoryMailer();
        $alerter = $this->alerter($repo, sink: $mailer);

        foreach (range(0, 2) as $i) {
            $this->runJob($repo, 'check-demo-abuse', sprintf('2026-10-22 12:%02d:00', $i), 1);
        }
        $alerter->evaluate($defs, $this->at('2026-10-22 12:03:00'));
        $this->runJob($repo, 'check-demo-abuse', '2026-10-22 17:50:00', 1);
        $alerter->evaluate($defs, $this->at('2026-10-22 17:51:00'));
        self::assertSame(2, $this->sentCount($mailer), 'still failing before 6 h: no new mail beyond the first pair');

        $this->runJob($repo, 'check-demo-abuse', '2026-10-22 18:03:00', 1);
        $alerter->evaluate($defs, $this->at('2026-10-22 18:04:00'));

        self::assertSame(4, $this->sentCount($mailer));
        self::assertStringContainsString('still failing', $mailer->sent[2]['body']);
    }

    public function test_one_recovery_notice_then_silence(): void
    {
        $repo    = new CronJobRepository($this->conn);
        $defs    = [$this->definition('check-demo-abuse')];
        $repo->upsertDefinition($defs[0]);
        $mailer  = new InMemoryMailer();
        $reporter = new RecordingErrorReporter();
        $alerter = $this->alerter($repo, sink: $mailer, reporter: $reporter);

        foreach (range(0, 2) as $i) {
            $this->runJob($repo, 'check-demo-abuse', sprintf('2026-10-22 12:%02d:00', $i), 1);
        }
        $alerter->evaluate($defs, $this->at('2026-10-22 12:03:00'));
        $this->runJob($repo, 'check-demo-abuse', '2026-10-22 12:03:00', 0);
        $alerter->evaluate($defs, $this->at('2026-10-22 12:04:00'));
        $alerter->evaluate($defs, $this->at('2026-10-22 12:05:00'));

        self::assertSame(4, $this->sentCount($mailer), 'alert + recovery, one mail each per recipient, nothing more');
        self::assertStringContainsString('recovered', $mailer->sent[2]['subject']);
        self::assertSame('ok', $repo->find('check-demo-abuse')?->alertState);
        self::assertCount(1, $reporter->reported, 'the recovery is mailed but never sent to Sentry');
    }

    public function test_jobs_that_change_state_in_one_tick_share_one_mail(): void
    {
        $repo = new CronJobRepository($this->conn);
        $defs = [$this->definition('job-a'), $this->definition('job-b')];
        foreach ($defs as $job) {
            $repo->upsertDefinition($job);
            foreach (range(0, 2) as $i) {
                $this->runJob($repo, $job->name, sprintf('2026-10-22 12:%02d:00', $i), -1);
            }
        }
        $mailer = new InMemoryMailer();

        $this->alerter($repo, sink: $mailer)->evaluate($defs, $this->at('2026-10-22 12:03:00'));

        self::assertSame(2, $this->sentCount($mailer), 'one mail per recipient, not one per job');
        self::assertStringContainsString('job-a', $mailer->sent[0]['body']);
        self::assertStringContainsString('job-b', $mailer->sent[0]['body']);
        self::assertStringContainsString('timed out', $mailer->sent[0]['body'], 'exit -1 is explained as a timeout or start failure');
    }

    public function test_a_failed_delivery_never_throws_and_is_retried_on_the_next_tick(): void
    {
        $repo = new CronJobRepository($this->conn);
        $defs = [$this->definition('check-demo-abuse')];
        $repo->upsertDefinition($defs[0]);
        foreach (range(0, 2) as $i) {
            $this->runJob($repo, 'check-demo-abuse', sprintf('2026-10-22 12:%02d:00', $i), 1);
        }
        $broken = new class () implements Mailer {
            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null, ?\Votepit\Mail\InlineImage $inlineImage = null, ?string $fromName = null, array $headers = []): void
            {
                throw new \RuntimeException('smtp relay refused the connection');
            }
        };

        $this->alerter($repo, sink: $broken)->evaluate($defs, $this->at('2026-10-22 12:03:00'));

        self::assertNotSame('failing', $repo->find('check-demo-abuse')?->alertState, 'undelivered alert is not marked as sent');
        self::assertNotEmpty($this->stderr);
        self::assertStringContainsString('check-demo-abuse', $this->readAudit());

        $mailer = new InMemoryMailer();
        $this->alerter($repo, sink: $mailer)->evaluate($defs, $this->at('2026-10-22 12:04:00'));

        self::assertSame(2, $this->sentCount($mailer));
        self::assertSame('failing', $repo->find('check-demo-abuse')?->alertState);
    }

    public function test_a_mailer_that_cannot_be_built_is_reported_not_thrown(): void
    {
        $repo = new CronJobRepository($this->conn);
        $defs = [$this->definition('check-demo-abuse')];
        $repo->upsertDefinition($defs[0]);
        foreach (range(0, 2) as $i) {
            $this->runJob($repo, 'check-demo-abuse', sprintf('2026-10-22 12:%02d:00', $i), 1);
        }

        $alerter = $this->alerter($repo, factory: static function (): Mailer {
            throw new \RuntimeException('smtp settings invalid');
        });
        $alerter->evaluate($defs, $this->at('2026-10-22 12:03:00'));

        self::assertNotEmpty($this->stderr);
        self::assertNotSame('failing', $repo->find('check-demo-abuse')?->alertState);
    }

    public function test_without_recipients_the_state_still_moves_and_nothing_is_sent(): void
    {
        $repo = new CronJobRepository($this->conn);
        $defs = [$this->definition('check-demo-abuse')];
        $repo->upsertDefinition($defs[0]);
        foreach (range(0, 2) as $i) {
            $this->runJob($repo, 'check-demo-abuse', sprintf('2026-10-22 12:%02d:00', $i), 1);
        }
        $mailer  = new InMemoryMailer();
        $alerter = new CronAlerter(
            $repo,
            static fn (): Mailer => $mailer,
            [],
            new RecordingErrorReporter(),
            new AuditLogger($this->auditFile),
            static function (string $line): void {},
        );

        $alerter->evaluate($defs, $this->at('2026-10-22 12:03:00'));

        self::assertSame(0, $this->sentCount($mailer));
        self::assertSame('failing', $repo->find('check-demo-abuse')?->alertState);
    }

    public function test_the_manager_tick_sends_the_alert_after_dispatch_settles(): void
    {
        $repo = new CronJobRepository($this->conn);
        $job  = $this->definition('check-demo-abuse');
        $repo->upsertDefinition($job);
        foreach (range(0, 2) as $i) {
            $this->runJob($repo, 'check-demo-abuse', sprintf('2026-10-22 12:%02d:00', $i), 1);
        }
        $mailer  = new InMemoryMailer();
        $manager = new CronManager($repo, new FakeJobDispatcher(), 3, 120, static fn (int $pid): bool => true, $this->alerter($repo, sink: $mailer));

        $manager->tick([$job], $this->at('2026-10-22 12:03:20'));

        self::assertSame(2, $this->sentCount($mailer), 'the tick that starts after the third failure reports it');
    }

    private function sentCount(InMemoryMailer $mailer): int
    {
        return count($mailer->sent);
    }

    private function readAudit(): string
    {
        return is_file($this->auditFile) ? (string) file_get_contents($this->auditFile) : '';
    }
}
