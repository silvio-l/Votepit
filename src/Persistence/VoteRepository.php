<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Vote persistence (arch.md §2 — persistence layer; ADR-3 amendment).
 *
 * The single DB seam for voting. Prepared-statements-only via DBAL, no
 * query-string concatenation. Board-scoped: `score_cache` maintenance carries board_id
 * as a parameter (defense in depth against cross-board leak; the action additionally
 * guarantees board membership via IdeaRepository::findInBoard).
 *
 * ADR-3 amendment: `ideas.score_cache` is maintained APP-side within the SAME
 * transaction as the `votes` mutation — no longer via a DB trigger.
 * This makes the load-bearing invariant `score_cache == SUM(votes.value)` verifiable
 * at the portable SQLite test seam. On an error, the votes mutation
 * AND the score delta roll back together (fail-secure).
 */
final readonly class VoteRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Idempotent vote core in ONE transaction. $value MUST already be
     * validated as {−1,+1} (VoteAction). Covers all three cases:
     *   - no vote yet             → INSERT (value)         + score_cache += value
     *   - other vote exists       → UPDATE to value         + score_cache += (value − old)
     *   - same vote exists        → DELETE (retraction)      + score_cache −= value
     *
     * Exactly one or no row exists per (idea, user) afterward — never two
     * (service logic + DB UNIQUE as a backstop).
     *
     * up_count/down_count are read within the same transaction (no re-query
     * outside) — for the JSON path, without a second DB query in the action.
     *
     * @return array{my_vote: 'up'|'down'|'none', score: int, up_count: int, down_count: int} Resulting state.
     * @throws DbalException
     */
    public function cast(int $boardId, int $ideaId, int $userId, int $value): array
    {
        /** @var array{my_vote: 'up'|'down'|'none', score: int, up_count: int, down_count: int} $result */
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

                // Maintain the score delta board-scoped within the same transaction.
                $conn->executeStatement(
                    'UPDATE ideas SET score_cache = score_cache + :delta
                     WHERE id = :idea AND board_id = :board',
                    ['delta' => $delta, 'idea' => $ideaId, 'board' => $boardId],
                );

                $score = $conn->fetchOne(
                    'SELECT score_cache FROM ideas WHERE id = :idea AND board_id = :board',
                    ['idea' => $ideaId, 'board' => $boardId],
                );

                // up_count / down_count within the same transaction — no re-query outside.
                $upCount = $conn->fetchOne(
                    'SELECT COUNT(*) FROM votes WHERE idea_id = :idea AND value > 0',
                    ['idea' => $ideaId],
                );
                $downCount = $conn->fetchOne(
                    'SELECT COUNT(*) FROM votes WHERE idea_id = :idea AND value < 0',
                    ['idea' => $ideaId],
                );

                return [
                    'my_vote'    => $newValue > 0 ? 'up' : ($newValue < 0 ? 'down' : 'none'),
                    'score'      => is_numeric($score) ? (int) $score : 0,
                    'up_count'   => is_numeric($upCount) ? (int) $upCount : 0,
                    'down_count' => is_numeric($downCount) ? (int) $downCount : 0,
                ];
            },
        );

        return $result;
    }

    /**
     * The user's current vote state on an idea (for detail/list display).
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

    /**
     * Lists ALL votes of an account, across all boards/ideas (customer
     * self-export). Account-scoped via a double JOIN
     * (votes → ideas → boards), since votes itself carries neither board_id nor
     * account_id — a vote of a foreign account
     * structurally never shows up here.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listForAccount(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT v.id, v.idea_id, v.user_id, v.value, v.created_at
             FROM votes v
             INNER JOIN ideas i ON i.id = v.idea_id
             INNER JOIN boards b ON b.id = i.board_id
             WHERE b.account_id = :account_id
             ORDER BY v.id ASC',
            ['account_id' => $accountId],
        );

        return $rows;
    }
}
