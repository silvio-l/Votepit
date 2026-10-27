<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * P-03 down-vote reasons (migrations/0099_add_vote_reasons.sql).
 *
 * One row per vote at most, keyed by votes.id. Every query reaches the idea
 * through votes.idea_id; no identity column is ever read or returned here.
 * All statements use bound parameters.
 */
final readonly class VoteReasonRepository
{
    /** Most free-text notes the operator view returns (ordered by reason and text, never by time). */
    public const NOTES_LIMIT = 50;

    public function __construct(private Connection $conn) {}

    /**
     * Sets or replaces the reason of one down vote.
     *
     * @throws DbalException
     */
    public function upsert(int $voteId, string $reason, ?string $note): void
    {
        $exists = $this->conn->fetchOne('SELECT 1 FROM vote_reasons WHERE vote_id = :vote', ['vote' => $voteId]);
        if ($exists !== false) {
            $this->conn->executeStatement(
                'UPDATE vote_reasons SET reason = :reason, note = :note WHERE vote_id = :vote',
                ['reason' => $reason, 'note' => $note, 'vote' => $voteId],
            );

            return;
        }

        $this->conn->executeStatement(
            'INSERT INTO vote_reasons (vote_id, reason, note) VALUES (:vote, :reason, :note)',
            ['vote' => $voteId, 'reason' => $reason, 'note' => $note],
        );
    }

    /**
     * Operator aggregate for one idea: counts per reason and the free texts
     * (ordered by reason and text, never by time; no identifiers).
     *
     * @return array{down_count: int, counts: array<string, int>, notes: list<array{reason: string, note: string}>}
     * @throws DbalException
     */
    public function aggregateForIdea(int $ideaId): array
    {
        $counts = ['not_needed' => 0, 'too_expensive' => 0, 'worsens_workflow' => 0, 'other' => 0];
        foreach ($this->conn->fetchAllAssociative(
            'SELECT r.reason AS reason, COUNT(*) AS total
               FROM vote_reasons r INNER JOIN votes v ON v.id = r.vote_id
              WHERE v.idea_id = :idea
              GROUP BY r.reason',
            ['idea' => $ideaId],
        ) as $row) {
            $key = is_string($row['reason'] ?? null) ? $row['reason'] : '';
            if (array_key_exists($key, $counts)) {
                $counts[$key] = (int) ($row['total'] ?? 0);
            }
        }

        $downCount = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM votes WHERE idea_id = :idea AND value = -1',
            ['idea' => $ideaId],
        );

        $notes = [];
        foreach ($this->conn->fetchAllAssociative(
            'SELECT r.reason AS reason, r.note AS note
               FROM vote_reasons r INNER JOIN votes v ON v.id = r.vote_id
              WHERE v.idea_id = :idea AND r.note IS NOT NULL
              ORDER BY r.reason ASC, r.note ASC
              LIMIT ' . self::NOTES_LIMIT,
            ['idea' => $ideaId],
        ) as $row) {
            if (is_string($row['note'] ?? null) && is_string($row['reason'] ?? null)) {
                $notes[] = ['reason' => $row['reason'], 'note' => $row['note']];
            }
        }

        return ['down_count' => $downCount, 'counts' => $counts, 'notes' => $notes];
    }

    /**
     * Moderator takedown of one free text (owner answer 22a): the reason choice
     * stays, only the text is cleared. The note is matched by idea, reason and
     * exact text, so the operator view never needs an identifier. The match
     * runs in PHP (=== on the bytes), so case variants such as "Zu teuer" and
     * "zu teuer" stay apart under any database collation. Returns false when no
     * such note exists on the idea.
     *
     * @throws DbalException
     */
    public function clearNoteForIdea(int $ideaId, string $reason, string $note): bool
    {
        $voteId = null;
        foreach ($this->conn->fetchAllAssociative(
            'SELECT r.vote_id AS vote_id, r.note AS note
               FROM vote_reasons r INNER JOIN votes v ON v.id = r.vote_id
              WHERE v.idea_id = :idea AND r.reason = :reason AND r.note IS NOT NULL',
            ['idea' => $ideaId, 'reason' => $reason],
        ) as $row) {
            if (is_string($row['note'] ?? null) && $row['note'] === $note) {
                $voteId = (int) $row['vote_id'];
                break;
            }
        }
        if ($voteId === null) {
            return false;
        }

        $this->conn->executeStatement(
            'UPDATE vote_reasons SET note = NULL WHERE vote_id = :vote',
            ['vote' => $voteId],
        );

        return true;
    }

    /**
     * Account erasure (UserErasureRepository): the erased user's votes keep
     * their value, but the free text they typed is removed. The reason choice
     * itself is not personal and stays.
     *
     * @param list<int> $voteIds
     * @throws DbalException
     */
    public function clearNotesForVotes(array $voteIds): void
    {
        foreach ($voteIds as $voteId) {
            $this->conn->executeStatement(
                'UPDATE vote_reasons SET note = NULL WHERE vote_id = :vote',
                ['vote' => $voteId],
            );
        }
    }
}
