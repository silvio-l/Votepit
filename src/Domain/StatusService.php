<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Single source of truth for allowed idea status values and transitions.
 *
 * Status set: open · planned · in_progress · done · declined.
 * Transitions:
 *   open        → planned | in_progress | done | declined
 *   planned     → in_progress | done | declined | open
 *   in_progress → done | declined | planned
 *   done        → in_progress (reopen) | declined
 *   declined    → open
 *
 * Self→self counts as an idempotent no-op (canTransition returns true,
 * the calling action decides whether a DB write is needed).
 *
 * This service is stateless and has no dependencies; it is instantiated
 * directly by both IdeaStatusAction and unit tests.
 */
final readonly class StatusService
{
    /** @var list<string> */
    public const VALID_STATUSES = ['open', 'planned', 'in_progress', 'done', 'declined'];

    /**
     * Allowed target states per source state.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'open'        => ['planned', 'in_progress', 'done', 'declined'],
        'planned'     => ['in_progress', 'done', 'declined', 'open'],
        'in_progress' => ['done', 'declined', 'planned'],
        'done'        => ['in_progress', 'declined'],
        'declined'    => ['open'],
    ];

    /** Checks whether $status is a valid idea status. */
    public function isValidStatus(string $status): bool
    {
        return in_array($status, self::VALID_STATUSES, true);
    }

    /**
     * Checks whether the transition $from → $to is allowed.
     *
     * Self→self ($from === $to) counts as allowed (idempotent no-op).
     * Invalid status values (outside VALID_STATUSES) → false.
     */
    public function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true; // idempotent no-op
        }

        if (!$this->isValidStatus($from) || !$this->isValidStatus($to)) {
            return false;
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
