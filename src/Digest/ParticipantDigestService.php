<?php

declare(strict_types=1);

namespace Votepit\Digest;

use Votepit\Config;
use Votepit\Mail\BoardMailBranding;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailVolumeMonitor;
use Votepit\Participant\ParticipantMailCategory;
use Votepit\Participant\ParticipantMailPolicy;
use Votepit\Participant\ParticipantMailRouter;
use Votepit\Persistence\ParticipantMailRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\RateLimiter;

/**
 * Participant digest (close-the-loop re-engagement C/G): delivers the queued
 * status, comment and thread-reply items as batched mails instead of one mail
 * per change.
 *
 * Per recipient and run:
 *  - every pending queue row is mapped to its EFFECTIVE cadence
 *    (ParticipantMailPolicy: stored cadence + decay by last app visit). Rows
 *    whose cadence is off, or whose recipient stopped after 90 idle days, are
 *    dropped on purpose; rows of an instant cadence are batched as daily;
 *  - each cadence group sends at most ONE mail per period (idempotency claim
 *    per recipient + period in participant_digest_sends, inserted before the
 *    send and released again on failure);
 *  - a group whose period is already claimed keeps its rows pending for the
 *    next period, and a run without volume headroom (MailVolumeMonitor
 *    buckets) defers everything. Deferral never drops a row.
 *
 * Guests (anonymous voters with a notification address) always get the daily
 * group; their address is decrypted only for the send and never stored in the
 * clear. Run hourly by bin/send-participant-digest.php.
 *
 * Known limit: pending recipients are read in ascending id order with a
 * bounded candidate list. Recipients whose period is already claimed stay in
 * that list until the period rolls over, so a very large backlog of such
 * recipients could delay the rest by up to one period.
 */
final class ParticipantDigestService
{
    private const RETENTION_QUEUE_DAYS = 30;
    private const RETENTION_CLAIM_DAYS = 120;
    private const CANDIDATES_PER_RUN   = 200;
    private const ROWS_PER_RECIPIENT   = 500;
    private const ITEMS_PER_MAIL       = 100;
    private const GUEST_LANGUAGE       = 'en';

    private int $sent      = 0;
    private int $skipped   = 0;
    private int $failed    = 0;
    private bool $exhausted = false;
    private ?string $lastError = null;

    public function __construct(
        private readonly ParticipantMailRepository $mail,
        private readonly BoardMailBranding $branding,
        private readonly Mailer $mailer,
        private readonly RateLimiter $rateLimiter,
        private readonly EncryptionService $guestEmailEncryption,
        private readonly ParticipantMailRouter $router,
        private readonly Config $config,
        private readonly int $maxPerRun = 30,
        private readonly int $hourlyVolumeCeiling = 40,
        private readonly int $dailyVolumeCeiling = 250,
    ) {}

    public function run(\DateTimeImmutable $now): DigestRunResult
    {
        // Kill switch (Config::$participantMailsEnabled): nothing is sent while off.
        if (!$this->config->participantMailsEnabled) {
            return new DigestRunResult(0, 0, 0, false);
        }

        $this->sent       = 0;
        $this->skipped    = 0;
        $this->failed     = 0;
        $this->exhausted  = false;
        $this->lastError  = null;

        $this->mail->purgeOld(
            $now->modify('-' . self::RETENTION_QUEUE_DAYS . ' days'),
            $now->modify('-' . self::RETENTION_CLAIM_DAYS . ' days'),
        );

        foreach ($this->mail->pendingUserIds(self::CANDIDATES_PER_RUN) as $userId) {
            if ($this->budgetSpent()) {
                $this->exhausted = true;
                break;
            }
            $this->deliverToUser($userId, $now);
        }

        foreach ($this->mail->pendingGuestKeys(self::CANDIDATES_PER_RUN) as $guestKey) {
            if ($this->budgetSpent()) {
                $this->exhausted = true;
                break;
            }
            $this->deliverToGuest($guestKey, $now);
        }

        return new DigestRunResult($this->sent, $this->skipped, $this->failed, $this->exhausted, $this->lastError);
    }

