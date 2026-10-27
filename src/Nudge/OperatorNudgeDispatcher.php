<?php

declare(strict_types=1);

namespace Votepit\Nudge;

use Votepit\Config;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailVolumeMonitor;
use Votepit\Persistence\OperatorNudgeRepository;
use Votepit\Security\OperatorNudgeUnsubscribeToken;
use Votepit\Security\RateLimiter;

/**
 * Sends one operator nudge to every eligible owner of one account, claim
 * first (idempotent, see OperatorNudgeRepository::claim()), then mail.
 *
 * Lowest mail priority: before every single send the shared MailVolumeMonitor
 * buckets are peeked, and the dispatch stops as soon as they reach the
 * (deliberately low) ceilings. Magic-link and digest mail keep the headroom.
 * A failed send releases its claim, so the next run retries it; one failing
 * recipient never stops the others.
 */
final readonly class OperatorNudgeDispatcher
{
    /** Recipients must have been active within this window (decay, owner decision 6). */
    public const ACTIVE_WITHIN_DAYS = 90;

    public function __construct(
        private OperatorNudgeRepository $nudges,
        private Mailer $mailer,
        private RateLimiter $rateLimiter,
        private OperatorNudgeUnsubscribeToken $tokens,
        private Config $config,
        private int $hourlyVolumeCeiling = 20,
        private int $dailyVolumeCeiling = 150,
    ) {}

    public function hasVolumeHeadroom(): bool
    {
        return $this->rateLimiter->peek(MailVolumeMonitor::HOURLY_BUCKET, MailVolumeMonitor::HOURLY_WINDOW_SECONDS) < $this->hourlyVolumeCeiling
            && $this->rateLimiter->peek(MailVolumeMonitor::DAILY_BUCKET, MailVolumeMonitor::DAILY_WINDOW_SECONDS) < $this->dailyVolumeCeiling;
    }

    /**
     * @param int $budget          mails this dispatch may attempt at most
     * @param \Closure(string $language, string $unsubscribeUrl, string $profileUrl): array{subject: string, text: string, html: string, image: \Votepit\Mail\InlineImage} $compose
     */
    public function dispatch(
        int $accountId,
        OperatorNudgeKind $kind,
        string $scope,
        \DateTimeImmutable $now,
        int $budget,
        \Closure $compose,
    ): NudgeRunResult {
        $sent      = 0;
        $failed    = 0;
        $exhausted = false;
        $lastError = null;
        $profileUrl = $this->config->appUrl . '/profile';

        $recipients = $this->nudges->recipientsFor(
            $accountId,
            $kind,
            $now->modify('-' . self::ACTIVE_WITHIN_DAYS . ' days'),
        );

        foreach ($recipients as $recipient) {
            if ($sent + $failed >= $budget || !$this->hasVolumeHeadroom()) {
                $exhausted = true;
                break;
            }

            if (!$this->nudges->claim($recipient['id'], $accountId, $kind, $scope, $now)) {
                continue;
            }

            $unsubscribeUrl = $this->config->appUrl . '/operator-nudges/unsubscribe?token='
                . $this->tokens->sign($recipient['id'], $kind);

            try {
                $mail = $compose($recipient['language'], $unsubscribeUrl, $profileUrl);
                $this->mailer->send($recipient['email'], $mail['subject'], $mail['text'], $mail['html'], $mail['image']);
            } catch (\Throwable $e) {
                $this->nudges->release($recipient['id'], $accountId, $kind, $scope);
                $failed++;
                $lastError = $e->getMessage();
                continue;
            }

            $sent++;
        }

        return new NudgeRunResult($sent, $failed, $exhausted, $lastError);
    }
}
