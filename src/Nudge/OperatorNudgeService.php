<?php

declare(strict_types=1);

namespace Votepit\Nudge;

use Votepit\Config;
use Votepit\Persistence\OperatorNudgeRepository;

/**
 * Operator nudges that exist on every plan (owner decisions 4 and 5):
 *
 *  - Onboarding: at most two mails in the first 14 days after an account was
 *    created. Day 3 while no public idea exists ("share your board"); day 7
 *    while no vote from a non-member exists ("get a vote from outside").
 *    Never two onboarding mails on the same day, and a step that is done
 *    stops being nudged.
 *  - Quiet board: a board with earlier activity (idea, vote or comment) but
 *    none for 21 days gets one tip mail. The nudge re-arms once activity
 *    returns, so each quiet period gets at most one mail.
 *
 * The plan-limit heads-up needs the Cloud plan table and lives in the Cloud
 * layer (PlanLimitNudgeService), on the same dispatcher and sent-log.
 *
 * Run hourly by the cron manager (bin/send-operator-nudges.php) at the lowest
 * mail priority: the dispatcher backs off at its own (lower) volume ceilings,
 * and every run sends at most $maxPerRun mails. Idempotent by construction:
 * each (recipient, account, kind, scope) is claimed once, see the dispatcher.
 */
