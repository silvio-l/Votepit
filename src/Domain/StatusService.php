<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Einzige Quelle der Wahrheit für erlaubte Idea-Status-Werte und Transitions.
 *
 * Status-Menge: open · planned · in_progress · done · declined.
 * Transitions (PRD §3):
 *   open        → planned | in_progress | done | declined
 *   planned     → in_progress | done | declined | open
 *   in_progress → done | declined | planned
 *   done        → in_progress (Reopen) | declined
 *   declined    → open
 *
 * Selbst→Selbst gilt als idempotenter No-op (canTransition gibt true zurück,
 * die aufrufende Action entscheidet, ob ein DB-Schreibvorgang nötig ist).
 *
 * Dieser Service ist zustandslos und hat keine Abhängigkeiten; er wird sowohl
 * von IdeaStatusAction als auch von Unit-Tests direkt instanziert.
 */
final readonly class StatusService
{
    /** @var list<string> */
    public const VALID_STATUSES = ['open', 'planned', 'in_progress', 'done', 'declined'];

    /**
     * Erlaubte Zielzustände je Ausgangszustand.
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

    /** Prüft, ob $status ein gültiger Idea-Status ist. */
    public function isValidStatus(string $status): bool
    {
        return in_array($status, self::VALID_STATUSES, true);
    }

    /**
     * Prüft, ob der Übergang $from → $to erlaubt ist.
     *
     * Selbst→Selbst ($from === $to) gilt als erlaubt (idempotenter No-op).
     * Ungültige Status-Werte (außerhalb VALID_STATUSES) → false.
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
