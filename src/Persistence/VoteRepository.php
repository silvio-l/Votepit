<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Vote-Persistenz (Sprint 4, arch.md §2 — Persistence-Layer; ADR-3-Amendment).
 *
 * Einzige DB-Seam fürs Voting. Prepared-Statements-only via DBAL, kein
 * Query-String-Concat. Board-scoped: die `score_cache`-Pflege trägt board_id
 * als Parameter (Defense in Depth gegen Cross-Board-Leak; die Action garantiert
 * Board-Zugehörigkeit zusätzlich über IdeaRepository::findInBoard).
 *
 * ADR-3-Amendment (Sprint 4): `ideas.score_cache` wird APP-seitig in DERSELBEN
 * Transaktion wie die `votes`-Mutation gepflegt — nicht mehr per DB-Trigger.
 * Damit ist die tragende Invariante `score_cache == SUM(votes.value)` an der
 * portablen SQLite-Test-Naht verifizierbar. Bei einem Fehler rollen votes-Mutate
 * UND score-Delta gemeinsam zurück (fail-secure).
 */
final readonly class VoteRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Idempotenter Vote-Kern in EINER Transaktion. $value MUSS bereits auf
     * {−1,+1} validiert sein (VoteAction). Deckt alle drei Fälle ab:
     *   - keine Stimme           → INSERT (value)         + score_cache += value
     *   - andere Stimme vorhanden → UPDATE auf value       + score_cache += (value − alt)
     *   - gleiche Stimme vorhanden → DELETE (Rücknahme)     + score_cache −= value
     *
     * Pro (Idee, Nutzer) existiert danach genau eine oder keine Zeile — nie zwei
     * (Service-Logik + DB-UNIQUE als Backstop).
     *
     * @return array{my_vote: 'up'|'down'|'none', score: int} Resultierender Zustand.
     * @throws DbalException
     */
    public function cast(int $boardId, int $ideaId, int $userId, int $value): array
    {
        /** @var array{my_vote: 'up'|'down'|'none', score: int} $result */
        $result = $this->conn->transactional(
            function (Connection $conn) use ($boardId, $ideaId, $userId, $value): array {
                $existing = $conn->fetchOne(
                    'SELECT value FROM votes WHERE idea_id = :idea AND user_id = :user',
                    ['idea' => $ideaId, 'user' => $userId],
                );

                if ($existing === false) {
                    $conn->executeStatement(
                        'INSERT INTO votes (idea_id, user_id, value, created_at)
                         VALUES (:idea, :user, :value, CURRENT_TIMESTAMP)',
                        ['idea' => $ideaId, 'user' => $userId, 'value' => $value],
                    );
                    $delta    = $value;
                    $newValue = $value;
                } elseif ((int) $existing === $value) {
                    $conn->executeStatement(
                        'DELETE FROM votes WHERE idea_id = :idea AND user_id = :user',
                        ['idea' => $ideaId, 'user' => $userId],
                    );
                    $delta    = -$value;
                    $newValue = 0;
                } else {
                    $old = (int) $existing;
                    $conn->executeStatement(
                        'UPDATE votes SET value = :value WHERE idea_id = :idea AND user_id = :user',
                        ['value' => $value, 'idea' => $ideaId, 'user' => $userId],
                    );
                    $delta    = $value - $old;
                    $newValue = $value;
                }

                // Score-Delta board-scoped in derselben Transaktion pflegen.
                $conn->executeStatement(
                    'UPDATE ideas SET score_cache = score_cache + :delta
                     WHERE id = :idea AND board_id = :board',
                    ['delta' => $delta, 'idea' => $ideaId, 'board' => $boardId],
                );

                $score = $conn->fetchOne(
                    'SELECT score_cache FROM ideas WHERE id = :idea AND board_id = :board',
                    ['idea' => $ideaId, 'board' => $boardId],
                );

                return [
                    'my_vote' => $newValue > 0 ? 'up' : ($newValue < 0 ? 'down' : 'none'),
                    'score'   => is_numeric($score) ? (int) $score : 0,
                ];
            },
        );

        return $result;
    }

    /**
     * Aktueller Vote-Zustand des Nutzers auf einer Idee (für Detail-/Listen-Anzeige).
     *
     * @return 'up'|'down'|'none'
     * @throws DbalException
     */
    public function findUserVote(int $ideaId, int $userId): string
    {
        $value = $this->conn->fetchOne(
            'SELECT value FROM votes WHERE idea_id = :idea AND user_id = :user',
            ['idea' => $ideaId, 'user' => $userId],
        );

        if ($value === false) {
            return 'none';
        }

        return (int) $value > 0 ? 'up' : 'down';
    }
}
