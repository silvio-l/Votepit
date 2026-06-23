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
     * Legt eine neue Idee board-scoped an (Prepared-Statement, kein String-Konkat).
     *
     * Status startet per Schema-Default 'open'. `title_normalized` wird vom
     * Aufrufer (IdeaCreateAction via TitleNormalizer) gesetzt — kein Fork der
     * Normalisierungs-Logik hier.
     *
     * @throws DbalException
     * @return int Die neue Idee-ID (last insert id).
     */
    public function create(
        int $boardId,
        int $authorId,
        string $title,
        string $titleNormalized,
        string $body,
    ): int {
        $this->conn->executeStatement(
            'INSERT INTO ideas (board_id, author_id, title, title_normalized, body, status, created_at, updated_at)
             VALUES (:board_id, :author_id, :title, :title_normalized, :body, \'open\', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            [
                'board_id'         => $boardId,
                'author_id'        => $authorId,
                'title'            => $title,
                'title_normalized' => $titleNormalized,
                'body'             => $body,
            ],
        );

        return (int) $this->conn->lastInsertId();
    }

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
     * Liefert eine einzelne Idee (board-scoped, Prepared-Statement).
     *
     * Gibt null zurück wenn die Idee unbekannt ist ODER nicht zu diesem Board gehört
     * (Cross-Board-Leak strukturell ausgeschlossen).
     *
     * Wenn $currentUserId gesetzt ist, enthält das Ergebnis zusätzlich den Schlüssel
     * `my_vote` ∈ {'up','down','none'} — ermittelt per korrelierter Subquery
     * (set-based, kein N+1-Problem, user- und board-scoped über ideas.id).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findInBoard(int $boardId, int $id, ?int $currentUserId = null): ?array
    {
        $myVoteExpr = $currentUserId !== null
            ? ', COALESCE((SELECT CASE WHEN value > 0 THEN \'up\' WHEN value < 0 THEN \'down\' ELSE \'none\' END FROM votes WHERE idea_id = ideas.id AND user_id = :current_user_id), \'none\') AS my_vote'
            : '';

        $params = ['board_id' => $boardId, 'id' => $id];
        if ($currentUserId !== null) {
            $params['current_user_id'] = $currentUserId;
        }

        $row = $this->conn->fetchAssociative(
            'SELECT id, board_id, author_id, title, body, status, score_cache, created_at, updated_at, (SELECT COUNT(*) FROM comments WHERE comments.idea_id = ideas.id) AS comment_count, (SELECT COUNT(*) FROM votes WHERE votes.idea_id = ideas.id AND votes.value > 0) AS up_count, (SELECT COUNT(*) FROM votes WHERE votes.idea_id = ideas.id AND votes.value < 0) AS down_count'
            . $myVoteExpr
            . '
             FROM ideas
             WHERE board_id = :board_id AND id = :id',
            $params,
        );

        return $row !== false ? $row : null;
    }

    /**
     * Aktualisiert eine eigene Idee (board-scoped, author-scoped, Prepared-Statement).
     *
     * Bindet id, author_id und board_id als Parameter — keine Row wird geändert wenn
     * die Idee nicht existiert, nicht zu diesem Board gehört oder nicht diesem Autor
     * gehört. Rückgabe: true wenn genau eine Zeile geändert wurde, false sonst.
     *
     * @throws DbalException
     */
    public function updateOwn(
        int $id,
        int $authorId,
        int $boardId,
        string $title,
        string $titleNormalized,
        string $body,
    ): bool {
        $affected = $this->conn->executeStatement(
            'UPDATE ideas
             SET title = :title, title_normalized = :title_normalized, body = :body,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND author_id = :author_id AND board_id = :board_id',
            [
                'id'               => $id,
                'author_id'        => $authorId,
                'board_id'         => $boardId,
                'title'            => $title,
                'title_normalized' => $titleNormalized,
                'body'             => $body,
            ],
        );

        return $affected === 1;
    }

    /**
     * Löscht eine eigene Idee (Hard-Delete, board-scoped, author-scoped, Prepared-Statement).
     *
     * WHERE bindet id, author_id UND board_id — fremde Ideen werden strukturell
     * ausgeschlossen (Defense in Depth über den Action-Guard hinaus).
     * Rückgabe: true wenn genau eine Zeile gelöscht wurde, false sonst.
     *
     * @throws DbalException
     */
    public function withdraw(int $id, int $authorId, int $boardId): bool
    {
        $affected = $this->conn->executeStatement(
            'DELETE FROM ideas WHERE id = :id AND author_id = :author_id AND board_id = :board_id',
            [
                'id'        => $id,
                'author_id' => $authorId,
                'board_id'  => $boardId,
            ],
        );

        return $affected === 1;
    }

    /**
     * Paginierte, board-scoped Ideenliste.
     *
     * @param int         $boardId       Board-Scoping — zwingend.
     * @param string|null $status        Optionaler Status-Filter (Allow-List validiert).
     * @param int         $limit         Maximale Anzahl Einträge.
     * @param int         $offset        Pagination-Offset.
     * @param string      $sortKey       Sortierachse als Schlüssel aus SORT_AXES.
     *                                   Unbekannte Schlüssel → DEFAULT_SORT ('newest').
     *                                   Hook für Sprint 4: 'top' hinzufügen.
     * @param int|null    $currentUserId Optional: Wenn gesetzt, enthält jede Idee
     *                                   zusätzlich `my_vote` ∈ {'up','down','none'}.
     *                                   Kein N+1 — korrelierte Subquery je Zeile.
     *                                   Board-/User-Scoped über ideas.id der äußeren Query.
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listByBoard(
        int $boardId,
        ?string $status = null,
        int $limit = self::DEFAULT_PAGE_SIZE,
        int $offset = 0,
        string $sortKey = self::DEFAULT_SORT,
        ?int $currentUserId = null,
    ): array {
        // Sort-Allow-List: unbekannte Schlüssel → Newest-Fallback.
        $orderBy = self::SORT_AXES[$sortKey] ?? self::SORT_AXES[self::DEFAULT_SORT];

        // Status-Allow-List: ungültige Werte → kein Filter (alle Status).
        $validStatus = ($status !== null && in_array($status, self::ALLOWED_STATUSES, true))
            ? $status
            : null;

        // my_vote-Subquery: nur wenn ein User eingeloggt ist. Set-based, kein N+1.
        $myVoteExpr = $currentUserId !== null
            ? ', COALESCE((SELECT CASE WHEN value > 0 THEN \'up\' WHEN value < 0 THEN \'down\' ELSE \'none\' END FROM votes WHERE idea_id = ideas.id AND user_id = :current_user_id), \'none\') AS my_vote'
            : '';

        $baseSelect = 'SELECT id, board_id, author_id, title, body, status, score_cache, created_at, updated_at, (SELECT COUNT(*) FROM comments WHERE comments.idea_id = ideas.id) AS comment_count, (SELECT COUNT(*) FROM votes WHERE votes.idea_id = ideas.id AND votes.value > 0) AS up_count, (SELECT COUNT(*) FROM votes WHERE votes.idea_id = ideas.id AND votes.value < 0) AS down_count'
            . $myVoteExpr;

        if ($validStatus !== null) {
            $params = ['board_id' => $boardId, 'status' => $validStatus, 'limit' => $limit, 'offset' => $offset];
            if ($currentUserId !== null) {
                $params['current_user_id'] = $currentUserId;
            }

            $rows = $this->conn->fetchAllAssociative(
                $baseSelect . '
                 FROM ideas
                 WHERE board_id = :board_id AND status = :status
                 ORDER BY ' . $orderBy . '
                 LIMIT :limit OFFSET :offset',
                $params,
            );
        } else {
            $params = ['board_id' => $boardId, 'limit' => $limit, 'offset' => $offset];
            if ($currentUserId !== null) {
                $params['current_user_id'] = $currentUserId;
            }

            $rows = $this->conn->fetchAllAssociative(
                $baseSelect . '
                 FROM ideas
                 WHERE board_id = :board_id
                 ORDER BY ' . $orderBy . '
                 LIMIT :limit OFFSET :offset',
                $params,
            );
        }

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }
}
