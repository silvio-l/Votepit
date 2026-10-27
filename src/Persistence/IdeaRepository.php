<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Votepit\Domain\ApprovalDisplay;
use Votepit\Domain\BoardStatuses;
use Votepit\Domain\IdeaSearchQuery;
use Votepit\Domain\Premoderation;
use Votepit\Domain\StatusLabel;
use Votepit\Domain\StatusService;
use Votepit\Domain\VoteThresholds;

/**
 * Idea persistence (arch.md §2 — persistence layer).
 *
 * Prepared-statements-only via DBAL. No query-string concatenation.
 * Strict board scoping: every method binds board_id as a parameter.
 *
 * Sort axis: `listByBoard` takes a $sortKey parameter from the
 * closed allow-list SORT_AXES. Unknown keys → 'newest' (fallback).
 * Hook for adding a new sort: add to SORT_AXES['top'], no API-breaking change.
 *
 * Pre-moderation default scope (broaden-use-cases issue 05, migration
 * 0083): every read in this class returns PUBLISHED ideas only
 * (`moderation_state = 'published'`, see PUBLISHED_ONLY). A pending idea is
 * reachable solely through an explicit opt-in — findInBoard(...,
 * includePending: true) for the author's/a moderator's detail view and the
 * reject action, listPendingByBoard()/countPendingByBoard() for the
 * moderation queue — so any future read path is safe by default. Deliberate
 * exceptions, each documented at the method: listAllForAccount() (owner's
 * self-export, marks the state), findByIdIncludingDeleted() (appeals) and
 * countAll() (operator usage).
 *
 * Status categories (broaden-use-cases issue 03, migration 0073):
 * ideas.status stores a status KEY; read paths LEFT JOIN board_statuses
 * (same statement, no extra query) and return `status` = the canonical
 * category slug (unchanged contract), plus the additive `status_key`,
 * `status_category` and `status_label` (see decorateStatus()). A board
 * without board_statuses rows yields exactly the old values. Status filters
 * match the CATEGORY (`?status=done` = every completed status) or, with
 * $statusKey, one exact key.
 */
