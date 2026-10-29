<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Votepit\Domain\StatusDecision;

/**
 * Status-change history of one idea (migration 0091, P-01).
 *
 * Writes happen inside IdeaStatusChanger's status transaction, so a status
 * change and its decision row are committed together or not at all.
 *
 * Reads are board-scoped (board_id + idea_id, both from an account-scoped
 * lookup) and return ONLY public columns. actor_user_id is written for
 * internal accountability and is deliberately never selected here.
 */
final readonly class IdeaStatusDecisionRepository
{
    /** Newest-first cap on the public history (the latest decision is always the first entry). */
    public const HISTORY_LIMIT = 50;

    public function __construct(private Connection $conn) {}

    /**
     * Appends one decision row. `$actorId` 0 (system, e.g. issue-tracker sync)
     * is stored as NULL.
     */
    public function insert(
        int $boardId,
        int $ideaId,
        string $statusFrom,
        string $statusTo,
        StatusDecision $decision,
        int $actorId,
    ): void {
        $this->conn->executeStatement(
            'INSERT INTO idea_status_decisions
                (board_id, idea_id, status_from, status_to, reason, next_step, responsible_role, actor_user_id)
             VALUES
                (:board_id, :idea_id, :status_from, :status_to, :reason, :next_step, :responsible_role, :actor_user_id)',
            [
                'board_id'         => $boardId,
                'idea_id'          => $ideaId,
                'status_from'      => $statusFrom,
                'status_to'        => $statusTo,
                'reason'           => $decision->reason,
                'next_step'        => $decision->nextStep,
                'responsible_role' => $decision->responsibleRole,
                'actor_user_id'    => $actorId > 0 ? $actorId : null,
            ],
        );
    }

    /**
     * Public history of one idea, newest first, public columns only.
     *
     * @return list<array{id: int, status_from: string, status_to: string, reason: ?string, next_step: ?string, responsible_role: ?string, created_at: string}>
     */
    public function listForIdea(int $boardId, int $ideaId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, status_from, status_to, reason, next_step, responsible_role, created_at
               FROM idea_status_decisions
              WHERE board_id = :board_id AND idea_id = :idea_id
              ORDER BY id DESC
              LIMIT ' . self::HISTORY_LIMIT,
            ['board_id' => $boardId, 'idea_id' => $ideaId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'               => (int) $row['id'],
                'status_from'      => (string) $row['status_from'],
                'status_to'        => (string) $row['status_to'],
                'reason'           => is_string($row['reason'] ?? null) ? $row['reason'] : null,
                'next_step'        => is_string($row['next_step'] ?? null) ? $row['next_step'] : null,
                'responsible_role' => is_string($row['responsible_role'] ?? null) ? $row['responsible_role'] : null,
                'created_at'       => (string) $row['created_at'],
            ];
        }

        return $out;
    }
}
