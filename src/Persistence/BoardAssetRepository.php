<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * board_assets rows (migration 0092). Board scoping is by board_id only; every
 * caller reaches a board through an account-scoped board lookup first (same
 * chokepoint reasoning as board_statuses, which has no account_id either).
 * All SQL uses bound parameters.
 */
final readonly class BoardAssetRepository
{
    public function __construct(
        private Connection $conn,
    ) {}

    /**
     * @return list<array{variant: string, public_id: string, file_name: string, content_type: string, width: int, height: int, ink_luminance: ?float}>
     *
     * @throws DbalException
     */
    public function listForBoard(int $boardId): array
    {
        /** @var list<array{variant: string, public_id: string, file_name: string, content_type: string, width: int|string, height: int|string, ink_luminance: string|null}> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT variant, public_id, file_name, content_type, width, height, ink_luminance
             FROM board_assets WHERE board_id = :board_id ORDER BY variant',
            ['board_id' => $boardId],
        );

        return array_map(
            static fn (array $row): array => [
                'variant'        => $row['variant'],
                'public_id'      => $row['public_id'],
                'file_name'      => $row['file_name'],
                'content_type'   => $row['content_type'],
                'width'          => (int) $row['width'],
                'height'         => (int) $row['height'],
                'ink_luminance'  => $row['ink_luminance'] === null ? null : (float) $row['ink_luminance'],
            ],
            $rows,
        );
    }

    /**
     * Public id of one variant of one board, or null. Used by BrandResolver for
     * the uploaded logo; the caller already holds the board id.
     *
     * @throws DbalException
     */
    public function publicIdForVariant(int $boardId, string $variant): ?string
    {
        $id = $this->conn->fetchOne(
            'SELECT public_id FROM board_assets WHERE board_id = :board_id AND variant = :variant',
            ['board_id' => $boardId, 'variant' => $variant],
        );

        return is_string($id) ? $id : null;
    }

    /**
     * Row for one public id, only if it belongs to THIS board. A foreign
     * board's public id returns null here by construction.
     *
     * @return array{variant: string, file_name: string, content_type: string}|null
     *
     * @throws DbalException
     */
    public function findByPublicIdForBoard(int $boardId, string $publicId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT variant, file_name, content_type FROM board_assets
             WHERE board_id = :board_id AND public_id = :public_id',
            ['board_id' => $boardId, 'public_id' => $publicId],
        );

        return is_array($row)
            ? ['variant' => (string) $row['variant'], 'file_name' => (string) $row['file_name'], 'content_type' => (string) $row['content_type']]
            : null;
    }

    /**
     * Replaces the rows for the given variants of one board in a single
     * transaction and returns the file names of the rows it replaced, so the
     * caller can delete those files only AFTER the DB points at the new ones.
     *
     * @param list<array{variant: string, public_id: string, file_name: string, content_type: string, width: int, height: int, ink_luminance: ?float}> $renditions
     * @return list<string> replaced file names
     *
     * @throws DbalException
     */
    public function replace(int $boardId, array $renditions, \DateTimeImmutable $now): array
    {
        $replaced = [];
        $this->conn->beginTransaction();
        try {
            foreach ($renditions as $rendition) {
                $old = $this->conn->fetchOne(
                    'SELECT file_name FROM board_assets WHERE board_id = :board_id AND variant = :variant',
                    ['board_id' => $boardId, 'variant' => $rendition['variant']],
                );
                if (is_string($old)) {
                    $replaced[] = $old;
                }
                $this->conn->executeStatement(
                    'DELETE FROM board_assets WHERE board_id = :board_id AND variant = :variant',
                    ['board_id' => $boardId, 'variant' => $rendition['variant']],
                );
                $this->conn->insert('board_assets', [
                    'board_id'     => $boardId,
                    'variant'      => $rendition['variant'],
                    'public_id'    => $rendition['public_id'],
                    'file_name'    => $rendition['file_name'],
                    'content_type' => $rendition['content_type'],
                    'width'        => $rendition['width'],
                    'height'       => $rendition['height'],
                    'ink_luminance' => $rendition['ink_luminance'],
                    'created_at'   => $now->format('Y-m-d H:i:s'),
                ]);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        return $replaced;
    }

    /**
     * Removes the rows of the given variants and returns their file names.
     *
     * @param list<string> $variants
     * @return list<string>
     *
     * @throws DbalException
     */
    public function deleteVariants(int $boardId, array $variants): array
    {
        $files = [];
        $this->conn->beginTransaction();
        try {
            foreach ($variants as $variant) {
                $old = $this->conn->fetchOne(
                    'SELECT file_name FROM board_assets WHERE board_id = :board_id AND variant = :variant',
                    ['board_id' => $boardId, 'variant' => $variant],
                );
                if (is_string($old)) {
                    $files[] = $old;
                }
                $this->conn->executeStatement(
                    'DELETE FROM board_assets WHERE board_id = :board_id AND variant = :variant',
                    ['board_id' => $boardId, 'variant' => $variant],
                );
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        return $files;
    }

    /**
     * File names of every asset of every board of one account (GDPR purge /
     * operator account delete). Called BEFORE the account row is deleted.
     *
     * @return list<string>
     *
     * @throws DbalException
     */
    public function fileNamesForAccount(int $accountId): array
    {
        /** @var list<string> $names */
        $names = $this->conn->fetchFirstColumn(
            'SELECT ba.file_name FROM board_assets ba
             INNER JOIN boards b ON b.id = ba.board_id
             WHERE b.account_id = :account_id',
            ['account_id' => $accountId],
        );

        return $names;
    }

    /**
     * File names of every asset of one board (board delete). Called BEFORE
     * the board row is deleted.
     *
     * @return list<string>
     *
     * @throws DbalException
     */
    public function fileNamesForBoard(int $boardId): array
    {
        /** @var list<string> $names */
        $names = $this->conn->fetchFirstColumn(
            'SELECT file_name FROM board_assets WHERE board_id = :board_id',
            ['board_id' => $boardId],
        );

        return $names;
    }
}
