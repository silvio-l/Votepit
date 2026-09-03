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
    public function __construct(private Connection $conn) {}

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
     * already resolved as an owner/moderator of THIS account, computed by
     * the caller via AccountMemberRepository::roleFor() — kept out of this
     * repository to avoid a repo-to-repo dependency).
     *
     * Operator panel: ALSO extends this SAME chokepoint with
     * `b.locked_at IS NULL AND a.locked_at IS NULL` — an operator's board- or
     * account-level lock (AccountRepository::lockAccount()/
     * BoardRepository::lockBoard()) makes the board structurally undiscoverable
     * here too, exactly like an unconfirmed account or a private board, instead
     * of adding a fourth parallel check. Owner/moderator admin routes stay on
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
     * Validation (slug format via SlugValidator, reserved words, collision within
     * the same account) MUST already have happened at the caller (BoardCreateAction)
     * — this is pure persistence. The UNIQUE(account_id, slug) constraint
     * (uq_boards_account_slug) still remains active as a backstop against
     * race collisions: if the INSERT therefore fails, this
     * method returns null instead of letting a 500 exception propagate —
     * the caller translates that into a 422 validation error.
     *
     * @return int|null The new board ID, or null on a slug collision.
     * @throws DbalException
     */
    public function create(int $accountId, string $slug, string $name): ?int
    {
        try {
            $this->conn->insert('boards', [
                'account_id'         => $accountId,
                'slug'               => $slug,
                'name'               => $name,
                'status'             => 'active',
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
             WHERE id = :id',
            [
                'primary'    => $primaryColor,
                'secondary'  => $secondaryColor,
                'logo'       => $logoUrl,
                'intro'      => $intro,
                'hide_badge' => $hideBadge ? 1 : 0,
                'id'         => $id,
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
    public function updateVisibility(int $id, string $visibility): void
    {
        $this->conn->executeStatement(
            'UPDATE boards SET visibility = :visibility WHERE id = :id',
            ['visibility' => $visibility, 'id' => $id],
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
     * board_blocklist, board_smtp_settings, blocked_users, api_tokens — see
     * db/schema.sql) automatically cleans up everything hanging off it.
     * Returns false if the ID did not exist.
     *
     * @throws DbalException
     */
    public function deleteBoard(int $id): bool
    {
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
