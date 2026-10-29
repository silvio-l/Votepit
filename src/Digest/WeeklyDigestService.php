<?php

declare(strict_types=1);

namespace Votepit\Digest;

use Votepit\Config;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailVolumeMonitor;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\DigestUnsubscribeToken;
use Votepit\Security\RateLimiter;

/**
 * Weekly owner digest (competitor-parity issue 14) — one mail per eligible
 * user (see UserRepository::findDueDigestRecipients()) summarising every
 * board of every account they own (or, opted in, administer): new ideas,
 * top / most rejected / most polarising ideas, and the board's weekly
 * figures + consensus average (IdeaRepository::boardStats(), the same
 * numbers as the board's "This week" panel).
 *
 * Mail is a critical, rate-limited path (shared-hosting send limit,
 * MailVolumeMonitor): this job is the lowest-priority sender and backs off
 * first. Run hourly by the cron manager, each run
 *  - sends at most $maxPerRun mails (attempts, incl. failures), and
 *  - stops as soon as the shared hourly/daily volume buckets
 *    (MailVolumeMonitor) reach $hourlyVolumeCeiling/$dailyVolumeCeiling —
 *    set well below the monitor's own alert thresholds, so magic-link mail
 *    always keeps its headroom.
 * Users not reached are simply picked up by the next run (oldest-handled
 * first); digest_processed_at is only set on a successful send or an empty
 * week, so a failed send is retried and nobody gets two digests a week.
 *
 * Skip empty weeks: boards without a new idea or vote in the window are
 * left out; a user with no active board gets no mail at all. The mail leads
 * with the week's highlights and one next step (ideas still waiting for an
 * answer, see IdeaRepository::countAwaitingAnswer(), the same predicate as the
 * operator worklist); the board figures follow
 * as detail.
 */
