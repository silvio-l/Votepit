<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Board persistence (arch.md §2 — persistence layer).
 *
 * Prepared-statements-only via DBAL. No query-string concatenation. Board-scoped:
 * every access carries `WHERE slug = :slug` or `WHERE id = :id` — no
 * cross-board leak possible.
 */
final readonly class BoardRepository
{
    /**
     * Cooldown before a tombstoned board slug becomes registrable again
     * within the same account — migrations/0030_add_slug_tombstones.sql,
     * review-2026-09-04-fixes item 3. Mirrors
     * AccountRepository::SLUG_TOMBSTONE_COOLDOWN_DAYS (kept as a separate
     * constant — the two scopes are independent and may diverge later).
     */
    private const SLUG_TOMBSTONE_COOLDOWN_DAYS = 30;

    public function __construct(private Connection $conn) {}

    /**
     * Records a tombstone for a just-deleted board slug (scoped to its
     * account — board slugs are only unique per account, see
     * uq_boards_account_slug). MUST be called BEFORE the board row is
     * actually deleted — same race-window reasoning as
     * AccountRepository::tombstoneSlug().
     *
     * @throws DbalException
     */
    private function tombstoneSlug(int $accountId, string $slug): void
    {
        $this->conn->insert('slug_tombstones', [
            'scope'      => 'board',
            'account_id' => $accountId,
            'slug'       => $slug,
            'expires_at' => (new \DateTimeImmutable('+' . self::SLUG_TOMBSTONE_COOLDOWN_DAYS . ' days'))->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @throws DbalException
     */
    private function isSlugTombstoned(int $accountId, string $slug): bool
    {
        $row = $this->conn->fetchOne(
            "SELECT 1 FROM slug_tombstones
             WHERE scope = 'board' AND account_id = :account_id AND slug = :slug AND expires_at > :now LIMIT 1",
            ['account_id' => $accountId, 'slug' => $slug, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );

        return $row !== false;
    }

    /**
     * Finds a board by its slug, scoped to an account
     * (UNIQUE(account_id, slug), see migrations/0003_seed_default_account.sql).
     * Also returns the branding columns.
     *
     * The ONLY chokepoint through which every board ID flows into the rest of
     * the stack (account scoping). A board from a foreign account
     * is structurally unfindable here → 404, no cross-tenant leak.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findBySlugForAccount(string $slug, int $accountId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, account_id, slug, name, status, visibility, locked_at, frozen_at, accent_color, primary_color, secondary_color,
                    logo_url, intro, is_default, created_at
             FROM boards WHERE slug = :slug AND account_id = :account_id',
            ['slug' => $slug, 'account_id' => $accountId],
        );

        return $row === false ? null : $row;
    }

    /**
     * Like findBySlugForAccount(), but additionally requires that the
     * OWNING account is already confirmed (accounts.confirmed_at IS NOT
     * NULL) — the confirm-before-public spam brake (ADR 0001 §2c decision 12,
     * cloud signup onboarding: "a new free board only becomes public after
     * the creator's email confirmation").
     *
     * The ONLY chokepoint for every anonymously-accessible/public board
     * read route (BoardHomeAction, BoardRoadmapAction, IdeaDetailAction,
     * IdeaNewAction) — an unconfirmed account is structurally
     * unfindable here → 404, identical to the cross-tenant leak protection of
     * findBySlugForAccount(). Admin/owner routes deliberately stay on
     * findBySlugForAccount(): the owner must keep being able to manage their
     * own board while it is still awaiting confirmation.
     *
     * Also the board-visibility chokepoint —
     * extends the SAME query instead of adding a second parallel check.
     * `public`/`unlisted` boards remain reachable via direct slug exactly as
     * before (the difference between them only matters for a future public
     * board LISTING, which does not exist yet — direct-link access is
     * identical for both). A `private` board is structurally undiscoverable
     * here unless the caller asserts $viewerIsMember (the requesting user
     * already resolved as a member — any of owner/admin/moderator — of THIS
     * account, computed by the caller via AccountMemberRepository::roleFor()
     * — kept out of this repository to avoid a repo-to-repo dependency).
     *
     * Operator panel: ALSO extends this SAME chokepoint with
     * `b.locked_at IS NULL AND a.locked_at IS NULL` — an operator's board- or
     * account-level lock (AccountRepository::lockAccount()/
     * BoardRepository::lockBoard()) makes the board structurally undiscoverable
     * here too, exactly like an unconfirmed account or a private board, instead
     * of adding a fourth parallel check. Owner/admin board-management routes stay on
     * findBySlugForAccount() (unaffected by any lock) for the same reason they
     * already bypass confirmed_at/visibility: the owner must keep seeing their
     * own board while it is locked, e.g. to read the operator's reason and
     * await an unlock.
     *
     * Downgrade/cancellation lifecycle: deliberately does NOT gain
     * a `b.frozen_at IS NULL` clause here — a downgrade-frozen board must stay
     * publicly readable (only writes are rejected, see the Http\Action
     * inline guards). `b.frozen_at` is exposed in the SELECT purely so the
     * SPA can render a read-only banner on the public page.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findPublicBySlugForAccount(string $slug, int $accountId, bool $viewerIsMember = false): ?array
    {
        // $viewerIsMember is an internal boolean (never user input), so it is safe to
        // splice the resulting clause directly rather than bind it as a query parameter.
        // Binding it as a param is deliberately avoided: SQLite's strict type-affinity
        // comparison rules mean a TEXT-bound '1' never equals the INTEGER literal 1,
        // which silently defeated this exact check (`:viewer_is_member = 1` always false).
        $privacyClause = $viewerIsMember ? '' : "AND b.visibility <> 'private'";

        $row = $this->conn->fetchAssociative(
            "SELECT b.id, b.account_id, b.slug, b.name, b.status, b.visibility, b.frozen_at, b.accent_color, b.primary_color, b.secondary_color,
                    b.logo_url, b.intro, b.is_default, b.created_at
             FROM boards b
             INNER JOIN accounts a ON a.id = b.account_id
             WHERE b.slug = :slug AND b.account_id = :account_id AND a.confirmed_at IS NOT NULL
                   AND b.locked_at IS NULL AND a.locked_at IS NULL
                   {$privacyClause}",
            ['slug' => $slug, 'account_id' => $accountId],
        );

        return $row === false ? null : $row;
    }

    /**
     * Finds an account's default board (for the SPA root route `/`, where no
     * slug is known yet) — the same visibility/confirm/lock rules as
     * findPublicBySlugForAccount(), just without a slug filter. Prefers the board
     * marked via the `is_default` flag; otherwise falls back to the oldest public board
     * (e.g. if the owner has since deleted the original default board).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findDefaultPublicForAccount(int $accountId, bool $viewerIsMember = false): ?array
    {
        // $viewerIsMember: see comment in findPublicBySlugForAccount() — deliberately
        // spliced instead of bound (no user input, SQLite type-affinity pitfall).
        $privacyClause = $viewerIsMember ? '' : "AND b.visibility <> 'private'";

        $row = $this->conn->fetchAssociative(
            "SELECT b.id, b.account_id, b.slug, b.name, b.status, b.visibility, b.frozen_at, b.accent_color, b.primary_color, b.secondary_color,
                    b.logo_url, b.intro, b.is_default, b.created_at
             FROM boards b
             INNER JOIN accounts a ON a.id = b.account_id
             WHERE b.account_id = :account_id AND a.confirmed_at IS NOT NULL
                   AND b.locked_at IS NULL AND a.locked_at IS NULL
                   {$privacyClause}
             ORDER BY b.is_default DESC, b.created_at ASC
             LIMIT 1",
            ['account_id' => $accountId],
        );

        return $row === false ? null : $row;
    }

    /**
     * Lists boards for the public, cross-tenant discovery page (GET
     * /discover, anon-reachable, no account scoping — the whole point of
     * this method). Deliberately STRICTER than the
     * findPublicBySlugForAccount() direct-link chokepoint: only
     * `visibility = 'public'` boards appear here (`unlisted` means
     * reachable by direct link but never shown in any listing — see
     * migration 0012 — so it must NOT match `<> 'private'` the way the
     * direct-link chokepoint does). Same account-trust gates as that
     * chokepoint (confirmed, not locked) plus
     * `accounts.deletion_scheduled_at IS NULL` (an account already
     * scheduled for GDPR deletion must not be advertised to new visitors).
     * `frozen_at` is NOT excluded — a downgrade-frozen board stays publicly
     * readable, same reasoning as findPublicBySlugForAccount().
     *
     * Returns only the fields the discovery UI needs — no board IDs, no
     * account internals beyond the slug already used to build the public
     * URL. `intro` is included (truncated) since it is already
     * plaintext-only/validated at write time (CLAUDE.md's shared-origin
     * invariant — no active content ever leaves a board's branding fields).
     *
     * Ranking (growth-flywheel: reward active, voter-engaged boards over
     * merely-recent ones): by vote_count (a live COUNT(*) over `votes`, not
     * the cached net ideas.score_cache — total vote volume better reflects
     * visitor activity than a signed score), then newest-first as the final
     * tiebreak. See spotlightBoards() below for the separate, randomized
     * "Spotlight" selection shown above this list.
     *
     * @return list<array{slug: string, name: string, account_slug: string, intro: string|null, idea_count: int, vote_count: int}>
     * @throws DbalException
     */
    public function listPublicDiscovery(int $limit, int $offset): array
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT b.slug, b.name, b.intro, a.slug AS account_slug,
                    (SELECT COUNT(*) FROM ideas i WHERE i.board_id = b.id) AS idea_count,
                    (SELECT COUNT(*) FROM votes v
                       INNER JOIN ideas i2 ON i2.id = v.idea_id
                      WHERE i2.board_id = b.id) AS vote_count
             FROM boards b
             INNER JOIN accounts a ON a.id = b.account_id
             WHERE b.visibility = 'public' AND b.locked_at IS NULL
                   AND a.confirmed_at IS NOT NULL AND a.locked_at IS NULL AND a.deletion_scheduled_at IS NULL
             ORDER BY vote_count DESC, b.created_at DESC
             LIMIT :limit OFFSET :offset",
            ['limit' => $limit, 'offset' => $offset],
        );

        return array_map(
            static fn (array $row): array => [
                'slug'         => (string) $row['slug'],
                'name'         => (string) $row['name'],
                'account_slug' => (string) $row['account_slug'],
                'intro'        => self::truncateIntro($row['intro']),
                'idea_count'   => (int) $row['idea_count'],
                'vote_count'   => (int) $row['vote_count'],
            ],
            $rows,
        );
    }

    /**
     * Selects `count` boards for the /discover "Spotlight" band — fully
     * automatic, no manual/editorial override (2026-09-04 product decision:
     * promotion must be algorithm-driven only). Two things at once:
     *
     *  - REWARDS good behaviour: each eligible board gets a weight of
     *    `1 + ln(1 + recent_votes)` (votes in the last `$windowDays` days,
     *    both up- and down-votes — engagement volume, not a net score,
     *    same reasoning as listPublicDiscovery()'s ranking). Log-scaled so
     *    one runaway board cannot make every other board's odds negligible.
     *  - GIVES EVERY BOARD A CHANCE: the weight floor is 1 (not 0), so a
     *    board with zero recent votes still has a real, if smaller, chance
     *    to be drawn — this is what keeps the band from calcifying into a
     *    fixed top-N. "Bad behaviour" is handled upstream: an operator-locked
     *    board (or one of a locked/unconfirmed/deletion-scheduled account) is
     *    structurally excluded from the eligible set here, same as
     *    everywhere else in /discover — there is no separate abuse check to
     *    keep in sync.
     *
     * Selection uses weighted random sampling WITHOUT replacement
     * (Efraimidis & Spirakis' A-ExpJ algorithm: draw key = u^(1/weight) for
     * a uniform u per board, take the top `count` keys) — deterministically
     * seeded by the current UTC date, so the picks stay stable for a whole
     * day (cacheable, no flicker on refresh) and rotate to a new set
     * automatically at UTC midnight, with no cron job needed. Boards with
     * zero content (idea_count = 0) are excluded — an empty board is a poor
     * spotlight, whatever its weight.
     *
     * @return list<array{slug: string, name: string, account_slug: string, intro: string|null, idea_count: int, vote_count: int}>
     * @throws DbalException
     */
    public function spotlightBoards(int $count, int $windowDays = 7, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $since = $now->modify("-{$windowDays} days")->format('Y-m-d H:i:s');

        $rows = $this->conn->fetchAllAssociative(
            "SELECT b.id, b.slug, b.name, b.intro, a.slug AS account_slug,
                    (SELECT COUNT(*) FROM ideas i WHERE i.board_id = b.id) AS idea_count,
                    (SELECT COUNT(*) FROM votes v
                       INNER JOIN ideas i2 ON i2.id = v.idea_id
                      WHERE i2.board_id = b.id) AS vote_count,
                    (SELECT COUNT(*) FROM votes v3
                       INNER JOIN ideas i3 ON i3.id = v3.idea_id
                      WHERE i3.board_id = b.id AND v3.created_at >= :since) AS recent_vote_count
             FROM boards b
             INNER JOIN accounts a ON a.id = b.account_id
             WHERE b.visibility = 'public' AND b.locked_at IS NULL
                   AND a.confirmed_at IS NOT NULL AND a.locked_at IS NULL AND a.deletion_scheduled_at IS NULL
                   AND EXISTS (SELECT 1 FROM ideas i4 WHERE i4.board_id = b.id)",
            ['since' => $since],
        );

        $seedDate = $now->format('Y-m-d');
        $keyed    = array_map(
            static function (array $row) use ($seedDate): array {
                $weight = 1.0 + log(1.0 + (int) $row['recent_vote_count']);
                $u      = self::deterministicUniform($seedDate . ':' . $row['id']);
                $row['spotlight_key'] = $u ** (1.0 / $weight);

                return $row;
            },
            $rows,
        );

        usort($keyed, static fn (array $a, array $b): int => $b['spotlight_key'] <=> $a['spotlight_key']);

        return array_map(
            static fn (array $row): array => [
                'slug'         => (string) $row['slug'],
                'name'         => (string) $row['name'],
                'account_slug' => (string) $row['account_slug'],
                'intro'        => self::truncateIntro($row['intro']),
                'idea_count'   => (int) $row['idea_count'],
                'vote_count'   => (int) $row['vote_count'],
            ],
            array_slice($keyed, 0, $count),
        );
    }

    /** Deterministic pseudo-uniform float in [0, 1) from a seed string — no shared/global PRNG state touched. */
    private static function deterministicUniform(string $seed): float
    {
        $hex = substr(hash('sha256', $seed), 0, 13);

        return hexdec($hex) / hexdec('fffffffffffff');
    }

    private static function truncateIntro(mixed $intro): ?string
    {
        if (!is_string($intro) || $intro === '') {
            return null;
        }
        if (mb_strlen($intro) <= 200) {
            return $intro;
        }

        return mb_substr($intro, 0, 199) . '…';
    }

    /**
     * Counts boards matching the same filter as listPublicDiscovery() (for
     * pagination totals) — kept as a literal duplicate of the WHERE clause
     * rather than a shared query builder, since this repository has no
     * existing query-fragment abstraction and a COUNT(*) has no need for
     * the SELECT/JOIN-to-ideas subquery.
     *
     * @throws DbalException
     */
    public function countPublicDiscovery(): int
    {
        return (int) $this->conn->fetchOne(
            "SELECT COUNT(*)
             FROM boards b
             INNER JOIN accounts a ON a.id = b.account_id
             WHERE b.visibility = 'public' AND b.locked_at IS NULL
                   AND a.confirmed_at IS NOT NULL AND a.locked_at IS NULL AND a.deletion_scheduled_at IS NULL",
        );
    }

    /**
     * Finds a board by its ID, scoped to an account. Analogous to
     * findBySlugForAccount() — used by bearer-token-authenticated
     * routes (ApiTokenAuthMiddleware supplies board_id +
     * account_id directly from the token, no slug in the path). A board from
     * a foreign account is structurally unfindable here → null.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findByIdForAccount(int $id, int $accountId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, account_id, slug, name, status, visibility, frozen_at, accent_color, primary_color, secondary_color,
                    logo_url, intro, is_default, created_at
             FROM boards WHERE id = :id AND account_id = :account_id',
            ['id' => $id, 'account_id' => $accountId],
        );

        return $row === false ? null : $row;
    }

    /**
     * Lists all boards of an account (id, slug, name) for the admin overview
     * GET /admin/boards. Account-scoped via WHERE
     * account_id — a board from a foreign account structurally never
     * shows up here (no cross-tenant leak).
     *
     * Also exposes `frozen_at` (downgrade-freeze) so the admin
     * overview can render a read-only badge on affected boards.
     *
     * `idea_count`/`vote_count` (onboarding — Setup Wizard follow-up) power
     * BoardsAdminPage's activation checklist ("first idea submitted"/"first
     * vote cast") without a second round trip. Correlated subqueries rather
     * than a JOIN + GROUP BY: an admin account only ever has a handful of
     * boards, so this stays cheap while keeping the account-scoped board row
     * shape untouched (no GROUP BY duplicate-row pitfalls to worry about).
     *
     * @return list<array{id: int, slug: string, name: string, frozen_at: string|null, idea_count: int, vote_count: int}>
     * @throws DbalException
     */
    public function listForAccount(int $accountId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT b.id, b.slug, b.name, b.frozen_at,
                    (SELECT COUNT(*) FROM ideas i WHERE i.board_id = b.id) AS idea_count,
                    (SELECT COUNT(*) FROM votes v
                       INNER JOIN ideas i2 ON i2.id = v.idea_id
                      WHERE i2.board_id = b.id) AS vote_count
             FROM boards b WHERE b.account_id = :account_id ORDER BY b.name ASC',
            ['account_id' => $accountId],
        );

        return array_map(
            static fn (array $row): array => [
                'id'         => (int) $row['id'],
                'slug'       => (string) $row['slug'],
                'name'       => (string) $row['name'],
                'frozen_at'  => $row['frozen_at'] !== null ? (string) $row['frozen_at'] : null,
                'idea_count' => (int) $row['idea_count'],
                'vote_count' => (int) $row['vote_count'],
            ],
            $rows,
        );
    }

    /**
     * Counts the boards of an account (board-count limit check).
     * Account-scoped via WHERE account_id — a board of a foreign account
     * structurally never enters here (no cross-tenant counting possible).
     *
     * @throws DbalException
     */
    public function countForAccount(int $accountId): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM boards WHERE account_id = :account_id',
            ['account_id' => $accountId],
        );
    }

    /**
     * Lists ALL board columns (incl. branding + moderation toggle) of an
     * account (customer self-export). Unlike listForAccount()
     * (lean admin overview: id/slug/name/frozen_at), this method returns
     * every column the export document needs — deliberately NOT a new
     * security chokepoint, but the same `WHERE account_id` discipline
     * as every other method of this class. Boards contain no secrets
     * (branding/intro are publicly visible anyway), so a full
     * column export is unproblematic here.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listFullForAccount(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, slug, name, status, visibility, locked_at, frozen_at, accent_color, primary_color,
                    secondary_color, logo_url, intro, hide_badge, moderation_enabled, is_default, created_at
             FROM boards WHERE account_id = :account_id ORDER BY id ASC',
            ['account_id' => $accountId],
        );

        return $rows;
    }

    /**
     * Creates a new board for $accountId (admin board
     * creation). Sets sensible defaults: status='active',
     * accent_color='#3b82f6', moderation_enabled=1, is_default=0.
     *
     * $visibility MUST already have been determined/validated by the caller
     * (BoardCreateAction — either an explicit, plan-allowed value from the
     * request, or the safest plan-allowed fallback) — this method does not
     * validate or default it, pure persistence. Always set explicitly in the
     * INSERT rather than relying on the `boards.visibility` column default,
     * so the fail-secure choice is visible at the call site.
     *
     * Validation (slug format via SlugValidator, reserved words, collision within
     * the same account) MUST already have happened at the caller (BoardCreateAction)
     * — this is pure persistence. The UNIQUE(account_id, slug) constraint
     * (uq_boards_account_slug) still remains active as a backstop against
     * race collisions: if the INSERT therefore fails, this
     * method returns null instead of letting a 500 exception propagate —
     * the caller translates that into a 422 validation error. Same null
     * return for a slug still cooling down in slug_tombstones for this
     * account (item 3) — no tombstone-existence leak to the caller.
     *
     * @return int|null The new board ID, or null on a slug collision/tombstone.
     * @throws DbalException
     * @phpstan-impure
     */
    public function create(int $accountId, string $slug, string $name, string $visibility): ?int
    {
        if ($this->isSlugTombstoned($accountId, $slug)) {
            return null;
        }

        try {
            $this->conn->insert('boards', [
                'account_id'         => $accountId,
                'slug'               => $slug,
                'name'               => $name,
                'status'             => 'active',
                'visibility'         => $visibility,
                'accent_color'       => '#3b82f6',
                'moderation_enabled' => 1,
                'is_default'         => 0,
                'created_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return null;
        }

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Renames a board's title and/or slug (account-scoped via id +
     * accountId — a foreign board is structurally unreachable here, same
     * chokepoint discipline as findBySlugForAccount()).
     *
     * Name and slug are independent: passing the board's current slug back
     * unchanged is always safe (no self-collision, no tombstone check, no
     * tombstoning of the "old" slug — nothing actually changed). Only an
     * ACTUAL slug change tombstones the old slug (link-hijack protection,
     * same reasoning as deleteBoard()) and checks the new slug against
     * slug_tombstones — identical rules to create().
     *
     * Validation (name length, slug format via SlugValidator, collision
     * within the same account) MUST already have happened at the caller
     * (BoardRenameAction) — this is pure persistence. The
     * UNIQUE(account_id, slug) constraint stays active as a race backstop:
     * if the UPDATE fails on it, this method returns false instead of
     * letting a 500 propagate (caller translates that into a 422). Note:
     * on that race backstop path the old slug has already been tombstoned
     * (see ordering below) even though the rename itself did not happen —
     * harmless, since the board still owns that slug (the UNIQUE
     * constraint on `boards` already prevents anyone else from taking it
     * regardless), it merely adds an inert extra cooldown entry.
     *
     * Ordering mirrors deleteBoard(): tombstone the old slug BEFORE
     * attempting the UPDATE, not after — avoids the race window where the
     * old slug would sit briefly un-tombstoned while already vacated.
     *
     * @return bool false on a slug collision/tombstone (or unknown board),
     *              true on success.
     * @throws DbalException
     */
    public function renameBoard(int $id, int $accountId, string $newSlug, string $newName): bool
    {
        $current = $this->conn->fetchAssociative(
            'SELECT slug FROM boards WHERE id = :id AND account_id = :account_id',
            ['id' => $id, 'account_id' => $accountId],
        );
        if ($current === false) {
            return false;
        }

        $oldSlug     = (string) $current['slug'];
        $slugChanged = $oldSlug !== $newSlug;

        if ($slugChanged) {
            if ($this->isSlugTombstoned($accountId, $newSlug)) {
                return false;
            }
            $this->tombstoneSlug($accountId, $oldSlug);
        }

        try {
            $this->conn->executeStatement(
                'UPDATE boards SET slug = :slug, name = :name WHERE id = :id AND account_id = :account_id',
                ['slug' => $newSlug, 'name' => $newName, 'id' => $id, 'account_id' => $accountId],
            );
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Sets a board's branding (board-scoped via id). Values MUST already be
     * validated/sanitized by the caller (BrandingValidator); null
     * resets the respective column → default theme.
     *
     * Branding tiers: also carries `intro` (plaintext, see
     * BrandingValidator::introText()) and `hideBadge` (Pro-only "Powered by
     * Votepit" toggle). Plan gating MUST already have been checked by the
     * caller (BoardBrandingAction, PlanPolicy::isBrandingFieldAllowed())
     * — this method does not validate/gate, pure persistence.
     *
     * @throws DbalException
     */
    public function updateBranding(
        int $id,
        int $accountId,
        ?string $primaryColor,
        ?string $secondaryColor,
        ?string $logoUrl,
        ?string $intro,
        bool $hideBadge,
    ): void {
        $this->conn->executeStatement(
            'UPDATE boards
             SET primary_color = :primary, secondary_color = :secondary, logo_url = :logo,
                 intro = :intro, hide_badge = :hide_badge
             WHERE id = :id AND account_id = :account_id',
            [
                'primary'    => $primaryColor,
                'secondary'  => $secondaryColor,
                'logo'       => $logoUrl,
                'intro'      => $intro,
                'hide_badge' => $hideBadge ? 1 : 0,
                'id'         => $id,
                'account_id' => $accountId,
            ],
        );
    }

    /**
     * Sets a board's visibility (tier enforcement).
     * $visibility MUST already have been checked by the caller against
     * PlanPolicy::isVisibilityAllowed() — this method does not validate, pure persistence.
     *
     * @throws DbalException
     */
    public function updateVisibility(int $id, int $accountId, string $visibility): void
    {
        $this->conn->executeStatement(
            'UPDATE boards SET visibility = :visibility WHERE id = :id AND account_id = :account_id',
            ['visibility' => $visibility, 'id' => $id, 'account_id' => $accountId],
        );
    }

    // -------------------------------------------------------------------------
    // Operator panel — platform-wide operator actions. Every
    // method below is board-id-scoped (never by slug+account — the operator
    // acts on a specific board regardless of which tenant owns it), meant to
    // be called ONLY from behind AuthZMiddleware::operator().
    // -------------------------------------------------------------------------

    /**
     * Sets boards.locked_at = now (reversible — see unlockBoard()). Takes
     * effect immediately on every anonymously-accessible read route via
     * findPublicBySlugForAccount() (see there).
     *
     * @throws DbalException
     */
    public function lockBoard(int $id): void
    {
        $this->conn->executeStatement(
            'UPDATE boards SET locked_at = :now WHERE id = :id',
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    /**
     * Sets boards.locked_at = NULL — lifts an operator lock again.
     *
     * @throws DbalException
     */
    public function unlockBoard(int $id): void
    {
        $this->conn->executeStatement(
            'UPDATE boards SET locked_at = NULL WHERE id = :id',
            ['id' => $id],
        );
    }

    // -------------------------------------------------------------------------
    // Board freezing (plan-limit reconciliation). Distinct from the operator
    // lock block above: frozen_at makes a board READ-ONLY (writes rejected —
    // see the Http\Action inline guards), never hidden from its own public
    // page (unlike locked_at).
    // -------------------------------------------------------------------------

    /**
     * Reconciles an account's non-frozen board count against $limit (the
     * account's current board limit, see PlanPolicy::boardLimit()) — freezes
     * the excess, deterministically newest-created-first (oldest boards are
     * assumed the most established/active and are kept unfrozen by default;
     * the owner can always override this via setActiveBoards()).
     *
     * Idempotent + self-healing: meant to be called after EVERY plan change
     * (not only on a clear "downgrade" delta) so an account can never be
     * left with more unfrozen boards than its CURRENT limit allows, no matter
     * what sequence of changes produced that state. Already-frozen boards are
     * never touched (WHERE frozen_at IS NULL) — re-running this after a
     * further reduction only freezes MORE boards, never re-stamps ones
     * already frozen.
     *
     * @return list<int> IDs of boards frozen by this call (empty if already
     *                    within the limit — the common case).
     * @throws DbalException
     */
    public function enforcePlanLimit(int $accountId, int $limit): array
    {
        /** @var list<int> $activeIds */
        $activeIds = array_map(
            static fn (mixed $id): int => (int) $id,
            $this->conn->fetchFirstColumn(
                'SELECT id FROM boards WHERE account_id = :account_id AND frozen_at IS NULL
                 ORDER BY created_at DESC, id DESC',
                ['account_id' => $accountId],
            ),
        );

        $excess = count($activeIds) - $limit;
        if ($excess <= 0) {
            return [];
        }

        // Newest-first order (see query above) → the first $excess IDs are the
        // most-recently-created boards, which is exactly what gets frozen.
        $toFreeze = array_slice($activeIds, 0, $excess);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($toFreeze as $boardId) {
            $this->conn->executeStatement(
                'UPDATE boards SET frozen_at = :now WHERE id = :id',
                ['now' => $now, 'id' => $boardId],
            );
        }

        return $toFreeze;
    }

    /**
     * Owner-facing re-choice of which boards stay active: unfreezes exactly
     * $keepBoardIds, freezes every OTHER board of the account. $keepBoardIds
     * MUST already be validated by the caller (BoardActiveSetAction) — count
     * within PlanPolicy::boardLimit()
     * and every ID account-scoped (BoardRepository::findByIdForAccount()) —
     * this method performs no validation of its own, purely persistence.
     *
     * @param list<int> $keepBoardIds
     * @throws DbalException
     */
    public function setActiveBoards(int $accountId, array $keepBoardIds): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($keepBoardIds !== []) {
            $this->conn->executeStatement(
                'UPDATE boards SET frozen_at = NULL WHERE account_id = :account_id AND id IN (:ids)',
                ['account_id' => $accountId, 'ids' => $keepBoardIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
            );

            $this->conn->executeStatement(
                'UPDATE boards SET frozen_at = :now WHERE account_id = :account_id AND frozen_at IS NULL AND id NOT IN (:ids)',
                ['now' => $now, 'account_id' => $accountId, 'ids' => $keepBoardIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
        } else {
            // Nothing kept active — freeze every currently-unfrozen board.
            $this->conn->executeStatement(
                'UPDATE boards SET frozen_at = :now WHERE account_id = :account_id AND frozen_at IS NULL',
                ['now' => $now, 'account_id' => $accountId],
            );
        }
    }

    /**
     * Hard-deletes a board. ON DELETE CASCADE (ideas → votes/comments,
     * board_blocklist, board_smtp_settings, blocked_users, api_token_boards)
     * automatically cleans up everything hanging off it. A multi-board API
     * token loses only its grant to THIS board (api_tokens itself survives
     * as long as it still grants at least one other board — see migration
     * 0044). Returns false if the ID did not exist.
     *
     * Tombstones the slug within its account (item 3) BEFORE deleting the
     * row — see tombstoneSlug() doc for why the order matters.
     *
     * @throws DbalException
     */
    public function deleteBoard(int $id): bool
    {
        $row = $this->conn->fetchAssociative('SELECT account_id, slug FROM boards WHERE id = :id', ['id' => $id]);
        if (is_array($row)) {
            $this->tombstoneSlug((int) $row['account_id'], (string) $row['slug']);
        }

        $affected = $this->conn->executeStatement('DELETE FROM boards WHERE id = :id', ['id' => $id]);

        return $affected > 0;
    }

    /**
     * Finds a board by its ID, WITHOUT account scoping (operator
     * actions). Deliberately separate from findByIdForAccount() (bearer-
     * token-scoped): an operator by definition acts across all
     * accounts, a foreign board is DELIBERATELY findable here.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findByIdAny(int $id): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, account_id, slug, name, status, visibility, locked_at, accent_color, primary_color, secondary_color,
                    logo_url, intro, is_default, created_at
             FROM boards WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /**
     * Lists ALL boards platform-wide incl. account slug (operator overview)
     * — deliberately WITHOUT account-scoping WHERE, which is the whole point
     * of this route.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listAllForOperator(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT b.id, b.account_id, a.slug AS account_slug, b.slug, b.name, b.status, b.visibility,
                    b.locked_at, b.created_at
             FROM boards b
             INNER JOIN accounts a ON a.id = b.account_id
             ORDER BY b.created_at DESC',
        );

        return $rows;
    }

    /**
     * Counts all boards platform-wide.
     *
     * @throws DbalException
     */
    public function countAll(): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards');
    }
}