    private function deliverToUser(int $userId, \DateTimeImmutable $now): void
    {
        $rows      = $this->mail->pendingForUser($userId, self::ROWS_PER_RECIPIENT);
        $recipient = $this->mail->userRecipient($userId);

        if ($recipient === null || !$recipient['usable'] || $recipient['email'] === null) {
            $this->mail->markHandled($this->rowIds($rows), 'dropped', $now);
            $this->skipped++;

            return;
        }

        $baseline = $this->mail->activityBaseline($userId);
        /** @var array<string, list<array<string, mixed>>> $groups */
        $groups   = [];
        $dropped  = [];

        foreach ($rows as $row) {
            $category  = $row['category'];
            $effective = ParticipantMailPolicy::effectiveCadence(
                $category,
                $this->mail->cadenceFor($userId, $category),
                $baseline,
                $now,
            );

            if ($effective === ParticipantMailPolicy::STOP || $effective === ParticipantMailCategory::CADENCE_OFF) {
                $dropped[] = $row['id'];
                continue;
            }

            $group          = $effective === ParticipantMailCategory::CADENCE_INSTANT
                ? ParticipantMailCategory::CADENCE_DAILY
                : $effective;
            $groups[$group][] = $row;
        }

        if ($dropped !== []) {
            $this->mail->markHandled($dropped, 'dropped', $now);
        }

        foreach ($groups as $group => $groupRows) {
            if ($this->budgetSpent()) {
                $this->exhausted = true;

                return;
            }

            $period = ParticipantMailPolicy::periodKey($group, $now);
            $key    = 'u:' . $userId;

            // Already mailed for this period: the rows wait for the next one.
            if (!$this->mail->claim($key, $period, $now)) {
                continue;
            }

            $this->dispatch(
                array_slice($groupRows, 0, self::ITEMS_PER_MAIL),
                $recipient['email'],
                $recipient['language'],
                $key,
                $period,
                $userId,
                null,
                $now,
            );
        }
    }

    private function deliverToGuest(string $guestKey, \DateTimeImmutable $now): void
    {
        $rows = $this->mail->pendingForGuest($guestKey, self::ITEMS_PER_MAIL);
        if ($rows === []) {
            return;
        }

        $email = $this->guestEmailEncryption->decrypt($rows[0]['recipient_email_enc']);
        if ($email === null || $email === '' || $this->mail->isGuestSuppressed($guestKey)) {
            $this->mail->markHandled($this->rowIds($rows), 'dropped', $now);
            $this->skipped++;

            return;
        }

        $period = ParticipantMailPolicy::periodKey(ParticipantMailCategory::CADENCE_DAILY, $now);
        if (!$this->mail->claim($guestKey, $period, $now)) {
            return;
        }

        $this->dispatch($rows, $email, self::GUEST_LANGUAGE, $guestKey, $period, null, $guestKey, $now);
    }

    /**
     * One mail for one batch of rows. The claim is released on any failure so
     * the next run retries; rows are marked handled only after a successful send.
     *
     * @param list<array<string, mixed>> $batch
     */
    private function dispatch(
        array $batch,
        string $email,
        string $language,
        string $claimKey,
        string $period,
        ?int $userId,
        ?string $guestKey,
        \DateTimeImmutable $now,
    ): void {
        $categories = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['category'], $batch)));
        $category   = count($categories) === 1 ? $categories[0] : 'all';
        $boardIds   = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['board_id'], $batch)));
        $branding   = count($boardIds) === 1 ? $this->branding->forBoard($boardIds[0]) : null;

        try {
            $mail = ParticipantDigestMail::render(
                $language,
                array_map($this->item(...), $batch),
                $this->router->preferencesUrl(),
                $this->router->unsubscribePageUrl($userId, $guestKey, $category),
                $this->config->appUrl,
                $userId === null,
                $branding !== null ? $branding['brand'] : null,
            );

            $this->mailer->send(
                $email,
                $mail['subject'],
                $mail['text'],
                $mail['html'],
                $mail['image'],
                $branding !== null ? $branding['sender_name'] : null,
                $this->router->unsubscribeHeaders($userId, $guestKey, $category),
            );
        } catch (\Throwable $e) {
            $this->mail->releaseClaim($claimKey, $period);
            $this->failed++;
            $this->lastError = $e->getMessage();

            return;
        }

        $this->mail->markHandled($this->rowIds($batch), 'sent', $now);
        $this->sent++;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{event_kind: string, title: string, status: ?string, reason: ?string, excerpt: ?string, link: string}
     */
    private function item(array $row): array
    {
        $decoded = json_decode((string) $row['payload'], true);
        $payload = is_array($decoded) ? $decoded : [];

        return [
            'event_kind' => (string) $row['event_kind'],
            'title'      => is_string($payload['title'] ?? null) ? $payload['title'] : '',
            'status'     => is_string($payload['status'] ?? null) ? $payload['status'] : null,
            'reason'     => is_string($payload['reason'] ?? null) ? $payload['reason'] : null,
            'excerpt'    => is_string($payload['excerpt'] ?? null) ? $payload['excerpt'] : null,
            'link'       => $this->config->appUrl . $row['link_path'],
        ];
    }

    private function budgetSpent(): bool
    {
        return $this->sent + $this->failed >= $this->maxPerRun || !$this->hasVolumeHeadroom();
    }

    private function hasVolumeHeadroom(): bool
    {
        return $this->rateLimiter->peek(MailVolumeMonitor::HOURLY_BUCKET, MailVolumeMonitor::HOURLY_WINDOW_SECONDS) < $this->hourlyVolumeCeiling
            && $this->rateLimiter->peek(MailVolumeMonitor::DAILY_BUCKET, MailVolumeMonitor::DAILY_WINDOW_SECONDS) < $this->dailyVolumeCeiling;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function rowIds(array $rows): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }
}
