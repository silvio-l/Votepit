<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Per-board embeddable widget configuration (migrations/0078, ADR 0005).
 *
 * Prepared statements only. Every read joins `boards` and requires the
 * caller's account id, so a widget row of a foreign account is structurally
 * unreachable (account scoping, same chokepoint idea as
 * BoardRepository::findBySlugForAccount()). Writes take a board id the
 * caller already resolved through BoardRepository::findBySlugForAccount()
 * and additionally re-check the account in SQL.
 */
final readonly class BoardWidgetRepository
{
    /** Install detection is refreshed at most this often per origin. */
    private const SEEN_THROTTLE_SECONDS = 3600;

    public function __construct(private Connection $conn) {}

    /**
     * @return array{board_id: int, allowed_origins: list<string>, secret_encrypted: string|null, secret_rotated_at: string|null, last_seen_origin: string|null, last_seen_at: string|null}|null
     * @throws DbalException
     */
    public function findForBoard(int $accountId, int $boardId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT w.board_id, w.allowed_origins, w.secret_encrypted, w.secret_rotated_at, w.last_seen_origin, w.last_seen_at
             FROM board_widgets w
             INNER JOIN boards b ON b.id = w.board_id
             WHERE w.board_id = :board_id AND b.account_id = :account_id',
            ['board_id' => $boardId, 'account_id' => $accountId],
        );
        if ($row === false) {
            return null;
        }

        $decoded = json_decode((string) $row['allowed_origins'], true);
        $origins = is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];

        return [
            'board_id'          => (int) $row['board_id'],
            'allowed_origins'   => $origins,
            'secret_encrypted'  => is_string($row['secret_encrypted']) ? $row['secret_encrypted'] : null,
            'secret_rotated_at' => is_string($row['secret_rotated_at']) ? $row['secret_rotated_at'] : null,
            'last_seen_origin'  => is_string($row['last_seen_origin']) ? $row['last_seen_origin'] : null,
            'last_seen_at'      => is_string($row['last_seen_at']) ? $row['last_seen_at'] : null,
        ];
    }

    /**
     * @param list<string> $origins Already validated (WidgetOriginValidator::normalize()).
     * @throws DbalException
     */
    public function saveOrigins(int $accountId, int $boardId, array $origins): void
    {
        $json = (string) json_encode($origins);
        if ($this->ensureRow($accountId, $boardId, $json)) {
            return;
        }

        $this->conn->executeStatement(
            'UPDATE board_widgets SET allowed_origins = :origins, updated_at = :now
             WHERE board_id = :board_id
               AND board_id IN (SELECT id FROM boards WHERE account_id = :account_id)',
            ['origins' => $json, 'now' => $this->now(), 'board_id' => $boardId, 'account_id' => $accountId],
        );
    }

    /** @throws DbalException */
    public function saveSecret(int $accountId, int $boardId, string $secretEncrypted): void
    {
        $this->ensureRow($accountId, $boardId, '[]');

        $this->conn->executeStatement(
            'UPDATE board_widgets SET secret_encrypted = :secret, secret_rotated_at = :now, updated_at = :now
             WHERE board_id = :board_id
               AND board_id IN (SELECT id FROM boards WHERE account_id = :account_id)',
            ['secret' => $secretEncrypted, 'now' => $this->now(), 'board_id' => $boardId, 'account_id' => $accountId],
        );
    }

    /**
     * Install detection: remembers which allowed origin last loaded the
     * widget. Throttled in SQL (one write per origin and hour at most), so
     * a busy embed does not turn every page view into a write.
     *
     * @throws DbalException
     */
    public function recordSeen(int $boardId, string $origin, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();

        $this->conn->executeStatement(
            'UPDATE board_widgets
             SET last_seen_origin = :origin, last_seen_at = :now, updated_at = updated_at
             WHERE board_id = :board_id
               AND (last_seen_at IS NULL OR last_seen_at < :threshold OR last_seen_origin IS NULL OR last_seen_origin <> :origin)',
            [
                'origin'    => $origin,
                'now'       => $now->format('Y-m-d H:i:s'),
                'threshold' => $now->modify('-' . self::SEEN_THROTTLE_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
                'board_id'  => $boardId,
            ],
        );
    }

    /**
     * Inserts the row if missing (account-checked). Returns true when it
     * inserted with $originsJson already set.
     *
     * @throws DbalException
     */
    private function ensureRow(int $accountId, int $boardId, string $originsJson): bool
    {
        $exists = $this->conn->fetchOne('SELECT 1 FROM board_widgets WHERE board_id = :board_id', ['board_id' => $boardId]);
        if ($exists !== false) {
            return false;
        }

        $inserted = $this->conn->executeStatement(
            'INSERT INTO board_widgets (board_id, allowed_origins, created_at, updated_at)
             SELECT id, :origins, :now, :now FROM boards WHERE id = :board_id AND account_id = :account_id',
            ['origins' => $originsJson, 'now' => $this->now(), 'board_id' => $boardId, 'account_id' => $accountId],
        );

        return $inserted > 0;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