final readonly class WeeklyDigestService
{
    /** Window the digest covers. */
    private const WINDOW = '-7 days';

    /** A user is due again one week after the last handling, minus one cron tick of slack. */
    private const DUE_AFTER = '-167 hours';

    /** Upper bound of candidate rows loaded per run (empty weeks don't consume mail budget). */
    private const CANDIDATES_PER_RUN = 500;

    public function __construct(
        private UserRepository $users,
        private AccountMemberRepository $members,
        private BoardRepository $boards,
        private IdeaRepository $ideas,
        private Mailer $mailer,
        private RateLimiter $rateLimiter,
        private DigestUnsubscribeToken $unsubscribeTokens,
        private Config $config,
        private int $maxPerRun = 30,
        private int $hourlyVolumeCeiling = 40,
        private int $dailyVolumeCeiling = 250,
    ) {}

    public function run(\DateTimeImmutable $now): DigestRunResult
    {
        $since     = $now->modify(self::WINDOW);
        $sent      = 0;
        $skipped   = 0;
        $failed    = 0;
        $exhausted = false;
        $lastError = null;
        /** @var array<int, array{stats: array{weekly_votes: int, weekly_new_ideas: int, avg_consensus: int, total_ideas: int}, highlights: array<string, mixed>, awaiting_answer: int}|null> $boardCache */
        $boardCache = [];

        foreach ($this->users->findDueDigestRecipients($now->modify(self::DUE_AFTER), self::CANDIDATES_PER_RUN) as $recipient) {
            if ($sent + $failed >= $this->maxPerRun || !$this->hasVolumeHeadroom()) {
                $exhausted = true;
                break;
            }

            $sections = $this->sectionsFor($recipient['id'], $recipient['explicit_opt_in'], $since, $now, $boardCache);
            if ($sections === []) {
                $this->users->markDigestProcessed($recipient['id'], $now);
                $skipped++;
                continue;
            }

            $mail = WeeklyDigestMail::render(
                $recipient['language'],
                $sections,
                $this->config->appUrl . '/',
                $this->config->appUrl . '/profile',
                $this->config->appUrl . '/digest/unsubscribe?token=' . $this->unsubscribeTokens->sign($recipient['id']),
                $since,
                $now->modify('-1 day'),
            );

            try {
                $this->mailer->send($recipient['email'], $mail['subject'], $mail['text'], $mail['html'], $mail['image']);
            } catch (\Throwable $e) {
                $failed++;
                $lastError = $e->getMessage();
                continue;
            }

            $this->users->markDigestProcessed($recipient['id'], $now);
            $sent++;
        }

        return new DigestRunResult($sent, $skipped, $failed, $exhausted, $lastError);
    }

    private function hasVolumeHeadroom(): bool
    {
        return $this->rateLimiter->peek(MailVolumeMonitor::HOURLY_BUCKET, MailVolumeMonitor::HOURLY_WINDOW_SECONDS) < $this->hourlyVolumeCeiling
            && $this->rateLimiter->peek(MailVolumeMonitor::DAILY_BUCKET, MailVolumeMonitor::DAILY_WINDOW_SECONDS) < $this->dailyVolumeCeiling;
    }

    /**
     * Active-board sections of every account this user's digest covers.
     * Board figures are cached per run — co-owners of one account share them.
     *
     * @param array<int, mixed> $boardCache
     * @return list<array{account_name: string, board_name: string, board_url: string, admin_url: string, stats: array{weekly_votes: int, weekly_new_ideas: int, avg_consensus: int, total_ideas: int}, highlights: array{new_ideas: list<array{id: int, title: string}>, top: list<array{id: int, title: string, up_count: int, down_count: int}>, most_rejected: list<array{id: int, title: string, up_count: int, down_count: int}>, most_polarising: list<array{id: int, title: string, up_count: int, down_count: int}>}, awaiting_answer: int}>
     */
    private function sectionsFor(int $userId, bool $includeAdminAccounts, \DateTimeImmutable $since, \DateTimeImmutable $now, array &$boardCache): array
    {
        $sections = [];
        foreach ($this->members->digestAccountsFor($userId, $includeAdminAccounts) as $account) {
            foreach ($this->boards->listForAccount($account['id']) as $board) {
                if (!array_key_exists($board['id'], $boardCache)) {
                    $stats = $this->ideas->boardStats($board['id'], $since);
                    $boardCache[$board['id']] = ($stats['weekly_new_ideas'] === 0 && $stats['weekly_votes'] === 0)
                        ? null
                        : [
                            'stats'           => $stats,
                            'highlights'      => $this->ideas->digestHighlights($board['id'], $since),
                            // Ideas that wait for an answer (shared predicate with the worklist, P-06).
                            'awaiting_answer' => $this->ideas->countAwaitingAnswer($board['id'], $now),
                        ];
                }

                $activity = $boardCache[$board['id']];
                if ($activity === null) {
                    continue;
                }

                $sections[] = [
                    'account_name'    => $account['name'],
                    'board_name'      => $board['name'],
                    'board_url'       => $this->boardUrl($account['slug'], $board['slug']),
                    'admin_url'       => $this->adminBoardUrl($account['slug'], $board['slug']),
                    'stats'           => $activity['stats'],
                    'highlights'      => $activity['highlights'],
                    'awaiting_answer' => $activity['awaiting_answer'],
                ];
            }
        }

        return $sections;
    }

    private function boardUrl(string $accountSlug, string $boardSlug): string
    {
        return $this->accountPrefixed($accountSlug) . '/' . rawurlencode($boardSlug);
    }

    /** The board's admin page (owner worklist: answer ideas, set statuses). */
    private function adminBoardUrl(string $accountSlug, string $boardSlug): string
    {
        return $this->accountPrefixed($accountSlug) . '/admin/boards/' . rawurlencode($boardSlug);
    }

    private function accountPrefixed(string $accountSlug): string
    {
        $prefix = $this->config->routingMode === 'cloud' ? '/' . rawurlencode($accountSlug) : '';

        return $this->config->appUrl . $prefix;
    }
}
