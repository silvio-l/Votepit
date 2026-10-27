<?php

declare(strict_types=1);

namespace Votepit\Cron;

use Votepit\Logging\AuditLogger;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailTemplate;
use Votepit\Monitoring\ErrorReporter;
use Votepit\Persistence\CronJobRepository;
use Votepit\Persistence\CronJobState;

/**
 * Self-alerting for the cron manager (research §3.7). Runs at the end of
 * every tick, reads the persisted job states and sends ONE batched mail per
 * recipient (admin_emails) for every job that changed alert state.
 *
 * Mail carries job name, exit reason, time and the consecutive count only.
 * Job output is never included: it can contain personal data.
 *
 * Sentry (ErrorReporter) is told only about state changes that were actually
 * delivered: an alert opening and a recovery. Re-alerts stay out of Sentry to
 * respect the shared quota.
 *
 * Never throws into the tick. A delivery failure is written to the audit log
 * and STDERR, and the state is left unchanged, so the next tick retries it.
 */
final readonly class CronAlerter
{
    private const AUDIT_FAILED = 'cron.alert_not_delivered';

    /**
     * @param \Closure(): Mailer              $mailerFactory builds the mailer lazily, so a broken SMTP setup is a delivery failure, not a crash
     * @param list<string>                    $recipients    the installation's admin_emails (plaintext)
     * @param \Closure(string): void          $warn          receives one line for STDERR
     */
    public function __construct(
        private CronJobRepository $jobs,
        private \Closure $mailerFactory,
        private array $recipients,
        private ErrorReporter $reporter,
        private AuditLogger $audit,
        private \Closure $warn,
    ) {}

    /** @param list<CronJobDefinition> $definitions */
    public function evaluate(array $definitions, \DateTimeImmutable $now): void
    {
        try {
            $this->evaluateOrFail($definitions, $now);
        } catch (\Throwable $e) {
            $this->warn('evaluation failed (' . $e->getMessage() . ')');
        }
    }

    /** @param list<CronJobDefinition> $definitions */
    private function evaluateOrFail(array $definitions, \DateTimeImmutable $now): void
    {
        $states = $this->jobs->all();

        /** @var list<array{job: CronJobDefinition, state: CronJobState, decision: AlertDecision}> $changes */
        $changes = [];
        foreach ($definitions as $job) {
            $state = $states[$job->name] ?? null;
            if ($state === null) {
                continue;
            }

            $threshold = CronAlertPolicy::failureThreshold($job->cronExpr, $now);
            $decision  = CronAlertPolicy::decide($state, $threshold, $now);
            if ($decision !== AlertDecision::None) {
                $changes[] = ['job' => $job, 'state' => $state, 'decision' => $decision];
            }
        }

        if ($changes === []) {
            return;
        }

        if (!$this->deliver($changes)) {
            return;
        }

        foreach ($changes as $change) {
            $this->jobs->updateAlertState(
                $change['job']->name,
                CronAlertPolicy::stateAfter($change['decision']),
                $change['decision'] === AlertDecision::Recover ? null : $now,
            );
            if (in_array($change['decision'], [AlertDecision::Alert, AlertDecision::Recover], true)) {
                $this->reportToSentry($change['job']->name, $change['decision']);
            }
        }
    }

    /** @param list<array{job: CronJobDefinition, state: CronJobState, decision: AlertDecision}> $changes */
    private function deliver(array $changes): bool
    {
        if ($this->recipients === []) {
            $this->audit->log(self::AUDIT_FAILED, ['reason' => 'no admin_emails configured', 'jobs' => self::names($changes)]);

            return true;
        }

        $subject = self::subject($changes);
        $body    = self::body($changes);

        try {
            $mailer = ($this->mailerFactory)();
        } catch (\Throwable $e) {
            $this->recordUndelivered($changes, $e);

            return false;
        }

        $sent = 0;
        foreach ($this->recipients as $recipient) {
            try {
                $mailer->send($recipient, $subject, $body['text'], $body['html'], $body['image']);
                $sent++;
            } catch (\Throwable $e) {
                $this->recordUndelivered($changes, $e);
            }
        }

        return $sent > 0;
    }

    /** @param list<array{job: CronJobDefinition, state: CronJobState, decision: AlertDecision}> $changes */
    private function recordUndelivered(array $changes, \Throwable $e): void
    {
        $this->audit->log(self::AUDIT_FAILED, ['jobs' => self::names($changes), 'error' => $e->getMessage()]);
        $this->warn('alert mail not delivered (' . $e->getMessage() . ')');
    }

    /** @param list<array{job: CronJobDefinition, state: CronJobState, decision: AlertDecision}> $changes */
    public static function subject(array $changes): string
    {
        $recovered = array_filter($changes, static fn (array $c): bool => $c['decision'] === AlertDecision::Recover);
        $prefix    = count($recovered) === count($changes) ? 'Cron recovered' : 'Cron alert';

        return sprintf('[Votepit] %s: %s', $prefix, implode(', ', self::names($changes)));
    }

    /**
     * @param list<array{job: CronJobDefinition, state: CronJobState, decision: AlertDecision}> $changes
     * @return array{text: string, html: string|null, image: \Votepit\Mail\InlineImage|null}
     */
    public static function body(array $changes): array
    {
        $paragraphs = [];
        foreach ($changes as $change) {
            $state     = $change['state'];
            $lastRun   = $state->finishedAt instanceof \DateTimeImmutable
                ? $state->finishedAt->format('Y-m-d H:i:s') . ' UTC'
                : 'unknown time';

            $paragraphs[] = match ($change['decision']) {
                AlertDecision::Recover => sprintf('%s has recovered. Last run finished %s successfully.', $change['job']->name, $lastRun),
                AlertDecision::Realert => sprintf(
                    '%s is still failing: %d consecutive failed runs. Last run finished %s, %s.',
                    $change['job']->name,
                    $state->consecutiveFailures,
                    $lastRun,
                    self::reason($state->lastExit),
                ),
                default => sprintf(
                    '%s is failing: %d consecutive failed runs. Last run finished %s, %s.',
                    $change['job']->name,
                    $state->consecutiveFailures,
                    $lastRun,
                    self::reason($state->lastExit),
                ),
            };
        }

        $rendered = MailTemplate::render(
            'Votepit cron alert',
            $paragraphs,
            null,
            null,
            ['Job output is not included in this email. The per-job logs are in logs/cron/ on the server.'],
        );

        return ['text' => $rendered['text'], 'html' => $rendered['html'], 'image' => $rendered['image']];
    }

    private static function reason(?int $exitCode): string
    {
        return match (true) {
            $exitCode === null  => 'no exit status recorded',
            $exitCode === -1    => 'reason: timed out, could not start, or ended without an exit status',
            default             => 'exit code ' . $exitCode,
        };
    }

    private function reportToSentry(string $jobName, AlertDecision $decision): void
    {
        $message = $decision === AlertDecision::Recover
            ? sprintf('Cron job "%s" recovered', $jobName)
            : sprintf('Cron job "%s" is failing', $jobName);

        try {
            $this->reporter->report(new \RuntimeException($message));
        } catch (\Throwable) {
            // Monitoring must not break the tick either.
        }
    }

    /**
     * @param list<array{job: CronJobDefinition, state: CronJobState, decision: AlertDecision}> $changes
     * @return list<string>
     */
    private static function names(array $changes): array
    {
        return array_map(static fn (array $c): string => $c['job']->name, $changes);
    }

    private function warn(string $message): void
    {
        ($this->warn)('Votepit cron: ' . $message . '.');
    }
}
