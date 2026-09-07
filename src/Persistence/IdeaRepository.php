<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Idea persistence (arch.md §2 — persistence layer).
 *
 * Prepared-statements-only via DBAL. No query-string concatenation.
 * Strict board scoping: every method binds board_id as a parameter.
 *
 * Sort axis: `listByBoard` takes a $sortKey parameter from the
 * closed allow-list SORT_AXES. Unknown keys → 'newest' (fallback).
 * Hook for adding a new sort: add to SORT_AXES['top'], no API-breaking change.
 */
final readonly class IdeaRepository
{
    /** Valid status values (allow-list for filtering). */
    public const ALLOWED_STATUSES = ['open', 'planned', 'in_progress', 'done', 'declined'];

    /**
     * Closed allow-list of the permitted sort axes.
     * Values are trusted SQL fragments (never user input).
     * Only keys from this map may flow into ORDER BY.
     *
     * @var array<string, string>
     */
    public const SORT_AXES = [
        'newest' => 'created_at DESC',
        'top'    => 'score_cache DESC, created_at DESC',
    ];

    /** Default sort axis (key from SORT_AXES). */
    public const DEFAULT_SORT = 'newest';

    /** Default page size (conservative). */
    public const DEFAULT_PAGE_SIZE = 50;

    public function __construct(private Connection $conn) {}

    /**
     * Creates a new idea, board-scoped (prepared statement, no string concatenation).
     *
     * Status starts at the schema default 'open'. `title_normalized` is set by
     * the caller (IdeaCreateAction via TitleNormalizer) — no fork of the
     * normalization logic here.
     *
     * @throws DbalException
     * @return int The new idea ID (last insert id).
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
     * Counts ideas of a board (board-scoped), optionally filtered by status.
     *
     * Used for pagination calculation.
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
     * Counts ideas authored by $authorId within one account, optionally
     * filtered by status. Account-scoped via a JOIN on boards (ideas itself
     * carries no account_id column, only board_id) — same chokepoint
     * reasoning as listAllForAccount(): an idea of a foreign account
     * structurally never contributes to this count.
     *
     * Backs the public profile's "ideas submitted" / "ideas shipped"
     * (status 'done') contribution stats (social-features issue 06).
     *
     * @throws DbalException
     */
    public function countByAuthorForAccount(int $accountId, int $authorId, ?string $status = null): int
    {
        $validStatus = ($status !== null && in_array($status, self::ALLOWED_STATUSES, true))
            ? $status
            : null;

        if ($validStatus !== null) {
            $count = $this->conn->fetchOne(
                'SELECT COUNT(*) FROM ideas i
                 INNER JOIN boards b ON b.id = i.board_id
                 WHERE b.account_id = :account_id AND i.author_id = :author_id AND i.status = :status',
                ['account_id' => $accountId, 'author_id' => $authorId, 'status' => $validStatus],
            );
        } else {
            $count = $this->conn->fetchOne(
                'SELECT COUNT(*) FROM ideas i
                 INNER JOIN boards b ON b.id = i.board_id
                 WHERE b.account_id = :account_id AND i.author_id = :author_id',
                ['account_id' => $accountId, 'author_id' => $authorId],
            );
        }

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Counts ALL ideas platform-wide, deliberately WITHOUT a board_id WHERE
     * (operator usage overview, see OperatorUsageAction). Unlike every other
     * method of this class, this is NOT a board-scoping chokepoint — intended
     * only for the operator route.
     *
     * @throws DbalException
     */
    public function countAll(): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas');
    }

    /**
     * Lists ALL ideas of an account, across all its boards (customer
     * self-export). Account-scoped via a JOIN on boards
     * (ideas itself carries no account_id column, only board_id) — the same
     * chokepoint reasoning as BoardRepository::findBySlugForAccount(): an
     * idea of a foreign account structurally never shows up here.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listAllForAccount(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT i.id, i.board_id, i.author_id, i.title, i.title_normalized, i.body, i.status,
                    i.is_pinned, i.merged_into_id, i.score_cache, i.view_count, i.created_at, i.updated_at
             FROM ideas i
             INNER JOIN boards b ON b.id = i.board_id
             WHERE b.account_id = :account_id
             ORDER BY i.id ASC',
            ['account_id' => $accountId],
        );

        return $rows;
    }

    /**
     * Returns a single idea (board-scoped, prepared statement).
     *
     * Returns null if the idea is unknown OR does not belong to this board
     * (cross-board leak structurally excluded).
     *
     * If $currentUserId is set, the result additionally contains the key
     * `my_vote` ∈ {'up','down','none'} — determined via a correlated subquery
     * (set-based, no N+1 problem, user- and board-scoped via ideas.id).
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
            'SELECT id, board_id, author_id, title, body, status, is_pinned, score_cache, view_count, created_at, updated_at, (SELECT COUNT(*) FROM comments WHERE comments.idea_id = ideas.id) AS comment_count, (SELECT COUNT(*) FROM votes WHERE votes.idea_id = ideas.id AND votes.value > 0) AS up_count, (SELECT COUNT(*) FROM votes WHERE votes.idea_id = ideas.id AND votes.value < 0) AS down_count'
            . $myVoteExpr
            . '
             FROM ideas
             WHERE board_id = :board_id AND id = :id',
            $params,
        );

        if ($row === false) {
            return null;
        }

        $row['is_pinned']  = (bool) ($row['is_pinned'] ?? false);
        $row['view_count'] = (int) ($row['view_count'] ?? 0);

        return $row;
    }

    /**
     * Increments the idea detail page's view counter (migrations/
     * 0049_add_ideas_view_count.sql) — app-side, same pattern as
     * VoteRepository's score_cache maintenance, not a COUNT-subquery (detail
     * reads happen far more often than votes/comments). Deduplicated
     * upstream by IdeaViewTracker; this method itself just increments.
     *
     * @throws DbalException
     */
    public function incrementViewCount(int $ideaId): void
    {
        $this->conn->executeStatement(
            'UPDATE ideas SET view_count = view_count + 1 WHERE id = :id',
            ['id' => $ideaId],
        );
    }

    /**
     * Updates a user's own idea (board-scoped, author-scoped, prepared statement).
     *
     * Binds id, author_id and board_id as parameters — no row is changed if
     * the idea does not exist, does not belong to this board, or does not belong
     * to this author. Returns: true if exactly one row was changed, false otherwise.
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
     * Deletes a user's own idea (hard delete, board-scoped, author-scoped, prepared statement).
     *
     * WHERE binds id, author_id AND board_id — foreign ideas are structurally
     * excluded (defense in depth beyond the action guard).
     * Returns: true if exactly one row was deleted, false otherwise.
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
     * Sets an idea's status (board-scoped, admin mutation, prepared statement).
     *
     * Binds id AND board_id as parameters — cross-board mutation structurally
     * excluded (defense in depth beyond the action guard).
     * Returns: true if exactly one row was changed, false otherwise.
     *
     * @throws DbalException
     */
    public function updateStatus(int $boardId, int $id, string $status): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE ideas SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND board_id = :board_id',
            [
                'status'   => $status,
                'id'       => $id,
                'board_id' => $boardId,
            ],
        );

        return $affected === 1;
    }

    /**
     * Sets an idea's pin state (board-scoped, admin mutation, prepared statement).
     *
     * Binds id AND board_id as parameters — cross-board mutation structurally
     * excluded (defense in depth beyond the action guard).
     * Returns: true if exactly one row was changed, false otherwise.
     *
     * @throws DbalException
     */
    public function setPinned(int $boardId, int $id, bool $pinned): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE ideas SET is_pinned = :is_pinned, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND board_id = :board_id',
            [
                'is_pinned' => $pinned ? 1 : 0,
                'id'        => $id,
                'board_id'  => $boardId,
            ],
        );

        return $affected === 1;
    }

    /**
     * Paginated, board-scoped idea list.
     *
     * @param int         $boardId       Board scoping — mandatory.
     * @param string|null $status        Optional status filter (allow-list validated).
     * @param int         $limit         Maximum number of entries.
     * @param int         $offset        Pagination offset.
     * @param string      $sortKey       Sort axis as a key from SORT_AXES.
     *                                   Unknown keys → DEFAULT_SORT ('newest').
     *                                   Hook for adding a new sort: 'top'.
     * @param int|null    $currentUserId Optional: if set, every idea
     *                                   additionally contains `my_vote` ∈ {'up','down','none'}.
     *                                   No N+1 — correlated subquery per row.
     *                                   Board-/user-scoped via ideas.id of the outer query.
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
        // Sort allow-list: unknown keys → newest fallback.
        // Pinned ideas always appear first, regardless of the chosen axis.
        $orderBy = 'is_pinned DESC, ' . (self::SORT_AXES[$sortKey] ?? self::SORT_AXES[self::DEFAULT_SORT]);

        // Status allow-list: invalid values → no filter (all statuses).
        $validStatus = ($status !== null && in_array($status, self::ALLOWED_STATUSES, true))
            ? $status
            : null;

        // my_vote subquery: only when a user is logged in. Set-based, no N+1.
        $myVoteExpr = $currentUserId !== null
            ? ', COALESCE((SELECT CASE WHEN value > 0 THEN \'up\' WHEN value < 0 THEN \'down\' ELSE \'none\' END FROM votes WHERE idea_id = ideas.id AND user_id = :current_user_id), \'none\') AS my_vote'
            : '';

        $baseSelect = 'SELECT id, board_id, author_id, title, body, status, is_pinned, score_cache, created_at, updated_at, (SELECT COUNT(*) FROM comments WHERE comments.idea_id = ideas.id) AS comment_count, (SELECT COUNT(*) FROM votes WHERE votes.idea_id = ideas.id AND votes.value > 0) AS up_count, (SELECT COUNT(*) FROM votes WHERE votes.idea_id = ideas.id AND votes.value < 0) AS down_count'
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

        foreach ($rows as &$row) {
            $row['is_pinned'] = (bool) ($row['is_pinned'] ?? false);
        }
        unset($row);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Returns the board-scoped roadmap ideas grouped by status
     * (planned / in_progress / done), each group sorted descending by score_cache.
     *
     * No voter PII — no user-specific fields (no my_vote, no author_id).
     * Only public aggregates (score_cache, up_count, down_count, comment_count).
     * Uses idx_ideas_board_status (board_id, status).
     *
     * open and declined do not appear (deliberately excluded).
     *
     * @return array{planned: list<array<string, mixed>>, in_progress: list<array<string, mixed>>, done: list<array<string, mixed>>}
     * @throws DbalException
     */
    public function roadmapByBoard(int $boardId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, title, body, status, score_cache, created_at,
                    (SELECT COUNT(*) FROM comments WHERE comments.idea_id = ideas.id) AS comment_count,
                    (SELECT COUNT(*) FROM votes WHERE votes.idea_id = ideas.id AND votes.value > 0) AS up_count,
                    (SELECT COUNT(*) FROM votes WHERE votes.idea_id = ideas.id AND votes.value < 0) AS down_count
             FROM ideas
             WHERE board_id = :board_id
               AND status IN (\'planned\', \'in_progress\', \'done\')
             ORDER BY
                 CASE status
                     WHEN \'planned\'     THEN 1
                     WHEN \'in_progress\' THEN 2
                     WHEN \'done\'        THEN 3
                     ELSE 4
                 END,
                 score_cache DESC,
                 id DESC',
            ['board_id' => $boardId],
        );

        $grouped = ['planned' => [], 'in_progress' => [], 'done' => []];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $grouped)) {
                $grouped[$status][] = $row;
            }
        }

        return $grouped;
    }

    /**
     * Board-wide metrics for the "This Week" panel (board-scoped, prepared statements).
     *
     * - weekly_votes:     votes on ideas of this board in the last 7 days.
     * - weekly_new_ideas: ideas of this board created in the last 7 days.
     * - avg_consensus:    average approval ratio (up/(up+down)) across all ideas with ≥1 vote,
     *                     as a percentage (0–100, rounded half up); 0 with no votes.
     *
     * The 7-day cutoff is computed in PHP and bound as a parameter — no
     * MySQL-specific INTERVAL, so the portable SQLite test seam behaves identically.
     *
     * @return array{weekly_votes: int, weekly_new_ideas: int, avg_consensus: int, total_ideas: int}
     * @throws DbalException
     */
    public function boardStats(int $boardId): array
    {
        $since = (new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s');

        $weeklyVotes = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM votes v
             JOIN ideas i ON i.id = v.idea_id
             WHERE i.board_id = :board_id AND v.created_at >= :since',
            ['board_id' => $boardId, 'since' => $since],
        );

        $weeklyNewIdeas = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ideas WHERE board_id = :board_id AND created_at >= :since',
            ['board_id' => $boardId, 'since' => $since],
        );

        // Per idea: up/(up+down)*100; COUNT(*) = total votes (each vote ±1).
        // Ideas without votes do not appear in votes → do not factor into the average.
        $avgConsensus = $this->conn->fetchOne(
            'SELECT AVG(consensus) FROM (
                SELECT (SUM(CASE WHEN v.value > 0 THEN 1 ELSE 0 END) * 100.0) / COUNT(*) AS consensus
                FROM votes v
                JOIN ideas i ON i.id = v.idea_id
                WHERE i.board_id = :board_id
                GROUP BY v.idea_id
             ) consensus_per_idea',
            ['board_id' => $boardId],
        );

        return [
            'weekly_votes'     => is_numeric($weeklyVotes) ? (int) $weeklyVotes : 0,
            'weekly_new_ideas' => is_numeric($weeklyNewIdeas) ? (int) $weeklyNewIdeas : 0,
            'avg_consensus'    => is_numeric($avgConsensus) ? (int) round((float) $avgConsensus) : 0,
            // All-time idea count (any status) — backs the owner-facing board
            // milestone celebrations (social-features issue, board momentum),
            // not just the "this week" figures above.
            'total_ideas'      => $this->countByBoard($boardId),
        ];
    }

    /**
     * Board-scoped duplicate-recall pool for the as-you-type duplicate search.
     * This is the RECALL half of "FULLTEXT recall + Jaro–Winkler
     * rerank" — DuplicateDetectionService does the reranking in PHP.
     *
     * MySQL/InnoDB: uses the ft_ideas_title FULLTEXT index (db/schema.sql) in
     * BOOLEAN MODE, each query token trailing-wildcarded, so short/partial
     * as-you-type input still recalls prefix matches (unlike NATURAL LANGUAGE
     * MODE, whose relevance/stopword handling behaves oddly on short input).
     * Terms are combined with the default OR (permissive recall — reranking
     * is what filters false positives, not the recall step).
     *
     * Non-MySQL (SQLite tests, self-host deployments without InnoDB FULLTEXT —
     * see the "PHP-Fallback" note in db/schema.sql's header comment): falls
     * back to the most recent ideas in the board, bounded by $limit. Board
     * sizes on self-hostable Community boards are small enough that this stays
     * cheap, and the Jaro–Winkler rerank still filters it down correctly.
     *
     * @return list<array<string, mixed>> Rows with id, title, title_normalized,
     *                                     status, up_count, down_count.
     * @throws DbalException
     */
    public function findDuplicateCandidates(int $boardId, string $title, int $limit = 30): array
    {
        $voteCountExprs = '(SELECT COUNT(*) FROM votes WHERE votes.idea_id = ideas.id AND votes.value > 0) AS up_count,
                    (SELECT COUNT(*) FROM votes WHERE votes.idea_id = ideas.id AND votes.value < 0) AS down_count';

        if ($this->conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $booleanQuery = $this->buildBooleanSearchQuery($title);
            if ($booleanQuery === '') {
                return [];
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $this->conn->fetchAllAssociative(
                'SELECT id, title, title_normalized, status, ' . $voteCountExprs . ',
                        MATCH(title) AGAINST(:query IN BOOLEAN MODE) AS relevance
                 FROM ideas
                 WHERE board_id = :board_id
                   AND MATCH(title) AGAINST(:query IN BOOLEAN MODE)
                 ORDER BY relevance DESC
                 LIMIT :limit',
                ['board_id' => $boardId, 'query' => $booleanQuery, 'limit' => $limit],
            );

            return $rows;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, title, title_normalized, status, ' . $voteCountExprs . '
             FROM ideas
             WHERE board_id = :board_id
             ORDER BY created_at DESC
             LIMIT :limit',
            ['board_id' => $boardId, 'limit' => $limit],
        );

        return $rows;
    }

    /**
     * Builds a MySQL BOOLEAN MODE FULLTEXT query string from $title: strips
     * everything that isn't a letter/number, drops words under 2 characters,
     * and trailing-wildcards each remaining word for prefix/partial matching.
     * Returns '' if no usable word remains (caller must skip the query then —
     * an empty BOOLEAN MODE query string is a syntax error, not "no match").
     */
    private function buildBooleanSearchQuery(string $title): string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $title, $matches);

        $words = array_filter(
            $matches[0],
            static fn (string $word): bool => mb_strlen($word) >= 2,
        );

        return implode(' ', array_map(static fn (string $word): string => $word . '*', $words));
    }
}
