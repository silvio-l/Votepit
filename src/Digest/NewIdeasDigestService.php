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
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaFollowRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ParticipantMailRepository;
use Votepit\Security\RateLimiter;

/**
 * Opt-in weekly "new ideas" digest (close-the-loop re-engagement F).
 *
 * A user with an explicit weekly new_ideas cadence gets one mail per week
 * listing new published ideas on PUBLIC boards they have participated in
 * (ParticipantMailRepository::newIdeasSubscriberIds + IdeaFollowRepository::
 * participatedBoardIds). Private, unlisted, locked or compliance-frozen boards
 * are never included.
 *
 * State: participant_new_ideas_state holds the per-board "checked until"
 * timestamp. It advances only after a successful send for boards that were
 * included, and for boards that had nothing new. A failed send keeps the old
 * timestamp, so the next run covers the same ideas again.
 *
 * Idempotency: the weekly claim uses the recipient key "n:<id>", separate from
 * the participant digest's "u:<id>", so the two mails never block each other.
 */
final class NewIdeasDigestService
{
    private const CANDIDATES_PER_RUN = 200;
    private const IDEAS_PER_BOARD    = 10;
    private const FIRST_WINDOW       = '-7 days';

    private int $sent      = 0;
    private int $skipped   = 0;
    private int $failed    = 0;
    private bool $exhausted = false;
    private ?string $lastError = null;

    public function __construct(
        private readonly ParticipantMailRepository $mail,
        private readonly IdeaFollowRepository $follows,
        private readonly IdeaRepository $ideas,
        private readonly BoardRepository $boards,
        private readonly AccountRepository $accounts,
        private readonly BoardMailBranding $branding,
        private readonly Mailer $mailer,
        private readonly RateLimiter $rateLimiter,
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

        $this->sent      = 0;
        $this->skipped   = 0;
        $this->failed    = 0;
        $this->exhausted = false;
        $this->lastError = null;

        foreach ($this->mail->newIdeasSubscriberIds(self::CANDIDATES_PER_RUN) as $userId) {
            if ($this->sent + $this->failed >= $this->maxPerRun || !$this->hasVolumeHeadroom()) {
                $this->exhausted = true;
                break;
            }

            $this->deliver($userId, $now);
        }

        return new DigestRunResult($this->sent, $this->skipped, $this->failed, $this->exhausted, $this->lastError);
    }

    private function deliver(int $userId, \DateTimeImmutable $now): void
    {
        $recipient = $this->mail->userRecipient($userId);
        if ($recipient === null || !$recipient['usable'] || $recipient['email'] === null) {
            $this->skipped++;

            return;
        }

        $sections = [];
        $checked  = [];
        foreach ($this->follows->participatedBoardIds($userId) as $boardId) {
            $board = $this->boards->findByIdAny($boardId);
            if ($board === null || $board['visibility'] !== 'public' || $board['locked_at'] !== null || $board['compliance_frozen_at'] !== null) {
                continue;
            }

            $since      = $this->mail->newIdeasCheckedAt($userId, $boardId) ?? $now->modify(self::FIRST_WINDOW);
            $highlights = $this->ideas->digestHighlights($boardId, $since, self::IDEAS_PER_BOARD);
            $newIdeas   = $highlights['new_ideas'];

            if ($newIdeas === []) {
                // Nothing new in the window: advancing the marker loses nothing.
                $this->mail->setNewIdeasCheckedAt($userId, $boardId, $now);
                continue;
            }

            $account    = $this->accounts->findById((int) $board['account_id']);
            $sections[] = [
                'board_id'   => $boardId,
                'board_name' => (string) $board['name'],
                'board_url'  => $this->boardUrl((string) ($account['slug'] ?? ''), (string) $board['slug']),
                'ideas'      => $newIdeas,
            ];
            $checked[] = $boardId;
        }

        if ($sections === []) {
            $this->skipped++;

            return;
        }

        $key    = 'n:' . $userId;
        $period = ParticipantMailPolicy::periodKey(ParticipantMailCategory::CADENCE_WEEKLY, $now);
        if (!$this->mail->claim($key, $period, $now)) {
            return;
        }

        $single = count($sections) === 1 ? $this->branding->forBoard($sections[0]['board_id']) : null;

        try {
            $mail = NewIdeasDigestMail::render(
                $recipient['language'],
                array_map(
                    static fn (array $s): array => ['board_name' => $s['board_name'], 'board_url' => $s['board_url'], 'ideas' => $s['ideas']],
                    $sections,
                ),
                $this->config->appUrl,
                $this->router->preferencesUrl(),
                $this->router->unsubscribePageUrl($userId, null, ParticipantMailCategory::NEW_IDEAS),
                $single !== null ? $single['brand'] : null,
            );

            $this->mailer->send(
                $recipient['email'],
                $mail['subject'],
                $mail['text'],
                $mail['html'],
                $mail['image'],
                $single !== null ? $single['sender_name'] : null,
                $this->router->unsubscribeHeaders($userId, null, ParticipantMailCategory::NEW_IDEAS),
            );
        } catch (\Throwable $e) {
            $this->mail->releaseClaim($key, $period);
            $this->failed++;
            $this->lastError = $e->getMessage();

            return;
        }

        foreach ($checked as $boardId) {
            $this->mail->setNewIdeasCheckedAt($userId, $boardId, $now);
        }
        $this->sent++;
    }

    private function boardUrl(string $accountSlug, string $boardSlug): string
    {
        $prefix = $this->config->routingMode === 'cloud' ? '/' . rawurlencode($accountSlug) : '';

        return $this->config->appUrl . $prefix . '/' . rawurlencode($boardSlug);
    }

    private function hasVolumeHeadroom(): bool
    {
        return $this->rateLimiter->peek(MailVolumeMonitor::HOURLY_BUCKET, MailVolumeMonitor::HOURLY_WINDOW_SECONDS) < $this->hourlyVolumeCeiling
            && $this->rateLimiter->peek(MailVolumeMonitor::DAILY_BUCKET, MailVolumeMonitor::DAILY_WINDOW_SECONDS) < $this->dailyVolumeCeiling;
    }
}
