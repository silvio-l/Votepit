<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Persistenz für die Board-Moderation-Konfiguration (Issue 10).
 *
 * Prepared-Statements-only via DBAL. Kein Query-String-Concat. Board-scoped:
 * jeder Zugriff trägt `WHERE board_id = :board_id` — kein Cross-Board-Leak möglich.
 */
final readonly class ModerationConfigRepository
{
    public function __construct(private Connection $conn) {}

    // -------------------------------------------------------------------------
    // Toggle (boards.moderation_enabled)
    // -------------------------------------------------------------------------

    /**
     * Liest den Moderation-Toggle für ein Board.
     * Liefert true (= Filter an) wenn der Wert 1 ist oder das Feld fehlt (fail-safe).
     *
     * @throws DbalException
     */
    public function isModerationEnabled(int $boardId): bool
    {
        $value = $this->conn->fetchOne(
            'SELECT moderation_enabled FROM boards WHERE id = :board_id',
            ['board_id' => $boardId],
        );

        if ($value === false) {
            return true; // Board nicht gefunden → fail-safe: Filter an
        }

        return (bool) $value;
    }

    /**
     * Setzt den Moderation-Toggle für ein Board (board-scoped via id).
     *
     * @throws DbalException
     */
    public function setModerationEnabled(int $boardId, bool $enabled): void
    {
        $this->conn->executeStatement(
            'UPDATE boards SET moderation_enabled = :enabled WHERE id = :board_id',
            [
                'enabled'  => $enabled ? 1 : 0,
                'board_id' => $boardId,
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Custom-Wörter (board_blocklist)
    // -------------------------------------------------------------------------

    /**
     * Listet alle Custom-Wörter eines Boards; board-scoped via board_id.
     *
     * @return list<array{id: int, word: string}>
     * @throws DbalException
     */
    public function listWords(int $boardId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, word FROM board_blocklist WHERE board_id = :board_id ORDER BY word ASC',
            ['board_id' => $boardId],
        );

        /** @var list<array{id: int, word: string}> */
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'word' => (string) $r['word']],
            $rows,
        );
    }

    /**
     * Fügt ein Custom-Wort zur Board-Blocklist hinzu (board-scoped).
     * Duplikate werden ignoriert (UNIQUE-Constraint → kein Fehler durch INSERT IGNORE).
     * Leerzeichen werden serverseitig getrimmt; leere Wörter werden abgewiesen.
     *
     * @throws DbalException
     */
    public function addWord(int $boardId, string $word): void
    {
        $word = mb_substr(trim($word), 0, 200, 'UTF-8');
        if ($word === '') {
            return;
        }

        // INSERT OR IGNORE (SQLite) / INSERT IGNORE (MySQL) — portables Duplikat-Handling.
        // Da DBAL keinen universellen "INSERT IGNORE" bietet, fangen wir UniqueConstraint-
        // Violations still ab, um sowohl SQLite (Tests) als auch MySQL (Produktion) zu stützen.
        try {
            $this->conn->executeStatement(
                'INSERT INTO board_blocklist (board_id, word, created_at)
                 VALUES (:board_id, :word, :created_at)',
                [
                    'board_id'   => $boardId,
                    'word'       => $word,
                    'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Duplikat — still ignorieren (idempotente Operation).
        }
    }

    /**
     * Entfernt ein Custom-Wort aus der Board-Blocklist (board-scoped via board_id + id).
     * Unbekannte IDs werden still ignoriert.
     *
     * @throws DbalException
     */
    public function removeWord(int $boardId, int $wordId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM board_blocklist WHERE id = :id AND board_id = :board_id',
            [
                'id'       => $wordId,
                'board_id' => $boardId,
            ],
        );
    }

    /**
     * Liefert alle Custom-Wörter eines Boards als einfache String-Liste
     * (zur Übergabe an ContentModerationService::withAdditionalWords()).
     *
     * @return list<string>
     * @throws DbalException
     */
    public function wordList(int $boardId): array
    {
        $rows = $this->conn->fetchFirstColumn(
            'SELECT word FROM board_blocklist WHERE board_id = :board_id',
            ['board_id' => $boardId],
        );

        return array_map(strval(...), $rows);
    }
}
