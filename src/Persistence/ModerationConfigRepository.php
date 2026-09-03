<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Persistence for the board moderation configuration.
 *
 * Prepared-statements-only via DBAL. No query-string concatenation. Board-scoped:
 * every access carries `WHERE board_id = :board_id` — no cross-board leak possible.
 */
final readonly class ModerationConfigRepository
{
    public function __construct(private Connection $conn) {}

    // -------------------------------------------------------------------------
    // Toggle (boards.moderation_enabled)
    // -------------------------------------------------------------------------

    /**
     * Reads the moderation toggle for a board.
     * Returns true (= filter on) if the value is 1 or the field is missing (fail-safe).
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
            return true; // Board not found → fail-safe: filter on
        }

        return (bool) $value;
    }

    /**
     * Sets the moderation toggle for a board (board-scoped via id).
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
    // Custom words (board_blocklist)
    // -------------------------------------------------------------------------

    /**
     * Lists all custom words of a board; board-scoped via board_id.
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
     * Adds a custom word to the board blocklist (board-scoped).
     * Duplicates are ignored (UNIQUE constraint → no error via INSERT IGNORE).
     * Whitespace is trimmed server-side; empty words are rejected.
     *
     * @throws DbalException
     */
    public function addWord(int $boardId, string $word): void
    {
        $word = mb_substr(trim($word), 0, 200, 'UTF-8');
        if ($word === '') {
            return;
        }

        // INSERT OR IGNORE (SQLite) / INSERT IGNORE (MySQL) — portable duplicate handling.
        // Since DBAL offers no universal "INSERT IGNORE", we silently catch unique-constraint
        // violations to support both SQLite (tests) and MySQL (production).
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
            // Duplicate — ignore silently (idempotent operation).
        }
    }

    /**
     * Removes a custom word from the board blocklist (board-scoped via board_id + id).
     * Unknown IDs are silently ignored.
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
     * Returns all custom words of a board as a simple string list
     * (for passing to ContentModerationService::withAdditionalWords()).
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

    /**
     * Lists the custom blocklist words of an account, across all
     * boards (customer self-export). Account-scoped via a
     * JOIN on boards (board_blocklist itself carries no account_id column).
     *
     * @return list<array{board_id: int, word: string}>
     * @throws DbalException
     */
    public function listWordsForAccount(int $accountId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT bl.board_id, bl.word
             FROM board_blocklist bl
             INNER JOIN boards b ON b.id = bl.board_id
             WHERE b.account_id = :account_id
             ORDER BY bl.board_id ASC, bl.word ASC',
            ['account_id' => $accountId],
        );

        /** @var list<array{board_id: int, word: string}> */
        return array_map(
            static fn (array $r): array => ['board_id' => (int) $r['board_id'], 'word' => (string) $r['word']],
            $rows,
        );
    }
}
