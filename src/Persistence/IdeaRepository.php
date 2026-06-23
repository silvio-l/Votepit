<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Idea-Persistenz (Sprint 3, arch.md §2 — Persistence-Layer).
 *
 * Prepared-Statements-only via DBAL. Kein Query-String-Concat.
 * Striktes Board-Scoping: jede Methode bindet board_id als Parameter.
 *
 * Sortierachse: `listByBoard` nimmt einen $sortKey-Parameter aus der
 * geschlossenen Allow-List SORT_AXES. Unbekannte Schlüssel → 'newest' (Fallback).
 * Hook für Sprint 4: SORT_AXES['top'] hinzufügen, kein API-Breaking-Change.
 */
final readonly class IdeaRepository
{
    /** Gültige Status-Werte (Allow-List für Filter). */
    public const ALLOWED_STATUSES = ['open', 'planned', 'in_progress', 'done', 'declined'];

    /**
     * Geschlossene Allow-List der erlaubten Sortierachsen.
     * Werte sind vertrauenswürdige SQL-Fragmente (nie User-Input).
     * Nur Schlüssel aus dieser Map dürfen in ORDER BY einfließen.
     *
     * @var array<string, string>
     */
    public const SORT_AXES = [
        'newest' => 'created_at DESC',
        'top'    => 'score_cache DESC, created_at DESC',
    ];

    /** Standard-Sortierachse (Schlüssel aus SORT_AXES). */
    public const DEFAULT_SORT = 'newest';

    /** Default-Seitengröße (konservativ). */
    public const DEFAULT_PAGE_SIZE = 50;

    public function __construct(private Connection $conn) {}

    /**
     * Zählt Ideen eines Boards (board-scoped), optional gefiltert nach Status.
     *
     * Wird für die Pagination-Berechnung genutzt.
     *
     * @throws DbalException
     */
    public function countByBoard(int $boardId, ?string $status = null): int
    {
        $validStatus = ($status !== null && in_array($status, self::ALLOWED_STATUSES, true))
            ? $status
            : null;

        if ($validStatus !== null) {
            $count = $this->conn->fetchOne(
                'SELECT COUNT(*) FROM ideas WHERE board_id = :board_id AND status = :status',
                ['board_id' => $boardId, 'status' => $validStatus],
            );
        } else {
            $count = $this->conn->fetchOne(
                'SELECT COUNT(*) FROM ideas WHERE board_id = :board_id',
                ['board_id' => $boardId],
            );
        }

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Paginierte, board-scoped Ideenliste.
     *
     * @param int         $boardId  Board-Scoping — zwingend.
     * @param string|null $status   Optionaler Status-Filter (Allow-List validiert).
     * @param int         $limit    Maximale Anzahl Einträge.
     * @param int         $offset   Pagination-Offset.
     * @param string      $sortKey  Sortierachse als Schlüssel aus SORT_AXES.
     *                              Unbekannte Schlüssel → DEFAULT_SORT ('newest').
     *                              Hook für Sprint 4: 'top' hinzufügen.
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listByBoard(
        int $boardId,
        ?string $status = null,
        int $limit = self::DEFAULT_PAGE_SIZE,
        int $offset = 0,
        string $sortKey = self::DEFAULT_SORT,
    ): array {
        // Sort-Allow-List: unbekannte Schlüssel → Newest-Fallback.
        $orderBy = self::SORT_AXES[$sortKey] ?? self::SORT_AXES[self::DEFAULT_SORT];

        // Status-Allow-List: ungültige Werte → kein Filter (alle Status).
        $validStatus = ($status !== null && in_array($status, self::ALLOWED_STATUSES, true))
            ? $status
            : null;

        if ($validStatus !== null) {
            $rows = $this->conn->fetchAllAssociative(
                'SELECT id, board_id, author_id, title, body, status, score_cache, created_at, updated_at
                 FROM ideas
                 WHERE board_id = :board_id AND status = :status
                 ORDER BY ' . $orderBy . '
                 LIMIT :limit OFFSET :offset',
                [
                    'board_id' => $boardId,
                    'status'   => $validStatus,
                    'limit'    => $limit,
                    'offset'   => $offset,
                ],
            );
        } else {
            $rows = $this->conn->fetchAllAssociative(
                'SELECT id, board_id, author_id, title, body, status, score_cache, created_at, updated_at
                 FROM ideas
                 WHERE board_id = :board_id
                 ORDER BY ' . $orderBy . '
                 LIMIT :limit OFFSET :offset',
                [
                    'board_id' => $boardId,
                    'limit'    => $limit,
                    'offset'   => $offset,
                ],
            );
        }

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }
}
