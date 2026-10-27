<?php

declare(strict_types=1);

namespace Votepit\IssueTracker;

use Votepit\Domain\IdeaStatusChanger;
use Votepit\Domain\StatusService;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\IdeaIssueLinkRepository;
use Votepit\Persistence\IdeaRepository;

/**
 * Inbound status sync: "issue completed in Linear/GitHub" → the linked
 * idea moves to the board's done status.
 *
 * Strictly account-scoped: the link, the idea's board and the idea are all
 * resolved inside the account of the (signature-verified) connection, so a
 * delivery for account A can never touch an idea of account B.
 *
 * Owner-defined statuses (board_statuses) are respected: the target is the
 * system `done` status if it is assignable, otherwise the first other
 * visible status in category `done` that the idea's current status may
 * transition to. If none qualifies (all hidden, or the transition is not
 * allowed, e.g. from `declined`) the idea is left unchanged. An idea that
 * is already in category `done` or `archived`, or on a frozen board, is
 * left unchanged too.
 *
 * Exempt from the board's `status_reason_required` switch (migration 0115):
 * this is an automated transition triggered by the tracker, not a human
 * decision, so there is no reason text to require. It therefore bypasses
 * IdeaStatusAction and calls IdeaStatusChanger directly with no decision.
 */
final readonly class IssueStatusSync
{
    public const UPDATED   = 'updated';
    public const UNCHANGED = 'unchanged';
    public const UNLINKED  = 'unlinked';

    public function __construct(
        private IdeaIssueLinkRepository $links,
        private BoardRepository $boards,
        private IdeaRepository $ideas,
        private BoardStatusRepository $statuses,
        private IdeaStatusChanger $changer,
    ) {}

    public function markCompleted(int $accountId, IssueTrackerProvider $provider, string $externalId): string
    {
        $link = $this->links->findForSync($accountId, $provider->value, $externalId);
        if ($link === null) {
            return self::UNLINKED;
        }

        // findBySlugForAccount (not findByIdForAccount): it carries
        // statuses_customized, which registryFor() needs.
        $board = $this->boards->findBySlugForAccount($link['board_slug'], $accountId);
        $idea  = is_array($board) ? $this->ideas->findInBoard((int) $board['id'], $link['idea_id']) : null;
        if (!is_array($board) || !is_array($idea)) {
            return self::UNLINKED;
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return self::UNCHANGED;
        }

        $registry = $this->statuses->registryFor($board);
        $from     = (string) ($idea['status_key'] ?? 'open');
        // Already done — or archived, i.e. deliberately retired after done:
        // a (re-)closed issue never revives an archived idea.
        if (in_array($registry->categoryOf($from), [StatusService::STATUS_DONE, 'archived'], true)) {
            return self::UNCHANGED;
        }

        $candidates = $registry->keysInCategory(StatusService::STATUS_DONE);
        usort($candidates, static fn (string $a, string $b): int => ($b === StatusService::STATUS_DONE) <=> ($a === StatusService::STATUS_DONE));
        $target = null;
        foreach ($candidates as $key) {
            // canTransition() also refuses hidden targets.
            if ($registry->canTransition($from, $key)) {
                $target = $key;
                break;
            }
        }
        if ($target === null) {
            return self::UNCHANGED;
        }

        $this->changer->apply(
            $accountId,
            (int) $board['id'],
            (string) $board['slug'],
            $idea,
            $registry,
            $target,
            0,
            ['source' => 'issue_tracker', 'provider' => $provider->value],
        );

        return self::UPDATED;
    }
}