final readonly class IdeaRepository
{
    /** Valid status values (allow-list for filtering) = the six category slugs. */
    public const ALLOWED_STATUSES = StatusService::VALID_STATUSES;

    /** Same-statement join of an idea's status row (alias `ideas`). */
    private const STATUS_JOIN = ' LEFT JOIN board_statuses bs ON bs.board_id = ideas.board_id AND bs.status_key = ideas.status';

    /** Canonical category of an idea row (alias `ideas`). */
    private const CATEGORY_EXPR = 'COALESCE(bs.category, ideas.status)';

    /** Extra columns decorateStatus() turns into status_key/_category/_label. */
    private const STATUS_EXTRA_COLS = ', ideas.status AS status_key, bs.label_de AS status_label_de, bs.label_en AS status_label_en';

    /**
     * Closed allow-list of the permitted sort axes.
     * Values are trusted SQL fragments (never user input).
     * Only keys from this map may flow into ORDER BY.
     *
     * @var array<string, string>
     */
    public const SORT_AXES = [
        'newest'       => 'created_at DESC',
        'top'          => 'score_cache DESC, created_at DESC',
        // Controversial (G-11, CONTEXT.md): rank = LEAST(up, down) DESC, then
        // (up + down) DESC; the id tiebreak is appended by the caller. min is 0 for
        // every idea with zero up or zero down votes, so those fall after all
        // controversial ideas, ordered by total. Reads the denormalised
        // up_count/down_count (same row, no extra query). CASE instead of LEAST():
        // the SQLite test schema has no LEAST.
        'controversial' => 'CASE WHEN ideas.up_count < ideas.down_count THEN ideas.up_count ELSE ideas.down_count END DESC, (ideas.up_count + ideas.down_count) DESC',
    ];

    /** Default sort axis (key from SORT_AXES). */
    public const DEFAULT_SORT = 'newest';

    /** Default page size (conservative). */
    public const DEFAULT_PAGE_SIZE = 50;

    /** Default-scope predicate (trusted SQL fragment, never user input). */
    private const PUBLISHED_ONLY = "ideas.moderation_state = 'published'";

    /** An idea waits for an answer from this many days on (owner decision Q-W2b, P-06). */
    public const WAITING_AFTER_DAYS = 3;

    /** Age tier of the worklist: waiting this long or more (age filter and header count). */
    public const LONG_WAIT_AFTER_DAYS = 7;

    /**
     * "Waits for an answer" before the dismissal filter (P-06, the one
     * definition shared by the weekly digest and the worklist): published,
     * not soft-deleted, not merged, the open status CATEGORY (custom statuses
     * of that category included, Q-W3a), and no comment from a member of the
     * idea's account. Trusted SQL, needs the STATUS_JOIN and the `boards b`
     * join in the same statement. The age cut-off is added by the caller.
     * Also the eligibility test for a dismissal (dismissWorklistIdea()).
     */
    private const WAITING_BASE = "ideas.deleted_at IS NULL AND ideas.moderation_state = 'published' AND ideas.merged_into_id IS NULL
        AND " . self::CATEGORY_EXPR . " = 'open'
        AND NOT EXISTS (
            SELECT 1 FROM comments c
            INNER JOIN account_members am ON am.account_id = b.account_id AND am.user_id = c.author_id
            WHERE c.idea_id = ideas.id
        )";

    /**
     * A worklist dismissal (P-06 part B, migration 0113) is "in force" while
     * the status is unchanged since dismissal and no comment (not soft-deleted)
     * was created after it. An idea with an in-force dismissal is hidden from
     * the unanswered and decision-due categories, the digest count and the
     * badge. Votes do not end a dismissal. Trusted SQL, needs the `boards b` join.
     */
    private const DISMISSED_IN_FORCE = "NOT EXISTS (
        SELECT 1 FROM worklist_dismissals wd
        WHERE wd.account_id = b.account_id AND wd.idea_id = ideas.id
          AND ideas.status = wd.status_at_dismissal
          AND NOT EXISTS (
            SELECT 1 FROM comments wdc
            WHERE wdc.idea_id = ideas.id AND wdc.deleted_at IS NULL AND wdc.created_at > wd.dismissed_at
          )
    )";

    /** "Waits for an answer" as the digest and the worklist count it: WAITING_BASE without an in-force dismissal. */
    private const WAITING_PREDICATE = self::WAITING_BASE . " AND " . self::DISMISSED_IN_FORCE;

    /** Category unanswered: WAITING_PREDICATE plus the waiting cut-off (named parameter :waiting_cutoff). */
    private const UNANSWERED_PREDICATE = self::WAITING_PREDICATE . " AND ideas.created_at <= :waiting_cutoff";

    /** Unanswered before the dismissal filter (named parameter :waiting_cutoff): eligibility for a dismissal. */
    private const UNANSWERED_BASE = self::WAITING_BASE . " AND ideas.created_at <= :waiting_cutoff";

    /** Waiting in the pre-moderation queue (P-06 category pending_moderation). Not dismissable. */
    private const PENDING_PREDICATE = "ideas.deleted_at IS NULL AND ideas.moderation_state = 'pending'";

    /**
     * Decision due before the dismissal filter (P-06 category, P-02 "enough"
     * rule): open category, published, not merged, and at least
     * FEW_VOTES_THRESHOLD votes from at least FEW_VOTERS_THRESHOLD voters.
     * Voters = up + down (one row per identity).
     */
    private const DECISION_DUE_BASE = "ideas.deleted_at IS NULL AND ideas.moderation_state = 'published' AND ideas.merged_into_id IS NULL
        AND " . self::CATEGORY_EXPR . " = 'open'
        AND (ideas.up_count + ideas.down_count) >= " . VoteThresholds::FEW_VOTES_THRESHOLD . "
        AND (ideas.up_count + ideas.down_count) >= " . VoteThresholds::FEW_VOTERS_THRESHOLD;

    /** Decision due (P-06 category) without an in-force dismissal. */
    private const DECISION_DUE_PREDICATE = self::DECISION_DUE_BASE . " AND " . self::DISMISSED_IN_FORCE;

    /** Upper bound of one moderation-queue page (abuse guard, not a product limit). */
    public const QUEUE_PAGE_SIZE = 100;

    public function __construct(private Connection $conn) {}

    /**
     * Creates a new idea, board-scoped (prepared statement, no string concatenation).
     *
     * Status starts at the schema default 'open'. `title_normalized` is set by
     * the caller (IdeaCreateAction via TitleNormalizer) — no fork of the
     * normalization logic here.
     *
     * $moderationState: 'published' (default — behaviour as before 0083) or
     * 'pending' (pre-moderated board, author is no moderator).
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
        string $moderationState = Premoderation::STATE_PUBLISHED,
        ?string $anonAuthorTokenHash = null,
    ): int {
        $this->conn->executeStatement(
            'INSERT INTO ideas (board_id, author_id, anon_author_token_hash, title, title_normalized, body, status, moderation_state, created_at, updated_at)
             VALUES (:board_id, :author_id, :anon_author_token_hash, :title, :title_normalized, :body, \'open\', :moderation_state, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            [
                'board_id'         => $boardId,
                'author_id'        => $authorId,
                'anon_author_token_hash' => $anonAuthorTokenHash,
                'title'            => $title,
                'title_normalized' => $titleNormalized,
                'body'             => $body,
                'moderation_state' => $moderationState === Premoderation::STATE_PENDING ? Premoderation::STATE_PENDING : Premoderation::STATE_PUBLISHED,
            ],
        );

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Creates one idea from a board import (BoardImportService) — board-
     * scoped, prepared statement, same table/defaults as create(). Differs
     * only in what the import carries over: status, the original creation
     * time (also used as updated_at) and the vote-count baseline, which
     * seeds both imported_vote_count and score_cache (migrations/0077:
     * score_cache == imported_vote_count + SUM(votes.value)). No votes rows
     * and no users are created. Every column not named here (moderation
     * state, counters, …) keeps its schema default exactly like create().
     *
     * @throws DbalException
     * @return int The new idea ID.
     */
    public function createImported(
        int $boardId,
        int $authorId,
        int $importId,
        string $title,
        string $titleNormalized,
        string $body,
        string $status,
        \DateTimeImmutable $createdAt,
        int $importedVotes,
    ): int {
        $this->conn->executeStatement(
            'INSERT INTO ideas (board_id, author_id, title, title_normalized, body, status, score_cache, imported_vote_count, import_id, created_at, updated_at)
             VALUES (:board_id, :author_id, :title, :title_normalized, :body, :status, :score, :votes, :import_id, :created_at, :updated_at)',
            [
                'board_id'         => $boardId,
                'author_id'        => $authorId,
                'title'            => $title,
                'title_normalized' => $titleNormalized,
                'body'             => $body,
                'status'           => in_array($status, self::ALLOWED_STATUSES, true) ? $status : 'open',
                'score'            => max(0, $importedVotes),
                'votes'            => max(0, $importedVotes),
                'import_id'        => $importId,
                'created_at'       => $createdAt->format('Y-m-d H:i:s'),
                'updated_at'       => $createdAt->format('Y-m-d H:i:s'),
            ],
        );

        return (int) $this->conn->lastInsertId();
    }

    /**
     * All title_normalized keys of a board's non-deleted ideas — the board
     * import's duplicate check (one query per import, no per-row lookup).
     *
     * @return array<string, true>
     * @throws DbalException
     */
    public function normalizedTitlesForBoard(int $boardId): array
    {
        $keys = $this->conn->fetchFirstColumn(
            'SELECT title_normalized FROM ideas WHERE board_id = :board_id AND deleted_at IS NULL',
            ['board_id' => $boardId],
        );

        $set = [];
        foreach ($keys as $key) {
            $set[(string) $key] = true;
        }

        return $set;
    }

    /**
     * Counts ideas of a board (board-scoped), optionally filtered by status.
     *
     * Used for pagination calculation, paired with listByBoard() — which
     * always excludes soft-deleted (moderated) ideas, board-operator-
     * compliance issue 07 — so this count excludes them too, or the
     * computed page total would overshoot what listByBoard() actually
     * returns.
     *
     * @throws DbalException
     */
    public function countByBoard(int $boardId, ?string $status = null, ?string $statusKey = null): int
    {
        $validStatus = ($status !== null && in_array($status, self::ALLOWED_STATUSES, true))
            ? $status
            : null;
        $validKey = $this->validKey($statusKey);

        if ($validKey !== null) {
            $count = $this->conn->fetchOne(
                'SELECT COUNT(*) FROM ideas WHERE ideas.board_id = :board_id AND ideas.status = :status_key AND ideas.deleted_at IS NULL AND ' . self::PUBLISHED_ONLY . ' AND ideas.merged_into_id IS NULL',
                ['board_id' => $boardId, 'status_key' => $validKey],
            );
        } elseif ($validStatus !== null) {
            $count = $this->conn->fetchOne(
                'SELECT COUNT(*) FROM ideas' . self::STATUS_JOIN . '
                 WHERE ideas.board_id = :board_id AND ' . self::CATEGORY_EXPR . ' = :status AND ideas.deleted_at IS NULL AND ' . self::PUBLISHED_ONLY . ' AND ideas.merged_into_id IS NULL',
                ['board_id' => $boardId, 'status' => $validStatus],
            );
        } else {
            $count = $this->conn->fetchOne(
                'SELECT COUNT(*) FROM ideas WHERE ideas.board_id = :board_id AND ideas.deleted_at IS NULL AND ' . self::PUBLISHED_ONLY . ' AND ideas.merged_into_id IS NULL',
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
            // Category match: a custom "completed" status counts as shipped.
            $count = $this->conn->fetchOne(
                'SELECT COUNT(*) FROM ideas
                 INNER JOIN boards b ON b.id = ideas.board_id' . self::STATUS_JOIN . '
                 WHERE b.account_id = :account_id AND ideas.author_id = :author_id AND ' . self::CATEGORY_EXPR . ' = :status AND ' . self::PUBLISHED_ONLY,
                ['account_id' => $accountId, 'author_id' => $authorId, 'status' => $validStatus],
            );
        } else {
            $count = $this->conn->fetchOne(
                'SELECT COUNT(*) FROM ideas
                 INNER JOIN boards b ON b.id = ideas.board_id
                 WHERE b.account_id = :account_id AND ideas.author_id = :author_id AND ' . self::PUBLISHED_ONLY,
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
     * Deliberately NOT filtered by deleted_at — a self-export must include
     * an account's own moderated ideas too (deleted_at/moderation_action_id
     * are returned so the export reflects that state), same as
     * CommentRepository::listForAccount(). Likewise NOT default-scoped to
     * published ideas (the exporting owner is a moderator and may see
     * pending ideas anyway) — `moderation_state` marks them instead.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listAllForAccount(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT ideas.id, ideas.board_id, ideas.author_id, ideas.title, ideas.title_normalized, ideas.body, ' . self::CATEGORY_EXPR . ' AS status,
                    ideas.start_date, ideas.target_date,
                    ideas.is_pinned, ideas.merged_into_id, ideas.merged_at, ideas.merged_by, ideas.score_cache, ideas.view_count, ideas.created_at, ideas.updated_at,
                    ideas.deleted_at, ideas.moderation_action_id, ideas.moderation_state, ideas.approved_at, ideas.approved_by, ideas.imported_vote_count, ideas.import_id' . self::STATUS_EXTRA_COLS . '
             FROM ideas
             INNER JOIN boards b ON b.id = ideas.board_id' . self::STATUS_JOIN . '
             WHERE b.account_id = :account_id
             ORDER BY ideas.id ASC',
            ['account_id' => $accountId],
        );

        return array_map(self::decorateStatus(...), $rows);
    }

    /**
     * Returns a single idea (board-scoped, prepared statement).
     *
     * Returns null if the idea is unknown, does not belong to this board
     * (cross-board leak structurally excluded), OR has already been
     * soft-deleted by moderation — a moderated idea is thereby structurally
     * unreachable for editing/voting/commenting/re-moderating alike (404,
     * same "invisible = not found" convention CommentRepository::
     * findForIdea() already established), board-operator-compliance
     * issue 07.
     *
     * A merged duplicate (issue 10) is likewise null unless $includeMerged —
     * so every vote/comment/edit/status action 404s on it by default. Only
     * read paths that must point an old link at the canonical idea (detail)
     * and the unmerge action opt in; the row then carries merged_into_id.
     *
     * If $currentUserId is set, the result additionally contains the key
     * `my_vote` ∈ {'up','down','none'} — determined via a correlated subquery
     * (set-based, no N+1 problem, user- and board-scoped via ideas.id).
     *
     * Pre-moderation: a pending idea is excluded by default (null → 404 for
     * every existing caller). $includePending is the explicit opt-in for the
     * two callers that legitimately address a pending idea — the detail
     * view (which itself then restricts it to author + moderators) and the
     * moderator's reject action. Only then is `moderation_state` part of
     * the returned row, so every default payload stays byte-identical.
     */
    /**
     * Loads an idea by ID regardless of its soft-delete state — unlike
     * findInBoard() (WHERE deleted_at IS NULL), a moderated (soft-deleted)
     * idea is still returned here. Board-operator-compliance issue 09's
     * appeal path needs this: ModerationAppealAction::submit() must confirm
     * "does the caller own the removed content?" for content that is, by
     * definition, already soft-deleted at that point.
     *
     * Deliberately NOT board-scoped (unlike every other lookup in this
     * class) — the caller already trusts board_id/account_id from the
     * content_moderation_actions row it looked the entity up through, and
     * scoping on id alone keeps this a plain by-id lookup.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findByIdIncludingDeleted(int $id): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, board_id, author_id, title, body, status, created_at, deleted_at
             FROM ideas
             WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findInBoard(int $boardId, int $id, ?int $currentUserId = null, bool $includePending = false, bool $includeMerged = false): ?array
    {
        $stateColumn = $includePending ? ', ideas.moderation_state' : '';
        $stateClause = $includePending ? '' : ' AND ' . self::PUBLISHED_ONLY;

        $myVoteExpr = $currentUserId !== null
            ? ', COALESCE((SELECT CASE WHEN value > 0 THEN \'up\' WHEN value < 0 THEN \'down\' ELSE \'none\' END FROM votes WHERE idea_id = ideas.id AND user_id = :current_user_id), \'none\') AS my_vote'
            : '';

        $params = ['board_id' => $boardId, 'id' => $id];
        if ($currentUserId !== null) {
            $params['current_user_id'] = $currentUserId;
        }

        $row = $this->conn->fetchAssociative(
            'SELECT ideas.id, ideas.board_id, ideas.author_id, ideas.title, ideas.body, ' . self::CATEGORY_EXPR . ' AS status, ideas.is_pinned, ideas.score_cache, ideas.view_count, ideas.created_at, ideas.updated_at, ideas.comment_count, ideas.up_count, ideas.down_count, (ideas.up_count + ideas.down_count) AS voter_count, ideas.merged_into_id'
            . $stateColumn
            . $myVoteExpr
            . ', (SELECT COUNT(*) FROM idea_status_decisions d WHERE d.idea_id = ideas.id) AS decision_count'
            . self::STATUS_EXTRA_COLS
            . '
             FROM ideas' . self::STATUS_JOIN . '
             WHERE ideas.board_id = :board_id AND ideas.id = :id AND ideas.deleted_at IS NULL' . $stateClause
            . ($includeMerged ? '' : ' AND ideas.merged_into_id IS NULL'),
            $params,
        );

        if ($row === false) {
            return null;
        }

        $row = self::decorateStatus($row);

        $row['is_pinned']      = (bool) ($row['is_pinned'] ?? false);
        $row['view_count']     = (int) ($row['view_count'] ?? 0);
        $row['merged_into_id'] = is_numeric($row['merged_into_id'] ?? null) ? (int) $row['merged_into_id'] : null;

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
     * Sets an idea's Roadmap Gantt dates (board-scoped, admin mutation,
     * prepared statement). Gate this behind PlanPolicy::roadmapGanttAllowed()
     * in the calling action — the repository itself does not know about plans.
     *
     * Binds id AND board_id as parameters — cross-board mutation structurally
     * excluded (defense in depth beyond the action guard).
     * Returns: true if exactly one row was changed, false otherwise.
     *
     * @param string|null $startDate  'Y-m-d' or null to clear.
     * @param string|null $targetDate 'Y-m-d' or null to clear.
     * @throws DbalException
     */
    public function updateDates(int $boardId, int $id, ?string $startDate, ?string $targetDate): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE ideas SET start_date = :start_date, target_date = :target_date, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND board_id = :board_id',
            [
                'start_date'  => $startDate,
                'target_date' => $targetDate,
                'id'          => $id,
                'board_id'    => $boardId,
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
     * Soft-deletes an idea (board-scoped, moderation mutation,
     * board-operator-compliance issue 07 — same shape as
     * CommentRepository::softDelete()). Stamps deleted_at and links
     * moderation_action_id back to the content_moderation_actions row that
     * recorded the reason/snapshot, so the removal is retained internally
     * (Art. 17 appeal review) while every public read path
     * (WHERE deleted_at IS NULL) stops showing it.
     *
     * Only affects a row that isn't already soft-deleted — moderating an
     * already-moderated idea a second time is a no-op here (the caller,
     * IdeaModerationAction, never reaches this: findInBoard() already
     * excludes soft-deleted ideas, so a repeat attempt 404s before this
     * method is ever called).
     *
     * Binds id AND board_id as parameters — an idea of a foreign board is
     * structurally never touched (defense in depth beyond the action
     * guard, analogous to setPinned()).
     * Returns: true if exactly one row was updated, false otherwise.
     *
     * @throws DbalException
     */
    public function softDelete(int $boardId, int $ideaId, int $moderationActionId): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE ideas
             SET deleted_at = CURRENT_TIMESTAMP, moderation_action_id = :moderation_action_id
             WHERE id = :id AND board_id = :board_id AND deleted_at IS NULL',
            ['id' => $ideaId, 'board_id' => $boardId, 'moderation_action_id' => $moderationActionId],
        );

        return $affected === 1;
    }

    /**
     * Moderation queue (explicit opt-in to pending ideas, pre-moderation
     * issue 05): the board's pending, not soft-deleted ideas, oldest first,
     * capped at QUEUE_PAGE_SIZE. Board-scoped like every other read here —
     * the caller resolves the board account-scoped first.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listPendingByBoard(int $boardId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, author_id, title, body, created_at
             FROM ideas
             WHERE board_id = :board_id AND moderation_state = \'pending\' AND deleted_at IS NULL
             ORDER BY created_at ASC, id ASC
             LIMIT :limit',
            ['board_id' => $boardId, 'limit' => self::QUEUE_PAGE_SIZE],
        );

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Guest ideas (ADR 0006, migrations/0084_add_anonymous_idea_submission.sql)
    // -------------------------------------------------------------------------

    /** Pending guest ideas on one board — the queue cap (ADR 0006 §5); members' pending ideas never count. */
    public function countPendingGuestIdeas(int $boardId, int $guestUserId): int
    {
        $count = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ideas
             WHERE board_id = :board_id AND author_id = :guest AND moderation_state = \'pending\' AND deleted_at IS NULL',
            ['board_id' => $boardId, 'guest' => $guestUserId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * "My ideas on this device" (ADR 0006 §7): this board's guest ideas
     * carrying the device's account-bound hash — pending, published and
     * moderator-rejected (soft-deleted with a moderation action; the reason
     * code comes along, never the free-text note). Merged ideas are not
     * special-cased: they stay "published". Newest first.
     *
     * @return list<array{id: int, title: string, moderation_state: string, status: string, rejected: bool, reason_code: string|null, created_at: string}>
     */
    public function listGuestIdeasForDevice(int $boardId, int $guestUserId, string $tokenHash, int $limit): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT i.id, i.title, i.moderation_state, i.status, i.deleted_at, i.created_at, cma.reason_code
             FROM ideas i
             LEFT JOIN content_moderation_actions cma ON cma.id = i.moderation_action_id
             WHERE i.board_id = :board_id AND i.author_id = :guest AND i.anon_author_token_hash = :hash
                   AND (i.deleted_at IS NULL OR i.moderation_action_id IS NOT NULL)
             ORDER BY i.created_at DESC, i.id DESC
             LIMIT :limit',
            ['board_id' => $boardId, 'guest' => $guestUserId, 'hash' => $tokenHash, 'limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'id'               => (int) $row['id'],
            'title'            => (string) $row['title'],
            'moderation_state' => (string) $row['moderation_state'],
            'status'           => (string) $row['status'],
            'rejected'         => $row['deleted_at'] !== null,
            'reason_code'      => $row['deleted_at'] !== null && is_string($row['reason_code']) ? $row['reason_code'] : null,
            'created_at'       => (string) $row['created_at'],
        ], $rows);
    }

    /**
     * Guest withdraw (ADR 0006 §7): hard-deletes the idea only if it is
     * this board's, a guest idea, carries this device's hash and is still
     * pending. Anything else affects no row — the caller answers 404 without
     * revealing which condition failed.
     */
    public function deletePendingGuestIdea(int $boardId, int $ideaId, int $guestUserId, string $tokenHash): bool
    {
        $affected = $this->conn->executeStatement(
            'DELETE FROM ideas
             WHERE id = :id AND board_id = :board_id AND author_id = :guest AND anon_author_token_hash = :hash
                   AND moderation_state = \'pending\' AND deleted_at IS NULL',
            ['id' => $ideaId, 'board_id' => $boardId, 'guest' => $guestUserId, 'hash' => $tokenHash],
        );

        return $affected === 1;
    }

    /**
     * Pending guest ideas of one board for the bulk reject (ADR 0006 §5a) —
     * members' ideas are never included.
     *
     * @return list<array{id: int, title: string, body: string}>
     */
    public function listPendingGuestIdeas(int $boardId, int $guestUserId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, title, body FROM ideas
             WHERE board_id = :board_id AND author_id = :guest AND moderation_state = \'pending\' AND deleted_at IS NULL
             ORDER BY id ASC',
            ['board_id' => $boardId, 'guest' => $guestUserId],
        );

        return array_map(static fn (array $row): array => [
            'id'    => (int) $row['id'],
            'title' => (string) $row['title'],
            'body'  => (string) $row['body'],
        ], $rows);
    }

    /**
     * Retention (ADR 0006 §10, review 5b): after the cookie lifetime the
     * device hash is nulled, so the idea can no longer be tied to a device.
     *
     * @return int Rows updated.
     */
    public function clearAnonAuthorHashesCreatedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->conn->executeStatement(
            'UPDATE ideas SET anon_author_token_hash = NULL
             WHERE anon_author_token_hash IS NOT NULL AND created_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Pending-count badge for the board admin (explicit opt-in, board-scoped).
     *
     * @throws DbalException
     */
    public function countPendingByBoard(int $boardId): int
    {
        $count = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ideas WHERE board_id = :board_id AND moderation_state = \'pending\' AND deleted_at IS NULL',
            ['board_id' => $boardId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Publishes a pending idea (board-scoped moderator mutation). The
     * conditional UPDATE (only a still-pending, not soft-deleted row of THIS
     * board) is what makes approval idempotent: the caller fires the
     * publish side effects (webhook, notification) only when this returns
     * true, so they happen exactly once even on a double submit.
     *
     * @throws DbalException
     */
    public function approve(int $boardId, int $ideaId, int $approverId): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE ideas
             SET moderation_state = \'published\', approved_at = CURRENT_TIMESTAMP, approved_by = :approved_by
             WHERE id = :id AND board_id = :board_id AND moderation_state = \'pending\' AND deleted_at IS NULL',
            ['id' => $ideaId, 'board_id' => $boardId, 'approved_by' => $approverId],
        );

        return $affected === 1;
    }

    /**
     * Physically deletes an already-moderated (soft-deleted) idea row —
     * board-operator-compliance issue 08's purge job, the counterpart to
     * softDelete() above (same shape as CommentRepository::hardDelete()).
     * Only ever affects a row that is already soft-deleted (WHERE
     * deleted_at IS NOT NULL) — a defense-in-depth backstop so this can
     * never be pointed at a live, un-moderated idea even if called with a
     * wrong ID.
     *
     * The `content_moderation_actions` row that recorded this idea's
     * removal is NOT touched here (its content_snapshot is cleared
     * separately by ModerationRepository::clearSnapshot() — the row itself
     * stays, it is the audit trail / repeat-offender counter). Deleting the
     * idea cascades its votes/comments (ON DELETE CASCADE) — those were
     * already inaccessible via the soft-deleted idea's own read-path
     * exclusion, so this does not expose or hide anything new.
     * Returns: true if exactly one row was deleted, false otherwise (e.g.
     * already purged, or was never soft-deleted).
     *
     * @throws DbalException
     */
    public function hardDelete(int $ideaId): bool
    {
        $affected = $this->conn->executeStatement(
            'DELETE FROM ideas WHERE id = :id AND deleted_at IS NOT NULL',
            ['id' => $ideaId],
        );

        return $affected === 1;
    }

    /**
     * Paginated, board-scoped idea list.
     *
     * @param int                  $boardId       Board scoping — mandatory.
     * @param string|null          $status        Optional status filter (allow-list validated).
     * @param int                  $limit         Maximum number of entries.
     * @param int                  $offset        Pagination offset.
     * @param string               $sortKey       Sort axis as a key from SORT_AXES.
     *                                            Unknown keys → DEFAULT_SORT ('newest').
     *                                            Hook for adding a new sort: 'top'.
     * @param int|null             $currentUserId Optional: if set, every idea
     *                                            additionally contains `my_vote` ∈ {'up','down','none'}.
     *                                            No N+1 — correlated subquery per row.
     *                                            Board-/user-scoped via ideas.id of the outer query.
     * @param IdeaSearchQuery|null $search        Optional free-text title search (board search,
     *                                            competitor-parity H6) — see listWhere().
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
        ?IdeaSearchQuery $search = null,
        ?string $statusKey = null,
        ?int $tagId = null,
        bool $excludeClosed = false,
    ): array {
        // Sort allow-list: unknown keys → newest fallback.
        // Pinned ideas always appear first, regardless of the chosen axis.
        // The id tiebreak makes the order total, so LIMIT/OFFSET pages never repeat or skip an idea.
        $orderBy = 'is_pinned DESC, ' . (self::SORT_AXES[$sortKey] ?? self::SORT_AXES[self::DEFAULT_SORT]) . ', ideas.id DESC';

        // my_vote subquery: only when a user is logged in. Set-based, no N+1.
        $myVoteExpr = $currentUserId !== null
            ? ', COALESCE((SELECT CASE WHEN value > 0 THEN \'up\' WHEN value < 0 THEN \'down\' ELSE \'none\' END FROM votes WHERE idea_id = ideas.id AND user_id = :current_user_id), \'none\') AS my_vote'
            : '';

        [$where, $params] = $this->listWhere($boardId, $status, $search, $statusKey, $tagId, $excludeClosed);
        $params['limit']  = $limit;
        $params['offset'] = $offset;
        if ($currentUserId !== null) {
            $params['current_user_id'] = $currentUserId;
        }

        $rows = $this->conn->fetchAllAssociative(
            'SELECT ideas.id, ideas.board_id, ideas.author_id, ideas.title, ideas.body, ' . self::CATEGORY_EXPR . ' AS status, ideas.is_pinned, ideas.score_cache, ideas.created_at, ideas.updated_at, ideas.comment_count, ideas.up_count, ideas.down_count, (ideas.up_count + ideas.down_count) AS voter_count'
            . $myVoteExpr
            . ', (SELECT d.reason IS NOT NULL FROM idea_status_decisions d WHERE d.idea_id = ideas.id ORDER BY d.id DESC LIMIT 1) AS has_decision_reason'
            . self::STATUS_EXTRA_COLS . '
             FROM ideas' . self::STATUS_JOIN . '
             WHERE ' . $where . '
             ORDER BY ' . $orderBy . '
             LIMIT :limit OFFSET :offset',
            $params,
        );

        foreach ($rows as &$row) {
            $row['is_pinned'] = (bool) ($row['is_pinned'] ?? false);
            // P-01: the latest status decision carries a public reason (flag only, no text in lists).
            $row['has_decision_reason'] = (bool) ($row['has_decision_reason'] ?? false);
            $row              = self::decorateStatus($row);
        }
        unset($row);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Every idea of the board results export (broaden-use-cases issue 07):
     * the same WHERE clause as listByBoard()/countListed() (listWhere():
     * board scope, not deleted, published only, not merged into another
     * idea, status/category filter,
     * archived hidden unless filtered), but unpaginated and without the
     * pinned-first rule — a results document is ordered purely by the
     * chosen axis (default 'top' = score), ties broken by id.
     *
     * Only public aggregates and content — no author_id, no voter data.
     * Counters are the denormalised up_count/down_count/comment_count, so
     * this is ONE statement regardless of idea count.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listForResultsExport(int $boardId, ?string $status, ?string $statusKey, string $sortKey, string $locale = 'en'): array
    {
        $orderBy = (self::SORT_AXES[$sortKey] ?? self::SORT_AXES['top']) . ', ideas.id DESC';

        [$where, $params] = $this->listWhere($boardId, $status, null, $statusKey);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT ideas.id, ideas.title, ideas.body, ' . self::CATEGORY_EXPR . ' AS status, ideas.score_cache, ideas.up_count, ideas.down_count, ideas.comment_count, ideas.created_at, ideas.target_date'
            . self::STATUS_EXTRA_COLS . '
             FROM ideas' . self::STATUS_JOIN . '
             WHERE ' . $where . '
             ORDER BY ' . $orderBy,
            $params,
        );

        /** @var list<array<string, mixed>> $decorated */
        $decorated = array_map(static fn (array $row): array => self::decorateStatus($row, $locale), $rows);

        return $decorated;
    }

    /**
     * Counts exactly the ideas listByBoard() pages through for the same
     * status/search arguments (pagination total of the board home).
     *
     * Unlike countByBoard() — the all-time board total behind boardStats() —
     * this applies listByBoard()'s default-view rules (archived hidden unless
     * explicitly filtered) and the optional search, so a search never
     * reports pages that would come back empty.
     *
     * @throws DbalException
     */
    /**
     * Board tags with the number of ideas each one lists in the default scope
     * (no status, search or tag filter: archived hidden, like the board's "All").
     * One statement; the board id is an int literal, every other value is the
     * shared listWhere() fragment.
     *
     * @return list<array{id: int, name: string, color: string, count: int}>
     * @throws DbalException
     */
    public function tagsWithCountsByBoard(int $boardId): array
    {
        [$where, $params] = $this->listWhere($boardId, null, null);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT it.id, it.name, it.color,
                    (SELECT COUNT(*) FROM ideas' . self::STATUS_JOIN . ' WHERE ' . $where . '
                        AND EXISTS (SELECT 1 FROM idea_tag_assignments ta WHERE ta.idea_id = ideas.id AND ta.tag_id = it.id)) AS idea_count
               FROM idea_tags it
              WHERE it.board_id = ' . $boardId . '
              ORDER BY it.name ASC',
            $params,
        );

        return array_map(
            static fn (array $r): array => [
                'id'    => (int) $r['id'],
                'name'  => (string) $r['name'],
                'color' => (string) $r['color'],
                'count' => (int) $r['idea_count'],
            ],
            $rows,
        );
    }

    public function countListed(int $boardId, ?string $status = null, ?IdeaSearchQuery $search = null, ?string $statusKey = null, ?int $tagId = null, bool $excludeClosed = false): int
    {
        [$where, $params] = $this->listWhere($boardId, $status, $search, $statusKey, $tagId, $excludeClosed);

        $count = $this->conn->fetchOne('SELECT COUNT(*) FROM ideas' . self::STATUS_JOIN . ' WHERE ' . $where, $params);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Shared WHERE clause of listByBoard()/countListed(). Every fragment is a
     * trusted literal; all values (board id, status, search terms) are bound.
     *
     * - board_id: board scoping, always bound (the board itself was resolved
     *   through the account-scoped chokepoint by the caller).
     * - merged_into_id IS NULL: a merged duplicate (issue 10) is hidden from
     *   every list, search and count; only its canonical idea is shown.
     * - deleted_at IS NULL: a soft-deleted (moderated) idea, board-operator-
     *   compliance issue 07, is excluded regardless of any filter —
     *   moderation removal, unlike archiving, is never opted back into.
     * - PUBLISHED_ONLY (pre-moderation, migration 0083): a pending idea is
     *   excluded from list, search and count alike — no opt-in here.
     * - status (broaden-use-cases 03): $statusKey (pattern-validated) filters
     *   one exact status key and wins; otherwise $status (allow-list
     *   validated) filters the CATEGORY, i.e. every status of it. Neither →
     *   the 'archived' CATEGORY is hidden (resource-conscious archive —
     *   visible only via the explicit ?status=archived query). Category
     *   expressions need the caller's STATUS_JOIN (alias `bs`), so every
     *   column here is qualified with `ideas.`.
     * - search (H6): every term must match the title. MySQL: FULLTEXT-
     *   indexable terms go through one MATCH() … AGAINST() BOOLEAN MODE
     *   query on ft_ideas_title (required prefix terms), the rest — and every
     *   term on other platforms (SQLite tests) — as a LIKE substring match.
     *   See IdeaSearchQuery for the partitioning.
     *
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function listWhere(int $boardId, ?string $status, ?IdeaSearchQuery $search, ?string $statusKey = null, ?int $tagId = null, bool $excludeClosed = false): array
    {
        $conditions = ['ideas.board_id = :board_id', 'ideas.deleted_at IS NULL', self::PUBLISHED_ONLY, 'ideas.merged_into_id IS NULL'];
        $params     = ['board_id' => $boardId];
        $validKey   = $this->validKey($statusKey);

        if ($validKey !== null) {
            $conditions[]     = 'ideas.status = :status';
            $params['status'] = $validKey;
        } elseif ($status !== null && in_array($status, self::ALLOWED_STATUSES, true)) {
            $conditions[]     = self::CATEGORY_EXPR . ' = :status';
            $params['status'] = $status;
        } elseif ($excludeClosed) {
            // Active list (G-09 owner change): everything except the closed categories.
            $conditions[] = self::CATEGORY_EXPR . ' NOT IN (\'done\', \'declined\', \'archived\')';
        } else {
            $conditions[] = self::CATEGORY_EXPR . ' != \'archived\'';
        }

        // Tag filter (G-09): the tag must belong to THIS board, so a foreign tag id matches nothing.
        if ($tagId !== null) {
            $conditions[]     = 'EXISTS (SELECT 1 FROM idea_tag_assignments ta INNER JOIN idea_tags it ON it.id = ta.tag_id'
                . ' WHERE ta.idea_id = ideas.id AND it.id = :tag_id AND it.board_id = ideas.board_id)';
            $params['tag_id'] = $tagId;
        }

        if ($search instanceof \Votepit\Domain\IdeaSearchQuery) {
            $substringTerms = $search->terms();

            if ($this->conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $fulltext = $search->fulltextBooleanQuery();
                if ($fulltext !== '') {
                    $conditions[]               = 'MATCH(ideas.title) AGAINST(:search_fulltext IN BOOLEAN MODE)';
                    $params['search_fulltext'] = $fulltext;
                }
                $substringTerms = $search->substringTerms();
            }

            foreach ($substringTerms as $i => $term) {
                $conditions[]              = 'ideas.title LIKE :search_term_' . $i;
                $params['search_term_' . $i] = IdeaSearchQuery::likePattern($term);
            }
        }

        return [implode(' AND ', $conditions), $params];
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
        // Grouped by CATEGORY: a custom status in planned/active/completed
        // lands in that column.
        $rows = $this->conn->fetchAllAssociative(
            'SELECT ideas.id, ideas.title, ideas.body, ' . self::CATEGORY_EXPR . ' AS status, ideas.score_cache, ideas.created_at, ideas.start_date, ideas.target_date,
                    ideas.comment_count, ideas.up_count, ideas.down_count, (ideas.up_count + ideas.down_count) AS voter_count' . self::STATUS_EXTRA_COLS . '
             FROM ideas' . self::STATUS_JOIN . '
             WHERE ideas.board_id = :board_id
               AND ' . self::CATEGORY_EXPR . ' IN (\'planned\', \'in_progress\', \'done\')
               AND ideas.deleted_at IS NULL
               AND ' . self::PUBLISHED_ONLY . '
               AND ideas.merged_into_id IS NULL
             ORDER BY
                 CASE ' . self::CATEGORY_EXPR . '
                     WHEN \'planned\'     THEN 1
                     WHEN \'in_progress\' THEN 2
                     WHEN \'done\'        THEN 3
                     ELSE 4
                 END,
                 ideas.score_cache DESC,
                 ideas.id DESC',
            ['board_id' => $boardId],
        );

        $grouped = ['planned' => [], 'in_progress' => [], 'done' => []];
        foreach ($rows as $row) {
            $row    = self::decorateStatus($row);
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
     * Moderated (soft-deleted) ideas are excluded from every figure, matching
     * total_ideas — a removed idea must not keep showing up as board activity.
     *
     * - weekly_votes:     votes on ideas of this board in the last 7 days.
     * - weekly_new_ideas: ideas of this board created in the last 7 days.
     * - avg_consensus:    average approval ratio (up/(up+down)) across all ideas with ≥1 vote,
     *                     as a percentage (0–100, rounded half up); 0 with no votes.
     *
     * The 7-day cutoff is computed in PHP and bound as a parameter — no
     * MySQL-specific INTERVAL, so the portable SQLite test seam behaves identically.
     * $since overrides that cutoff (the weekly owner digest passes its own
     * run clock, so stats and highlights share one window).
     *
     * @return array{weekly_votes: int, weekly_new_ideas: int, avg_consensus: int, total_ideas: int}
     * @throws DbalException
     */
    public function boardStats(int $boardId, ?\DateTimeImmutable $since = null): array
    {
        $since = ($since ?? new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s');

        $weeklyVotes = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM votes v
             JOIN ideas ON ideas.id = v.idea_id
             WHERE ideas.board_id = :board_id AND ideas.deleted_at IS NULL AND ' . self::PUBLISHED_ONLY . ' AND v.created_at >= :since',
            ['board_id' => $boardId, 'since' => $since],
        );

        $weeklyNewIdeas = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ideas WHERE ideas.board_id = :board_id AND ideas.deleted_at IS NULL AND ' . self::PUBLISHED_ONLY . ' AND ideas.created_at >= :since',
            ['board_id' => $boardId, 'since' => $since],
        );

        // Per idea: up/(up+down)*100; COUNT(*) = total votes (each vote ±1).
        // Ideas without votes do not appear in votes → do not factor into the average.
        $approvalRow = $this->conn->fetchAssociative(
            'SELECT AVG(consensus) AS avg_approval, COALESCE(SUM(ups), 0) AS ups, COALESCE(SUM(downs), 0) AS downs FROM (
                SELECT (SUM(CASE WHEN v.value > 0 THEN 1 ELSE 0 END) * 100.0) / COUNT(*) AS consensus,
                       SUM(CASE WHEN v.value > 0 THEN 1 ELSE 0 END) AS ups,
                       SUM(CASE WHEN v.value < 0 THEN 1 ELSE 0 END) AS downs
                FROM votes v
                JOIN ideas ON ideas.id = v.idea_id
                WHERE ideas.board_id = :board_id AND ideas.deleted_at IS NULL AND ' . self::PUBLISHED_ONLY . ' AND ideas.merged_into_id IS NULL
                GROUP BY v.idea_id
             ) consensus_per_idea',
            ['board_id' => $boardId],
        );
        $avgApproval = is_array($approvalRow) && is_numeric($approvalRow['avg_approval'] ?? null)
            ? (float) $approvalRow['avg_approval']
            : null;

        return [
            'weekly_votes'     => is_numeric($weeklyVotes) ? (int) $weeklyVotes : 0,
            'weekly_new_ideas' => is_numeric($weeklyNewIdeas) ? (int) $weeklyNewIdeas : 0,
            'avg_consensus'    => $avgApproval === null ? 0 : ApprovalDisplay::percent($avgApproval, (int) $approvalRow['ups'] > 0, (int) $approvalRow['downs'] > 0),
            // All-time idea count (any status) — backs the owner-facing board
            // milestone celebrations (social-features issue, board momentum),
            // not just the "this week" figures above.
            'total_ideas'      => $this->countByBoard($boardId),
        ];
    }

    /**
     * Ideas on one board that wait for an answer (weekly owner digest's next
     * step and the operator worklist, P-06): see WAITING_PREDICATE. Counts
     * ideas created on or before now minus WAITING_AFTER_DAYS.
     *
     * @throws DbalException
     */
    public function countAwaitingAnswer(int $boardId, \DateTimeImmutable $now): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ideas INNER JOIN boards b ON b.id = ideas.board_id' . self::STATUS_JOIN . '
             WHERE ideas.board_id = :board_id AND ' . self::WAITING_PREDICATE . ' AND ideas.created_at <= :waiting_cutoff',
            ['board_id' => $boardId, 'waiting_cutoff' => self::waitingCutoff($now)],
        );
    }

    /**
     * Header counts of the operator worklist (P-06 slice 01) for one account,
     * optionally narrowed to one board. One aggregate statement, so the
     * numbers never disagree with each other. `unanswered` is the digest
     * number (same predicate); `unanswered_over_7_days` is the age tier of
     * the worklist filter.
     *
     * @return array{unanswered: int, unanswered_over_7_days: int, pending_moderation: int, decision_due: int, total: int}
     * @throws DbalException
     */
    public function worklistCounts(int $accountId, ?int $boardId, \DateTimeImmutable $now): array
    {
        $boardClause = $boardId === null ? '' : ' AND ideas.board_id = :board_id';
        $params      = [
            'account_id'     => $accountId,
            'waiting_cutoff' => self::waitingCutoff($now),
            'long_cutoff'    => $now->modify('-' . self::LONG_WAIT_AFTER_DAYS . ' days')->format('Y-m-d H:i:s'),
            // Named parameters must not repeat (native MySQL prepares reject that).
            'total_waiting_cutoff' => self::waitingCutoff($now),
        ];
        if ($boardId !== null) {
            $params['board_id'] = $boardId;
        }

        $row = $this->conn->fetchAssociative(
            'SELECT
                COALESCE(SUM(CASE WHEN ' . self::WAITING_PREDICATE . ' AND ideas.created_at <= :waiting_cutoff THEN 1 ELSE 0 END), 0) AS unanswered,
                COALESCE(SUM(CASE WHEN ' . self::WAITING_PREDICATE . ' AND ideas.created_at <= :long_cutoff THEN 1 ELSE 0 END), 0) AS unanswered_over_7_days,
                COALESCE(SUM(CASE WHEN ' . self::PENDING_PREDICATE . ' THEN 1 ELSE 0 END), 0) AS pending_moderation,
                COALESCE(SUM(CASE WHEN ' . self::DECISION_DUE_PREDICATE . ' THEN 1 ELSE 0 END), 0) AS decision_due,
                COALESCE(SUM(CASE WHEN (' . self::WAITING_PREDICATE . ' AND ideas.created_at <= :total_waiting_cutoff)
                    OR (' . self::PENDING_PREDICATE . ') OR (' . self::DECISION_DUE_PREDICATE . ') THEN 1 ELSE 0 END), 0) AS total
             FROM ideas INNER JOIN boards b ON b.id = ideas.board_id' . self::STATUS_JOIN . '
             WHERE b.account_id = :account_id' . $boardClause,
            $params,
        );

        $row = is_array($row) ? $row : [];

        return [
            'unanswered'            => is_numeric($row['unanswered'] ?? null) ? (int) $row['unanswered'] : 0,
            'unanswered_over_7_days' => is_numeric($row['unanswered_over_7_days'] ?? null) ? (int) $row['unanswered_over_7_days'] : 0,
            'pending_moderation'    => is_numeric($row['pending_moderation'] ?? null) ? (int) $row['pending_moderation'] : 0,
            'decision_due'          => is_numeric($row['decision_due'] ?? null) ? (int) $row['decision_due'] : 0,
            // Distinct ideas in any category (an idea can be waiting and due at once).
            'total'                 => is_numeric($row['total'] ?? null) ? (int) $row['total'] : 0,
        ];
    }

    /** Rows per worklist page (P-06: 50 rows, load more). */
    public const WORKLIST_PAGE_SIZE = 50;

    /**
     * One page of worklist rows (P-06 slice 02), account-scoped through the
     * boards join. Keyset pagination on the sort key plus id, so a page
     * never shifts when ideas are inserted before the cursor. Returns up to
     * $fetch rows; the caller trims one extra row to learn whether a next
     * page exists. Category flags are computed in SQL (one statement).
     *
     * @param string                                  $category    all|unanswered|pending_moderation|decision_due (validated by the caller)
     * @param array{created_at: string, id: int, votes: int|null}|null $after cursor position of the last row of the previous page
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function worklistRows(int $accountId, ?int $boardId, string $category, bool $longWaitOnly, string $sort, ?array $after, \DateTimeImmutable $now, int $fetch): array
    {
        $categoryPredicate = match ($category) {
            'unanswered'         => self::UNANSWERED_PREDICATE,
            'pending_moderation' => self::PENDING_PREDICATE,
            'decision_due'       => self::DECISION_DUE_PREDICATE,
            'all'                => '((' . self::UNANSWERED_PREDICATE . ') OR (' . self::PENDING_PREDICATE . ') OR (' . self::DECISION_DUE_PREDICATE . '))',
            default              => throw new \InvalidArgumentException('Unknown worklist category.'),
        };

        $where  = ['b.account_id = :account_id', $categoryPredicate];
        $params = [
            'account_id'          => $accountId,
            'flag_waiting_cutoff' => self::waitingCutoff($now),
        ];
        // Each named parameter occurs once per statement and only when the SQL uses it.
        if (in_array($category, ['all', 'unanswered'], true)) {
            $params['waiting_cutoff'] = self::waitingCutoff($now);
        }
        if ($longWaitOnly) {
            $where[]                = 'ideas.created_at <= :long_cutoff';
            $params['long_cutoff']  = $now->modify('-' . self::LONG_WAIT_AFTER_DAYS . ' days')->format('Y-m-d H:i:s');
        }
        if ($boardId !== null) {
            $where[]             = 'ideas.board_id = :board_id';
            $params['board_id']  = $boardId;
        }

        $votesExpr = '(ideas.up_count + ideas.down_count)';
        if ($after !== null) {
            $afterTail = '(ideas.created_at > :after_created OR (ideas.created_at = :after_created_eq AND ideas.id > :after_id))';
            $params['after_created']    = $after['created_at'];
            $params['after_created_eq'] = $after['created_at'];
            $params['after_id']         = $after['id'];
            if ($sort === 'votes') {
                $params['after_votes']    = (int) $after['votes'];
                $params['after_votes_eq'] = (int) $after['votes'];
                $where[] = "({$votesExpr} < :after_votes OR ({$votesExpr} = :after_votes_eq AND {$afterTail}))";
            } else {
                $where[] = $afterTail;
            }
        }

        $order = $sort === 'votes'
            ? "{$votesExpr} DESC, ideas.created_at ASC, ideas.id ASC"
            : 'ideas.created_at ASC, ideas.id ASC';

        return $this->conn->fetchAllAssociative(
            'SELECT ideas.id, ideas.title, ideas.status AS status_key, ideas.moderation_state, ideas.up_count, ideas.down_count,
                    ideas.created_at, ideas.author_id, b.slug AS board_slug, b.name AS board_name,
                    b.frozen_at, b.compliance_frozen_at,
                    COALESCE(bs.category, ideas.status) AS status_category, COALESCE(bs.is_system, 1) AS status_is_system,
                    bs.label_de AS status_label_de, bs.label_en AS status_label_en,
                    CASE WHEN ' . self::WAITING_PREDICATE . ' AND ideas.created_at <= :flag_waiting_cutoff THEN 1 ELSE 0 END AS flag_unanswered,
                    CASE WHEN ' . self::PENDING_PREDICATE . ' THEN 1 ELSE 0 END AS flag_pending_moderation,
                    CASE WHEN ' . self::DECISION_DUE_PREDICATE . ' THEN 1 ELSE 0 END AS flag_decision_due
             FROM ideas INNER JOIN boards b ON b.id = ideas.board_id' . self::STATUS_JOIN . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ' . $order . '
             LIMIT ' . max(1, $fetch),
            $params,
        );
    }

    /**
     * The cut-off of "waiting for an answer": an idea created on or before
     * this moment has waited WAITING_AFTER_DAYS. The digest and the worklist
     * both use it (owner decision Q-W2b, 2026-10-26).
     */
    public static function waitingCutoff(\DateTimeImmutable $now): string
    {
        return $now->modify('-' . self::WAITING_AFTER_DAYS . ' days')->format('Y-m-d H:i:s');
    }

    /**
     * The freeze columns of the board an idea belongs to, for the account's
     * worklist actions (dismiss and undo are blocked on frozen boards, see
     * FrozenBoardGuard). Account-scoped through the boards join: a foreign or
     * unknown idea is null.
     *
     * @return array{frozen_at: ?string, compliance_frozen_at: ?string}|null
     * @throws DbalException
     */
    public function worklistIdeaBoard(int $accountId, int $ideaId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT b.frozen_at, b.compliance_frozen_at FROM ideas INNER JOIN boards b ON b.id = ideas.board_id
             WHERE b.account_id = :account_id AND ideas.id = :idea_id',
            ['account_id' => $accountId, 'idea_id' => $ideaId],
        );
        if ($row === false) {
            return null;
        }

        return [
            'frozen_at'            => $row['frozen_at'] !== null ? (string) $row['frozen_at'] : null,
            'compliance_frozen_at' => $row['compliance_frozen_at'] !== null ? (string) $row['compliance_frozen_at'] : null,
        ];
    }

    /**
     * "Erledigt / ausblenden" (P-06 part B): hides one idea from the worklist,
     * its counts and the badge until it gets activity (DISMISSED_IN_FORCE).
     * Only an idea that is unanswered or decision due right now qualifies;
     * pending ideas must be approved or rejected. Account-scoped through the
     * boards join: a foreign or unknown id is 'not_found'. An earlier dismissal
     * of the same idea is replaced. Delete and insert run in one transaction.
     *
     * @return 'dismissed'|'not_found'|'not_dismissable'
     * @throws DbalException
     */
    public function dismissWorklistIdea(int $accountId, int $ideaId, \DateTimeImmutable $now): string
    {
        $found = $this->conn->fetchOne(
            'SELECT ideas.id FROM ideas INNER JOIN boards b ON b.id = ideas.board_id
             WHERE b.account_id = :account_id AND ideas.id = :idea_id AND ideas.deleted_at IS NULL',
            ['account_id' => $accountId, 'idea_id' => $ideaId],
        );
        if ($found === false) {
            return 'not_found';
        }

        return $this->conn->transactional(function () use ($accountId, $ideaId, $now): string {
            $eligible = $this->conn->fetchOne(
                'SELECT ideas.id FROM ideas INNER JOIN boards b ON b.id = ideas.board_id' . self::STATUS_JOIN . '
                 WHERE b.account_id = :account_id AND ideas.id = :idea_id
                   AND ((' . self::UNANSWERED_BASE . ') OR (' . self::DECISION_DUE_BASE . '))',
                ['account_id' => $accountId, 'idea_id' => $ideaId, 'waiting_cutoff' => self::waitingCutoff($now)],
            );
            if ($eligible === false) {
                return 'not_dismissable';
            }

            $this->conn->executeStatement(
                'DELETE FROM worklist_dismissals WHERE account_id = :account_id AND idea_id = :idea_id',
                ['account_id' => $accountId, 'idea_id' => $ideaId],
            );
            $this->conn->executeStatement(
                'INSERT INTO worklist_dismissals (account_id, idea_id, dismissed_at, status_at_dismissal)
                 SELECT :account_id, ideas.id, :dismissed_at, ideas.status FROM ideas WHERE ideas.id = :idea_id',
                ['account_id' => $accountId, 'idea_id' => $ideaId, 'dismissed_at' => $now->format('Y-m-d H:i:s')],
            );

            return 'dismissed';
        });
    }

    /**
     * Undo of dismissWorklistIdea(): removes the dismissal row, so the idea is
     * listed again (if it still waits). Account-scoped; a foreign or unknown
     * idea is 'not_found', a missing dismissal is a no-op 'restored'.
     *
     * @return 'restored'|'not_found'
     * @throws DbalException
     */
    public function restoreWorklistIdea(int $accountId, int $ideaId): string
    {
        $found = $this->conn->fetchOne(
            'SELECT ideas.id FROM ideas INNER JOIN boards b ON b.id = ideas.board_id
             WHERE b.account_id = :account_id AND ideas.id = :idea_id',
            ['account_id' => $accountId, 'idea_id' => $ideaId],
        );
        if ($found === false) {
            return 'not_found';
        }

        $this->conn->executeStatement(
            'DELETE FROM worklist_dismissals WHERE account_id = :account_id AND idea_id = :idea_id',
            ['account_id' => $accountId, 'idea_id' => $ideaId],
        );

        return 'restored';
    }

    /**
     * Account data export (AccountExportService): the dismissal rows of one
     * account. Keys and timestamps only; no user id is stored.
     *
     * @return list<array{account_id: int|string, idea_id: int|string, dismissed_at: string, status_at_dismissal: string}>
     * @throws DbalException
     */
    public function listWorklistDismissalsForAccount(int $accountId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT account_id, idea_id, dismissed_at, status_at_dismissal FROM worklist_dismissals
             WHERE account_id = :account_id ORDER BY idea_id ASC',
            ['account_id' => $accountId],
        );

        return array_map(
            static fn (array $row): array => [
                'account_id'          => (int) $row['account_id'],
                'idea_id'             => (int) $row['idea_id'],
                'dismissed_at'        => (string) $row['dismissed_at'],
                'status_at_dismissal' => (string) $row['status_at_dismissal'],
            ],
            $rows,
        );
    }

    /** Upper bound of vote-active ideas digestHighlights() ranks per board (keeps a huge board's run cheap). */
    private const DIGEST_CANDIDATE_POOL = 200;

    /**
     * Per-board highlights for the weekly owner digest (competitor-parity
     * issue 14), board-scoped like every method here. Moderated
     * (soft-deleted), pending (pre-moderation, PUBLISHED_ONLY), merged
     * (issue 10) and archived
     * ideas never appear — "archived" is the CATEGORY (STATUS_JOIN), so a
     * custom status of the archived category is excluded too.
     *
     * - new_ideas:       ideas created since $since, newest first.
     * - top:             ideas that received a vote since $since, ranked by
     *                    net votes (up − down), only those with net > 0.
     * - most_rejected:   same pool, ranked by downvotes (down_count > 0).
     * - most_polarising: same pool, ideas with BOTH up- and downvotes,
     *                    ranked by the smaller side (many up AND many down
     *                    beats lopsided), then by total votes.
     *
     * Counts are the denormalized all-time counters (migration 0072); the
     * window only decides which ideas were active this week. One query for
     * new ideas + one for the vote-active pool, ranking happens in PHP — no
     * per-idea queries.
     *
     * @return array{
     *     new_ideas: list<array{id: int, title: string}>,
     *     top: list<array{id: int, title: string, up_count: int, down_count: int}>,
     *     most_rejected: list<array{id: int, title: string, up_count: int, down_count: int}>,
     *     most_polarising: list<array{id: int, title: string, up_count: int, down_count: int}>
     * }
     * @throws DbalException
     */
    public function digestHighlights(int $boardId, \DateTimeImmutable $since, int $limit = 3): array
    {
        $sinceSql = $since->format('Y-m-d H:i:s');

        $newRows = $this->conn->fetchAllAssociative(
            'SELECT ideas.id, ideas.title FROM ideas' . self::STATUS_JOIN . '
             WHERE ideas.board_id = :board_id AND ideas.deleted_at IS NULL AND ' . self::PUBLISHED_ONLY . ' AND ideas.merged_into_id IS NULL
               AND ' . self::CATEGORY_EXPR . ' != \'archived\' AND ideas.created_at >= :since
             ORDER BY ideas.created_at DESC, ideas.id DESC
             LIMIT :limit',
            ['board_id' => $boardId, 'since' => $sinceSql, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        $poolRows = $this->conn->fetchAllAssociative(
            'SELECT ideas.id, ideas.title, ideas.up_count, ideas.down_count FROM ideas' . self::STATUS_JOIN . '
             WHERE ideas.board_id = :board_id AND ideas.deleted_at IS NULL AND ' . self::PUBLISHED_ONLY . ' AND ideas.merged_into_id IS NULL
               AND ' . self::CATEGORY_EXPR . ' != \'archived\'
               AND EXISTS (SELECT 1 FROM votes v WHERE v.idea_id = ideas.id AND v.created_at >= :since)
             ORDER BY (ideas.up_count + ideas.down_count) DESC, ideas.id DESC
             LIMIT :limit',
            ['board_id' => $boardId, 'since' => $sinceSql, 'limit' => self::DIGEST_CANDIDATE_POOL],
            ['limit' => ParameterType::INTEGER],
        );

        $pool = array_map(
            static fn (array $r): array => [
                'id'         => (int) $r['id'],
                'title'      => (string) $r['title'],
                'up_count'   => (int) $r['up_count'],
                'down_count' => (int) $r['down_count'],
            ],
            $poolRows,
        );

        $rank = static function (array $ideas, callable $keep, callable $compare) use ($limit): array {
            $ideas = array_values(array_filter($ideas, $keep));
            usort($ideas, $compare);
            return array_slice($ideas, 0, $limit);
        };

        return [
            'new_ideas' => array_map(
                static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title']],
                $newRows,
            ),
            'top' => $rank(
                $pool,
                static fn (array $i): bool => $i['up_count'] - $i['down_count'] > 0,
                static fn (array $a, array $b): int => [$b['up_count'] - $b['down_count'], $b['up_count']] <=> [$a['up_count'] - $a['down_count'], $a['up_count']],
            ),
            'most_rejected' => $rank(
                $pool,
                static fn (array $i): bool => $i['down_count'] > 0,
                static fn (array $a, array $b): int => [$b['down_count'], $a['up_count']] <=> [$a['down_count'], $b['up_count']],
            ),
            'most_polarising' => $rank(
                $pool,
                static fn (array $i): bool => $i['up_count'] > 0 && $i['down_count'] > 0,
                static fn (array $a, array $b): int => [min($b['up_count'], $b['down_count']), $b['up_count'] + $b['down_count']]
                    <=> [min($a['up_count'], $a['down_count']), $a['up_count'] + $a['down_count']],
            ),
        ];
    }

    /**
     * Folds $sourceId into $targetId (merging duplicate ideas, issue 10).
     * Board-scoped on BOTH ideas; only a visible, not-yet-merged source and a
     * visible, not-merged target qualify (no chains). The domain rules the
     * caller checks first (not into itself, source without merged children)
     * are repeated here as a backstop. Votes are untouched — the caller
     * recomputes the canonical idea's aggregates in the same transaction
     * (VoteRepository::recomputeMergeGroup()).
     *
     * Returns true if the source row was merged.
     *
     * @throws DbalException
     */
    public function merge(int $boardId, int $sourceId, int $targetId, int $mergedBy): bool
    {
        if ($sourceId === $targetId
            || !is_array($this->findInBoard($boardId, $targetId))
            || $this->hasMergedChildren($boardId, $sourceId)) {
            return false;
        }

        $affected = $this->conn->executeStatement(
            'UPDATE ideas
             SET merged_into_id = :target, merged_at = CURRENT_TIMESTAMP, merged_by = :merged_by
             WHERE ideas.id = :id AND ideas.board_id = :board_id AND ideas.merged_into_id IS NULL AND ideas.deleted_at IS NULL
               AND ideas.moderation_state = \'published\'',
            ['target' => $targetId, 'merged_by' => $mergedBy, 'id' => $sourceId, 'board_id' => $boardId],
        );

        return $affected === 1;
    }

    /**
     * Undoes a merge (issue 10): clears merged_into_id/merged_at/merged_by,
     * so the idea is exactly as before (its votes never moved). Board-scoped.
     * Returns the former canonical idea's id (whose aggregates the caller
     * must recompute), or null if the idea was not merged.
     *
     * @throws DbalException
     */
    public function unmerge(int $boardId, int $ideaId): ?int
    {
        $targetId = $this->conn->fetchOne(
            'SELECT merged_into_id FROM ideas WHERE id = :id AND board_id = :board_id AND merged_into_id IS NOT NULL',
            ['id' => $ideaId, 'board_id' => $boardId],
        );
        if (!is_numeric($targetId)) {
            return null;
        }

        $this->conn->executeStatement(
            'UPDATE ideas SET merged_into_id = NULL, merged_at = NULL, merged_by = NULL
             WHERE id = :id AND board_id = :board_id',
            ['id' => $ideaId, 'board_id' => $boardId],
        );

        return (int) $targetId;
    }

    /**
     * Whether any idea of this board is merged into $ideaId (board-scoped).
     *
     * @throws DbalException
     */
    public function hasMergedChildren(int $boardId, int $ideaId): bool
    {
        return $this->conn->fetchOne(
            'SELECT 1 FROM ideas WHERE board_id = :board_id AND merged_into_id = :id LIMIT 1',
            ['board_id' => $boardId, 'id' => $ideaId],
        ) !== false;
    }

    /**
     * The visible duplicates merged into $ideaId (board-scoped), oldest merge
     * first — shown on the canonical idea so moderators can undo a merge.
     *
     * @return list<array{id: int, title: string}>
     * @throws DbalException
     */
    public function listMergedInto(int $boardId, int $ideaId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, title FROM ideas
             WHERE board_id = :board_id AND merged_into_id = :id AND deleted_at IS NULL
             ORDER BY merged_at ASC, id ASC',
            ['board_id' => $boardId, 'id' => $ideaId],
        );

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title']],
            $rows,
        );
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
     * Only ideas that can be shown are candidates: soft-deleted (moderated)
     * and merged ideas (issue 10) never surface as a suggestion.
     *
     * @return list<array<string, mixed>> Rows with id, title, title_normalized,
     *                                     status, up_count, down_count.
     * @throws DbalException
     */
    public function findDuplicateCandidates(int $boardId, string $title, int $limit = 30): array
    {
        // Denormalized counters (migration 0072) — no per-row COUNT subquery.
        $voteCountExprs = 'ideas.up_count, ideas.down_count';

        if ($this->conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $booleanQuery = $this->buildBooleanSearchQuery($title);
            if ($booleanQuery === '') {
                return [];
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $this->conn->fetchAllAssociative(
                'SELECT ideas.id, ideas.title, ideas.title_normalized, ' . self::CATEGORY_EXPR . ' AS status, ' . $voteCountExprs . ',
                        MATCH(ideas.title) AGAINST(:query IN BOOLEAN MODE) AS relevance' . self::STATUS_EXTRA_COLS . '
                 FROM ideas' . self::STATUS_JOIN . '
                 WHERE ideas.board_id = :board_id
                   AND ideas.deleted_at IS NULL
                   AND ideas.merged_into_id IS NULL
                   AND ' . self::PUBLISHED_ONLY . '
                   AND MATCH(ideas.title) AGAINST(:query IN BOOLEAN MODE)
                 ORDER BY relevance DESC
                 LIMIT :limit',
                ['board_id' => $boardId, 'query' => $booleanQuery, 'limit' => $limit],
            );

            return array_map(self::decorateStatus(...), $rows);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT ideas.id, ideas.title, ideas.title_normalized, ' . self::CATEGORY_EXPR . ' AS status, ' . $voteCountExprs . self::STATUS_EXTRA_COLS . '
             FROM ideas' . self::STATUS_JOIN . '
             WHERE ideas.board_id = :board_id AND ' . self::PUBLISHED_ONLY . '
               AND ideas.deleted_at IS NULL
               AND ideas.merged_into_id IS NULL
             ORDER BY ideas.created_at DESC
             LIMIT :limit',
            ['board_id' => $boardId, 'limit' => $limit],
        );

        return array_map(self::decorateStatus(...), $rows);
    }

    /**
     * Turns the joined status columns of an idea row into the additive
     * contract fields: `status_key` (exact status), `status_category`
     * (inbox|planned|active|completed|declined|archived) and `status_label`
     * (English chain unless $locale says otherwise, see
     * BoardStatuses::resolveLabel()). `status` itself is already the
     * canonical category slug.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function decorateStatus(array $row, string $locale = 'en'): array
    {
        $category = is_string($row['status'] ?? null) ? $row['status'] : 'open';
        $key      = is_string($row['status_key'] ?? null) ? $row['status_key'] : $category;
        $labelDe  = is_string($row['status_label_de'] ?? null) ? $row['status_label_de'] : null;
        $labelEn  = is_string($row['status_label_en'] ?? null) ? $row['status_label_en'] : null;
        unset($row['status_label_de'], $row['status_label_en']);

        $row['status_key']      = $key;
        $row['status_category'] = BoardStatuses::categoryName($category);
        $row['status_label']    = BoardStatuses::resolveLabel(
            $key,
            $category,
            StatusLabel::normalize($labelDe),
            StatusLabel::normalize($labelEn),
            $locale,
        );

        return $row;
    }

    private function validKey(?string $key): ?string
    {
        return $key !== null && preg_match(BoardStatuses::KEY_PATTERN, $key) === 1 ? $key : null;
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

    /**
     * Every idea $authorId wrote, across all accounts/boards, with the
     * account/board slugs for context — the user's own GDPR self-export
     * (UserDataExportService). User-scoped, not account-scoped: the rows are
     * the caller's personal data wherever they were written (session user id
     * only, never client input). Includes moderated (deleted_at) ideas.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listByAuthorWithBoard(int $authorId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT i.id, a.slug AS account_slug, b.slug AS board_slug, i.title, i.body, i.status,
                    i.created_at, i.updated_at, i.deleted_at
             FROM ideas i
             INNER JOIN boards b ON b.id = i.board_id
             INNER JOIN accounts a ON a.id = b.account_id
             WHERE i.author_id = :author_id
             ORDER BY i.id ASC',
            ['author_id' => $authorId],
        );

        return $rows;
    }
}
