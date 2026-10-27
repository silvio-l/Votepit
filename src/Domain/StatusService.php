<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Single source of truth for allowed idea status values and transitions.
 *
 * Status set: open · planned · in_progress · done · declined · archived.
 * Transitions:
 *   open        → planned | in_progress | done | declined
 *   planned     → in_progress | done | declined | open
 *   in_progress → done | declined | planned
 *   done        → in_progress (reopen) | declined | archived
 *   declined    → open
 *   archived    → done (unarchive)
 *
 * `archived` is reached only from `done` — it is the resource-conscious
 * "put a shipped idea away" state (excluded from the default board list
 * and from the roadmap/Gantt groups, see IdeaRepository::listByBoard()
 * and ::roadmapByBoard()), not a replacement for `declined`.
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
    public const VALID_STATUSES = ['open', 'planned', 'in_progress', 'done', 'declined', 'archived'];

    /**
     * The "shipped" status — named constant so callers outside this class
     * (e.g. PublicProfileAction's "ideas shipped" contribution stat) never
     * hardcode the literal string.
     */
    public const STATUS_DONE = 'done';

    /**
     * Allowed target states per source state.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'open'        => ['planned', 'in_progress', 'done', 'declined'],
        'planned'     => ['in_progress', 'done', 'declined', 'open'],
        'in_progress' => ['done', 'declined', 'planned'],
        'done'        => ['in_progress', 'declined', 'archived'],
        'declined'    => ['open'],
        'archived'    => ['done'],
    ];

    /**
     * Allowed target states of $status (empty for an unknown status). Also
     * the CATEGORY transition graph of BoardStatuses (broaden-use-cases
     * issue 03): the six statuses are exactly the six category slugs.
     *
     * @return list<string>
     */
    public function targetsFrom(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

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
