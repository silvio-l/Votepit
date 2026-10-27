<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Votepit\Domain\BoardStatuses;
use Votepit\Domain\StatusService;

/**
 * board_statuses persistence (broaden-use-cases issue 03,
 * migrations/0073_create_board_statuses.sql).
 *
 * Prepared statements only. Every method binds board_id; board_id itself
 * only ever comes from an account-scoped board lookup
 * (BoardRepository::findBySlugForAccount()/findPublicBySlugForAccount()),
 * same chokepoint reasoning as TagRepository. The writes that flip
 * boards.statuses_customized additionally bind account_id.
 *
 * The callers own the transaction for multi-statement writes
 * (BoardStatusesAdminAction wraps materialise + create/update/delete in one).
 */
final readonly class BoardStatusRepository
{
    private const COLUMNS = 'status_key, category, label_de, label_en, position, is_hidden, allow_votes, allow_comments, is_system';

    public function __construct(private Connection $conn) {}

    /**
     * The board's registry. A board without customisation (the flag on the
     * already-loaded board row) costs no query at all; a customised board
     * costs exactly one (rows + per-status idea counts in one statement).
     *
     * @param array<string, mixed> $board a row from BoardRepository
     * @throws DbalException
     */
    public function registryFor(array $board): BoardStatuses
    {
        if (!(bool) ($board['statuses_customized'] ?? false)) {
            return BoardStatuses::defaults();
        }

        return BoardStatuses::fromRows($this->rowsWithCounts((int) $board['id'], publishedOnly: true));
    }

    /**
     * Admin view: like registryFor() but always with idea counts per status
     * (needed for the delete/hide guidance), also for a default board.
     *
     * @param array<string, mixed> $board
     * @throws DbalException
     */
    public function registryWithCounts(array $board): BoardStatuses
    {
        $boardId = (int) $board['id'];
        if ((bool) ($board['statuses_customized'] ?? false)) {
            return BoardStatuses::fromRows($this->rowsWithCounts($boardId, publishedOnly: false));
        }

        $counts = $this->conn->fetchAllKeyValue(
            'SELECT status, COUNT(*) FROM ideas WHERE board_id = :board_id AND deleted_at IS NULL GROUP BY status',
            ['board_id' => $boardId],
        );

        $rows = [];
        foreach (StatusService::VALID_STATUSES as $i => $key) {
            $rows[] = $this->systemRow($key, $i) + ['idea_count' => (int) ($counts[$key] ?? 0)];
        }

        return BoardStatuses::fromRows($rows);
    }

    /**
     * Writes the six system rows and sets boards.statuses_customized — a
     * no-op when the board is already customised. Call inside the caller's
     * transaction, before the first board_statuses write.
     *
     * @param array<string, mixed> $board
     * @throws DbalException
     */
    public function materialize(array $board, int $accountId): void
    {
        if ((bool) ($board['statuses_customized'] ?? false)) {
            return;
        }

        $boardId = (int) $board['id'];
        $updated = $this->conn->executeStatement(
            'UPDATE boards SET statuses_customized = 1 WHERE id = :id AND account_id = :account_id AND statuses_customized = 0',
            ['id' => $boardId, 'account_id' => $accountId],
        );
        if ($updated !== 1) {
            return; // concurrent materialisation already happened (or foreign board)
        }

        foreach (StatusService::VALID_STATUSES as $i => $key) {
            $this->conn->insert('board_statuses', ['board_id' => $boardId] + $this->systemRow($key, $i));
        }
    }

    /**
     * Inserts a custom status; returns its generated key `s<base36 id>`.
     *
     * @throws DbalException
     */
    public function createCustom(int $boardId, string $category, ?string $labelDe, ?string $labelEn, bool $allowVotes, bool $allowComments): string
    {
        $position = $this->conn->fetchOne(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM board_statuses WHERE board_id = :board_id',
            ['board_id' => $boardId],
        );

        // Temporary unique key, replaced by the id-derived key right after the insert.
        $this->conn->insert('board_statuses', [
            'board_id'       => $boardId,
            'status_key'     => 't' . bin2hex(random_bytes(7)),
            'category'       => $category,
            'label_de'       => $labelDe,
            'label_en'       => $labelEn,
            'position'       => is_numeric($position) ? (int) $position : 1,
            'is_hidden'      => 0,
            'allow_votes'    => $allowVotes ? 1 : 0,
            'allow_comments' => $allowComments ? 1 : 0,
            'is_system'      => 0,
        ]);
        $id  = (int) $this->conn->lastInsertId();
        $key = 's' . base_convert((string) $id, 10, 36);

        $this->conn->executeStatement(
            'UPDATE board_statuses SET status_key = :key WHERE id = :id AND board_id = :board_id',
            ['key' => $key, 'id' => $id, 'board_id' => $boardId],
        );

        return $key;
    }

    /**
     * Updates labels/flags of one status (validated by the caller).
     *
     * @throws DbalException
     */
    public function update(int $boardId, string $key, ?string $labelDe, ?string $labelEn, bool $isHidden, bool $allowVotes, bool $allowComments): void
    {
        $this->conn->executeStatement(
            'UPDATE board_statuses
             SET label_de = :label_de, label_en = :label_en, is_hidden = :is_hidden,
                 allow_votes = :allow_votes, allow_comments = :allow_comments
             WHERE board_id = :board_id AND status_key = :key',
            [
                'label_de'       => $labelDe,
                'label_en'       => $labelEn,
                'is_hidden'      => $isHidden ? 1 : 0,
                'allow_votes'    => $allowVotes ? 1 : 0,
                'allow_comments' => $allowComments ? 1 : 0,
                'board_id'       => $boardId,
                'key'            => $key,
            ],
        );
    }

    /**
     * Moves every idea (incl. moderated ones) of $from to $to on one board.
     *
     * @throws DbalException
     */
    public function moveIdeas(int $boardId, string $from, string $to): int
    {
        return (int) $this->conn->executeStatement(
            'UPDATE ideas SET status = :to, updated_at = CURRENT_TIMESTAMP WHERE board_id = :board_id AND status = :from',
            ['to' => $to, 'board_id' => $boardId, 'from' => $from],
        );
    }

    /**
     * Ideas of any state (incl. moderated and pre-moderation pending) still
     * stored in $key.
     *
     * @throws DbalException
     */
    public function countIdeas(int $boardId, string $key): int
    {
        $count = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ideas WHERE board_id = :board_id AND status = :key',
            ['board_id' => $boardId, 'key' => $key],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Deletes a CUSTOM status row (system rows are never deleted).
     *
     * @throws DbalException
     */
    public function deleteCustom(int $boardId, string $key): bool
    {
        return $this->conn->executeStatement(
            'DELETE FROM board_statuses WHERE board_id = :board_id AND status_key = :key AND is_system = 0',
            ['board_id' => $boardId, 'key' => $key],
        ) === 1;
    }

    /**
     * Every board_statuses row of an account (customer self-export,
     * AccountExportService). Account-scoped via the JOIN on boards — a
     * foreign account's rows structurally never show up here.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listForAccount(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT bs.id, bs.board_id, bs.status_key, bs.category, bs.label_de, bs.label_en, bs.position,
                    bs.is_hidden, bs.allow_votes, bs.allow_comments, bs.is_system
             FROM board_statuses bs
             INNER JOIN boards b ON b.id = bs.board_id
             WHERE b.account_id = :account_id
             ORDER BY bs.board_id ASC, bs.position ASC, bs.id ASC',
            ['account_id' => $accountId],
        );

        return $rows;
    }

    /**
     * $publishedOnly (pre-moderation, migration 0083): the public registry
     * (registryFor() → board home/roadmap/detail payloads) counts published
     * ideas only, so a pending idea never surfaces, not even as a number.
     * The admin registry counts every live idea incl. pending ones, matching
     * countIdeas()/moveIdeas(), so delete/hide guidance never orphans them.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    private function rowsWithCounts(int $boardId, bool $publishedOnly): array
    {
        $stateClause = $publishedOnly ? ' AND i.moderation_state = \'published\'' : '';

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ',
                    (SELECT COUNT(*) FROM ideas i
                      WHERE i.board_id = bs.board_id AND i.status = bs.status_key AND i.deleted_at IS NULL' . $stateClause . ') AS idea_count
             FROM board_statuses bs
             WHERE bs.board_id = :board_id
             ORDER BY bs.position ASC, bs.id ASC',
            ['board_id' => $boardId],
        );

        return $rows;
    }

    /** @return array<string, mixed> */
    private function systemRow(string $key, int $position): array
    {
        return [
            'status_key'     => $key,
            'category'       => $key,
            'label_de'       => null,
            'label_en'       => null,
            'position'       => $position,
            'is_hidden'      => 0,
            'allow_votes'    => BoardStatuses::defaultAllowVotes($key) ? 1 : 0,
            'allow_comments' => 1,
            'is_system'      => 1,
        ];
    }
}
