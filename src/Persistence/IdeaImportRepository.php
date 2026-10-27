<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * idea_imports persistence (migrations/0077) — one row per applied board
 * import. Board-scoped: every lookup binds board_id, so an idempotency key
 * of one board (or tenant) never matches another's.
 */
final readonly class IdeaImportRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * @return array{id: int, format: string, content_sha256: string, imported_count: int, skipped_count: int}|null
     * @throws DbalException
     */
    public function findByKey(int $boardId, string $idempotencyKey): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, format, content_sha256, imported_count, skipped_count
             FROM idea_imports WHERE board_id = :board_id AND idempotency_key = :key',
            ['board_id' => $boardId, 'key' => $idempotencyKey],
        );

        if ($row === false) {
            return null;
        }

        return [
            'id'             => (int) $row['id'],
            'format'         => (string) $row['format'],
            'content_sha256' => (string) $row['content_sha256'],
            'imported_count' => (int) $row['imported_count'],
            'skipped_count'  => (int) $row['skipped_count'],
        ];
    }

    /**
     * Records an import. Returns null when (board_id, idempotency_key)
     * already exists — a concurrent duplicate request won the race.
     *
     * @throws DbalException
     */
    public function create(
        int $boardId,
        int $actorUserId,
        string $idempotencyKey,
        string $format,
        string $contentSha256,
        int $importedCount,
        int $skippedCount,
    ): ?int {
        try {
            $this->conn->executeStatement(
                'INSERT INTO idea_imports (board_id, actor_user_id, idempotency_key, format, content_sha256, imported_count, skipped_count, created_at)
                 VALUES (:board_id, :actor, :key, :format, :sha, :imported, :skipped, :created_at)',
                [
                    'board_id'   => $boardId,
                    'actor'      => $actorUserId,
                    'key'        => $idempotencyKey,
                    'format'     => $format,
                    'sha'        => $contentSha256,
                    'imported'   => $importedCount,
                    'skipped'    => $skippedCount,
                    'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return (int) $this->conn->lastInsertId();
    }
}