final readonly class OperatorNudgeService
{
    private const FIRST_IDEA_AFTER_DAYS = 3;
    private const FIRST_VOTE_AFTER_DAYS = 7;
    private const ONBOARDING_WINDOW_DAYS = 14;
    private const QUIET_AFTER_DAYS = 21;

    public function __construct(
        private OperatorNudgeRepository $nudges,
        private OperatorNudgeDispatcher $dispatcher,
        private Config $config,
        private int $maxPerRun = 20,
    ) {}

    public function run(\DateTimeImmutable $now): NudgeRunResult
    {
        // Kill switch (Config::$participantMailsEnabled): nothing is sent while off.
        if (!$this->config->participantMailsEnabled) {
            return new NudgeRunResult();
        }

        $this->nudges->purgeOnboardingBefore($now->modify('-30 days'));

        $total = $this->onboarding($now, new NudgeRunResult());

        return $this->quietBoards($now, $total);
    }

    private function onboarding(\DateTimeImmutable $now, NudgeRunResult $total): NudgeRunResult
    {
        $startOfToday = $now->setTime(0, 0);
        $firstIdeaDue = $now->modify('-' . self::FIRST_IDEA_AFTER_DAYS . ' days');
        $firstVoteDue = $now->modify('-' . self::FIRST_VOTE_AFTER_DAYS . ' days');

        $accounts = $this->nudges->onboardingAccountsCreatedBetween(
            $now->modify('-' . self::ONBOARDING_WINDOW_DAYS . ' days'),
            $now,
        );

        foreach ($accounts as $account) {
            $createdAt = new \DateTimeImmutable($account['created_at']);
            $accountId = $account['id'];

            // Day 3 first; day 7 only if no onboarding mail went out today and
            // the day-3 mail did not just go out. One onboarding mail per account per run.
            $candidates = [];
            if (!$account['has_public_idea'] && $createdAt <= $firstIdeaDue) {
                $candidates[] = 'day3';
            }
            if (!$account['has_outside_vote'] && $createdAt <= $firstVoteDue) {
                $candidates[] = 'day7';
            }

            if ($account['board_name'] === null || $account['board_slug'] === null) {
                continue; // no live board to talk about yet
            }

            $boardUrl = $this->boardUrl($account['slug'], $account['board_slug']);
            $boardName = $account['board_name'];
            $accountName = $account['name'];

            foreach ($candidates as $scope) {
                if ($scope === 'day7' && $this->nudges->kindSentSince($accountId, OperatorNudgeKind::Onboarding, $startOfToday)) {
                    break;
                }

                $budget = $this->maxPerRun - $total->attempts();
                if ($budget <= 0 || !$this->dispatcher->hasVolumeHeadroom()) {
                    return $total->plus(new NudgeRunResult(budgetExhausted: true));
                }

                $compose = $scope === 'day3'
                    ? static fn (string $language, string $unsubscribeUrl, string $profileUrl): array => OperatorNudgeMail::onboardingFirstIdea(
                        $language,
                        $boardName,
                        $boardUrl,
                        $accountName,
                        $unsubscribeUrl,
                        $profileUrl,
                    )
                    : static fn (string $language, string $unsubscribeUrl, string $profileUrl): array => OperatorNudgeMail::onboardingFirstVote(
                        $language,
                        $boardName,
                        $boardUrl,
                        $accountName,
                        $unsubscribeUrl,
                        $profileUrl,
                    );

                $result = $this->dispatcher->dispatch($accountId, OperatorNudgeKind::Onboarding, $scope, $now, $budget, $compose);
                $total  = $total->plus($result);
                if ($result->sent > 0) {
                    break;
                }
            }
        }

        return $total;
    }

    private function quietBoards(\DateTimeImmutable $now, NudgeRunResult $total): NudgeRunResult
    {
        $cutoff = $now->modify('-' . self::QUIET_AFTER_DAYS . ' days')->format('Y-m-d H:i:s');

        foreach ($this->nudges->boardActivity() as $board) {
            if ($board['last_activity'] === null) {
                continue; // never active: not a quiet board, just an empty one
            }

            $scope = 'board:' . $board['board_id'];
            if ($board['last_activity'] >= $cutoff) {
                // Activity is back: re-arm the nudge for the next quiet period.
                if ($this->nudges->scopeSent($board['account_id'], OperatorNudgeKind::QuietBoard, $scope)) {
                    $this->nudges->resetScope($board['account_id'], OperatorNudgeKind::QuietBoard, $scope);
                }

                continue;
            }

            $budget = $this->maxPerRun - $total->attempts();
            if ($budget <= 0 || !$this->dispatcher->hasVolumeHeadroom()) {
                return $total->plus(new NudgeRunResult(budgetExhausted: true));
            }

            $boardUrl = $this->boardUrl($board['account_slug'], $board['board_slug']);
            $boardName = $board['board_name'];
            $accountName = $board['account_name'];
            $compose = static fn (string $language, string $unsubscribeUrl, string $profileUrl): array => OperatorNudgeMail::quietBoard(
                $language,
                $boardName,
                $boardUrl,
                $accountName,
                $unsubscribeUrl,
                $profileUrl,
            );

            $total = $total->plus($this->dispatcher->dispatch($board['account_id'], OperatorNudgeKind::QuietBoard, $scope, $now, $budget, $compose));
        }

        return $total;
    }

    /**
     * "A newer Community release is available" to the owner of each unlocked
     * account. Scope 'version:<latest>' makes it once per release: a second
     * run for the same version finds every claim and sends nothing. A failed
     * send releases its claim, so the next daily run retries it.
     */
    public function updateAvailable(\DateTimeImmutable $now, string $installedVersion, string $latestVersion): NudgeRunResult
    {
        if (!$this->config->participantMailsEnabled) {
            return new NudgeRunResult();
        }

        $total = new NudgeRunResult();
        foreach ($this->nudges->unlockedAccounts() as $account) {
            $budget = $this->maxPerRun - $total->attempts();
            if ($budget <= 0 || !$this->dispatcher->hasVolumeHeadroom()) {
                return $total->plus(new NudgeRunResult(budgetExhausted: true));
            }

            $accountName = $account['name'];
            $compose = static fn (string $language, string $unsubscribeUrl, string $profileUrl): array => OperatorNudgeMail::updateAvailable(
                $language,
                $installedVersion,
                $latestVersion,
                $accountName,
                $unsubscribeUrl,
                $profileUrl,
            );

            $total = $total->plus($this->dispatcher->dispatch(
                $account['id'],
                OperatorNudgeKind::UpdateAvailable,
                'version:' . $latestVersion,
                $now,
                $budget,
                $compose,
            ));
        }

        return $total;
    }

    private function boardUrl(string $accountSlug, string $boardSlug): string
    {
        $prefix = $this->config->routingMode === 'cloud' ? '/' . rawurlencode($accountSlug) : '';

        return $this->config->appUrl . $prefix . '/' . rawurlencode($boardSlug);
    }
}
