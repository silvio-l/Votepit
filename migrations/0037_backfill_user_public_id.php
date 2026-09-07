<?php

declare(strict_types=1);

namespace Votepit\Migrations;

use Doctrine\DBAL\Connection;
use Votepit\Security\PublicIdGenerator;

/**
 * 0037_backfill_user_public_id — generates a random public_id
 * (PublicIdGenerator) for every existing users row.
 *
 * Batched (BATCH_SIZE rows/round), idempotent (only rows with
 * public_id IS NULL, a second run is a no-op). Retries on the rare
 * collision instead of assuming entropy alone is enough — the column has
 * no UNIQUE constraint yet at this point (0038 adds it after every row is
 * filled), so a collision is only caught by an explicit uniqueness check
 * here.
 */
final class BackfillUserPublicIdMigration implements Migration
{
    private const BATCH_SIZE = 500;
    private const MAX_ATTEMPTS_PER_ROW = 10;

    public function version(): string
    {
        return '0037_backfill_user_public_id';
    }

    public function up(Connection $conn): void
    {
        while (true) {
            /** @var list<array{id: int|string}> $rows */
            $rows = $conn->fetchAllAssociative(
                'SELECT id FROM users WHERE public_id IS NULL ORDER BY id LIMIT :batch_size',
                ['batch_size' => self::BATCH_SIZE],
            );

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $this->assignPublicId($conn, $row['id']);
            }
        }
    }

    private function assignPublicId(Connection $conn, int|string $userId): void
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS_PER_ROW; $attempt++) {
            $publicId = PublicIdGenerator::generate();
            $taken    = (bool) $conn->fetchOne(
                'SELECT 1 FROM users WHERE public_id = :public_id',
                ['public_id' => $publicId],
            );

            if (!$taken) {
                $conn->executeStatement(
                    'UPDATE users SET public_id = :public_id WHERE id = :id',
                    ['public_id' => $publicId, 'id' => $userId],
                );

                return;
            }
        }

        throw new \RuntimeException("0037_backfill_user_public_id: could not find a free public_id for user {$userId} after " . self::MAX_ATTEMPTS_PER_ROW . ' attempts.');
    }
}

return new BackfillUserPublicIdMigration();
