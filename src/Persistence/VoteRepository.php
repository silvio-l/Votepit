<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Vote persistence (arch.md §2 — persistence layer; ADR-3 amendment).
 *
 * The single DB seam for voting. Prepared-statements-only via DBAL, no
 * query-string concatenation. Board-scoped: `score_cache` maintenance carries board_id
 * as a parameter (defense in depth against cross-board leak; the action additionally
 * guarantees board membership via IdeaRepository::findInBoard).
 *
 * ADR-3 amendment: `ideas.score_cache` is maintained APP-side within the SAME
 * transaction as the `votes` mutation — no longer via a DB trigger.
 * This makes the load-bearing invariant `score_cache == SUM(votes.value)` verifiable
 * at the portable SQLite test seam. On an error, the votes mutation
 * AND the score delta roll back together (fail-secure).
 */
final readonly class VoteRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Idempotent vote core in ONE transaction. $value MUST already be
     * validated as {−1,+1} (VoteAction). Covers all three cases:
     *   - no vote yet             → INSERT (value)         + score_cache += value
     *   - other vote exists       → UPDATE to value         + score_cache += (value − old)
     *   - same vote exists        → DELETE (retraction)      + score_cache −= value
     *
     * Exactly one or no row exists per (idea, user) afterward — never two
     * (service logic + DB UNIQUE as a backstop).
     *
     * up_count/down_count (denormalized, migration 0072) are maintained and
     * read within the same transaction (no re-query outside) — for the JSON
     * path, without a second DB query in the action.
     *
     * $mergeAnonymousTokenHash (double-vote-prevention follow-up, ADR 0004):
     * when given, any EXISTING anonymous vote row on this idea under that
     * token hash is folded away (deleted, with its own score delta applied)
     * inside the SAME transaction, before the user_id cast below runs —
     * closes the "vote anonymously, later log in on the same browser and
     * vote again" double-count (the reverse of the already-covered "vote
     * while logged in, log out, vote again anonymously" case). The fresh
     * $value the caller just cast takes precedence; the old anonymous
     * value is discarded, never merged/summed.
     *
     * @return array{my_vote: 'up'|'down'|'none', score: int, up_count: int, down_count: int} Resulting state.
     * @throws DbalException
     */
    public function cast(int $boardId, int $ideaId, int $userId, int $value, ?string $mergeAnonymousTokenHash = null): array
    {
        return $this->castInternal($boardId, $ideaId, 'user_id', $userId, $value, null, $mergeAnonymousTokenHash);
    }

    /**
     * Anonymous counterpart of cast() (ADR 0004) — identical idempotent
     * cast/toggle/retract semantics, keyed by voter_token_hash instead of
     * user_id. $voterTokenHash MUST already be the HMAC
     * (AnonymousVoterHasher::hashToken()), never the raw cookie token.
     *
     * $voterEmailEnc is written on every call that provides one (the
     * optional, purely-for-notifications email, already ENCRYPTED by the
     * caller via EncryptionService context 'anon_vote_email' — see
     * VoteAction::voteAnonymous() and migrations/
     * 0069_replace_voter_email_hash_with_encryption.sql); a null value
     * leaves any previously stored ciphertext on this row untouched only on
     * UPDATE/no-op paths — on a fresh INSERT a null argument simply stores
     * NULL (no email given).
     *
     * @return array{my_vote: 'up'|'down'|'none', score: int, up_count: int, down_count: int}
     * @throws DbalException
     */
    public function castAnonymous(int $boardId, int $ideaId, string $voterTokenHash, int $value, ?string $voterEmailEnc = null): array
    {
        return $this->castInternal($boardId, $ideaId, 'voter_token_hash', $voterTokenHash, $value, $voterEmailEnc);
    }

    /**
     * Idempotent SET (not toggle) counterpart of castAnonymous() for the
     * Agent API's per-token service identity (competitor-parity issue 15):
     * $value ∈ {−1, 0, +1} is the desired end state — +1/−1 insert or
     * change the vote, 0 removes it, and repeating the current state is a
     * no-op (no write, no score delta). Same transaction/score_cache/merge-
     * group handling as cast(). $voterTokenHash MUST already be an HMAC.
     *
     * @return array{my_vote: 'up'|'down'|'none', score: int, up_count: int, down_count: int}
     * @throws DbalException
     */
    public function setAnonymous(int $boardId, int $ideaId, string $voterTokenHash, int $value): array
    {
        return $this->castInternal($boardId, $ideaId, 'voter_token_hash', $voterTokenHash, $value, toggle: false);
    }

    /**
     * Shared idempotent vote core in ONE transaction, parametrized over the
     * identity column ('user_id' xor 'voter_token_hash' — see migrations/
     * 0068_add_anonymous_voting.sql's CHECK constraint). $value MUST already
     * be validated as {−1,+1} (VoteAction). Covers all three cases:
     *   - no vote yet             → INSERT (value)         + score_cache += value
     *   - other vote exists       → UPDATE to value         + score_cache += (value − old)
     *   - same vote exists        → DELETE (retraction)      + score_cache −= value
     *
     * Exactly one or no row exists per (idea, identity) afterward — never two
     * (service logic + DB UNIQUE as a backstop).
     *
     * up_count/down_count (denormalized, migration 0072) are maintained and
     * read within the same transaction (no re-query outside) — for the JSON
     * path, without a second DB query in the action.
     *
     * $identityColumn is a fixed internal literal (never request input) — safe
     * to interpolate into the SQL string.
     *
     * @return array{my_vote: 'up'|'down'|'none', score: int, up_count: int, down_count: int}
     * @throws DbalException
     */
    private function castInternal(int $boardId, int $ideaId, string $identityColumn, int|string $identityValue, int $value, ?string $voterEmailEnc = null, ?string $mergeAnonymousTokenHash = null, bool $toggle = true): array
    {
        /** @var array{my_vote: 'up'|'down'|'none', score: int, up_count: int, down_count: int} $result */
        $result = $this->conn->transactional(
            function (Connection $conn) use ($boardId, $ideaId, $identityColumn, $identityValue, $value, $voterEmailEnc, $mergeAnonymousTokenHash, $toggle): array {
                if ($mergeAnonymousTokenHash !== null) {
                    $staleAnonValue = $conn->fetchOne(
                        'SELECT value FROM votes WHERE idea_id = :idea AND voter_token_hash = :hash',
                        ['idea' => $ideaId, 'hash' => $mergeAnonymousTokenHash],
                    );
                    if ($staleAnonValue !== false) {
                        $conn->executeStatement(
                            'DELETE FROM votes WHERE idea_id = :idea AND voter_token_hash = :hash',
                            ['idea' => $ideaId, 'hash' => $mergeAnonymousTokenHash],
                        );
                        $this->applyDelta($conn, $boardId, $ideaId, (int) $staleAnonValue, 0);
                    }
                }

                // The voter's current vote plus whether this idea is the
                // canonical idea of a merge group (issue 10) — one statement,
                // so the common (unmerged) path costs no extra query.
                $state = $conn->fetchAssociative(
                    "SELECT (SELECT value FROM votes WHERE idea_id = :idea AND {$identityColumn} = :identity) AS existing_value,
                            CASE WHEN EXISTS (SELECT 1 FROM ideas WHERE merged_into_id = :idea AND board_id = :board) THEN 1 ELSE 0 END AS has_merged",
                    ['idea' => $ideaId, 'identity' => $identityValue, 'board' => $boardId],
                );
                $existing  = is_array($state) && is_numeric($state['existing_value'] ?? null) ? (int) $state['existing_value'] : false;
                $hasMerged = is_array($state) && (int) ($state['has_merged'] ?? 0) === 1;

                if (!$toggle && ($existing === false ? 0 : $existing) === $value) {
                    // SET mode (setAnonymous()): already in the desired state.
                    $oldValue = $value;
                    $newValue = $value;
                } elseif (!$toggle && $value === 0) {
                    $conn->executeStatement(
                        "DELETE FROM votes WHERE idea_id = :idea AND {$identityColumn} = :identity",
                        ['idea' => $ideaId, 'identity' => $identityValue],
                    );
                    $oldValue = (int) $existing;
                    $newValue = 0;
                } elseif ($existing === false) {
                    $conn->executeStatement(
                        "INSERT INTO votes (idea_id, {$identityColumn}, value, voter_email_enc, created_at)
                         VALUES (:idea, :identity, :value, :email_enc, CURRENT_TIMESTAMP)",
                        ['idea' => $ideaId, 'identity' => $identityValue, 'value' => $value, 'email_enc' => $voterEmailEnc],
                    );
                    $oldValue = 0;
                    $newValue = $value;
                } elseif ($existing === $value) {
                    $conn->executeStatement(
                        "DELETE FROM votes WHERE idea_id = :idea AND {$identityColumn} = :identity",
                        ['idea' => $ideaId, 'identity' => $identityValue],
                    );
                    $oldValue = $value;
                    $newValue = 0;
                } else {
                    $conn->executeStatement(
                        "UPDATE votes SET value = :value WHERE idea_id = :idea AND {$identityColumn} = :identity",
                        ['value' => $value, 'idea' => $ideaId, 'identity' => $identityValue],
                    );
                    $oldValue = $existing;
                    $newValue = $value;
                }

                // P-03: a down-vote reason belongs to a DOWN vote only. A switch
                // from against to for (or any other change away from −1) drops
                // the reason in the same transaction. A withdrawal deletes the
                // vote row, and the reason goes with it (ON DELETE CASCADE). Only
                // this one transition pays an extra statement.
                if ($oldValue === -1 && $newValue !== -1) {
                    $conn->executeStatement(
                        "DELETE FROM vote_reasons WHERE vote_id IN (SELECT id FROM votes WHERE idea_id = :idea AND {$identityColumn} = :identity)",
                        ['idea' => $ideaId, 'identity' => $identityValue],
                    );
                }

                // $oldValue === $newValue only for a SET-mode no-op: nothing to recompute.
                if ($oldValue !== $newValue && $hasMerged) {
                    // A merge group's aggregates are not a plain delta: the
                    // voter's vote on a duplicate may now be shadowed (or
                    // revealed again) by this canonical vote.
                    $this->recomputeMergeGroup($boardId, $ideaId);
                } elseif ($oldValue !== $newValue) {
                    $this->applyDelta($conn, $boardId, $ideaId, $oldValue, $newValue);
                }

                // score + up/down counters within the same transaction — no re-query outside.
                $row = $conn->fetchAssociative(
                    'SELECT score_cache, up_count, down_count FROM ideas WHERE id = :idea AND board_id = :board',
                    ['idea' => $ideaId, 'board' => $boardId],
                );
                $row = $row === false ? [] : $row;

                return [
                    'my_vote'    => $newValue > 0 ? 'up' : ($newValue < 0 ? 'down' : 'none'),
                    'score'      => is_numeric($row['score_cache'] ?? null) ? (int) $row['score_cache'] : 0,
                    'up_count'   => is_numeric($row['up_count'] ?? null) ? (int) $row['up_count'] : 0,
                    'down_count' => is_numeric($row['down_count'] ?? null) ? (int) $row['down_count'] : 0,
                ];
            },
        );

        return $result;
    }

    /**
     * Applies one vote row's transition ($oldValue → $newValue, each in
     * {−1, 0, +1}; 0 = no row) to the idea's cached aggregates — score_cache
     * and the denormalized up_count/down_count (migrations/
     * 0072_add_idea_counters.sql) — board-scoped, in the caller's
     * transaction. The single place these columns are written (besides the
     * merge-group recompute, recomputeMergeGroup()), so the
     * invariants score_cache == SUM(value), up_count == COUNT(value > 0),
     * down_count == COUNT(value < 0) hold for every vote path alike.
     *
     * @throws DbalException
     */
    private function applyDelta(Connection $conn, int $boardId, int $ideaId, int $oldValue, int $newValue): void
    {
        $conn->executeStatement(
            'UPDATE ideas
             SET score_cache = score_cache + :delta,
                 up_count    = up_count + :up_delta,
                 down_count  = down_count + :down_delta
             WHERE id = :idea AND board_id = :board',
            [
                'delta'      => $newValue - $oldValue,
                'up_delta'   => ($newValue > 0 ? 1 : 0) - ($oldValue > 0 ? 1 : 0),
                'down_delta' => ($newValue < 0 ? 1 : 0) - ($oldValue < 0 ? 1 : 0),
                'idea'       => $ideaId,
                'board'      => $boardId,
            ],
        );
    }

    /**
     * Recomputes the canonical idea's score_cache/up_count/down_count as the
     * aggregate over its merge group (itself + every idea merged into it,
     * issue 10), with ONE vote per voter: if a voter voted on several ideas
     * of the group, the vote on the canonical idea wins, otherwise the most
     * recent vote (highest id) on a duplicate. Votes are never moved or
     * copied, so an unmerge followed by this call restores the canonical
     * idea's own figures exactly. Without merged ideas it equals the plain
     * SUM/COUNT over its own votes.
     *
     * The score also carries the imported vote baselines of every idea in
     * the group (migration 0077: score_cache == imported_vote_count +
     * SUM(votes.value)); up/down stay real-vote counters only.
     *
     * Voter identity is user_id, or voter_token_hash for anonymous votes.
     * A self-deleted user's anonymised votes (decision 2b) carry a fresh
     * random token per row, so they cannot be matched and count once each.
     *
     * Read + write in the caller's transaction, board-scoped on every idea
     * of the group. Two statements, because MySQL cannot UPDATE ideas from a
     * subquery over ideas (error 1093).
     *
     * @throws DbalException
     */
    public function recomputeMergeGroup(int $boardId, int $canonicalId): void
    {
        $group = '(SELECT g.id FROM ideas g WHERE g.board_id = :board AND (g.id = :canonical OR g.merged_into_id = :canonical))';

        $row = $this->conn->fetchAssociative(
            'SELECT COALESCE(SUM(v.value), 0)
                        + (SELECT COALESCE(SUM(gi.imported_vote_count), 0) FROM ideas gi
                           WHERE gi.board_id = :board AND (gi.id = :canonical OR gi.merged_into_id = :canonical)) AS score,
                    COALESCE(SUM(CASE WHEN v.value > 0 THEN 1 ELSE 0 END), 0) AS up_count,
                    COALESCE(SUM(CASE WHEN v.value < 0 THEN 1 ELSE 0 END), 0) AS down_count
             FROM votes v
             WHERE v.idea_id IN ' . $group . '
               AND NOT EXISTS (
                   SELECT 1 FROM votes w
                   WHERE w.idea_id IN ' . $group . '
                     AND w.id <> v.id
                     AND ((v.user_id IS NOT NULL AND w.user_id = v.user_id)
                          OR (v.voter_token_hash IS NOT NULL AND w.voter_token_hash = v.voter_token_hash))
                     AND ((w.idea_id = :canonical AND v.idea_id <> :canonical)
                          OR (w.idea_id <> :canonical AND v.idea_id <> :canonical AND w.id > v.id))
               )',
            ['board' => $boardId, 'canonical' => $canonicalId],
        );
        $row = $row === false ? [] : $row;

        $this->conn->executeStatement(
            'UPDATE ideas SET score_cache = :score, up_count = :up, down_count = :down
             WHERE id = :canonical AND board_id = :board',
            [
                'score'     => is_numeric($row['score'] ?? null) ? (int) $row['score'] : 0,
                'up'        => is_numeric($row['up_count'] ?? null) ? (int) $row['up_count'] : 0,
                'down'      => is_numeric($row['down_count'] ?? null) ? (int) $row['down_count'] : 0,
                'canonical' => $canonicalId,
                'board'     => $boardId,
            ],
        );
    }

    /**
     * Updates the notification email on an anonymous voter's EXISTING vote
     * row (ADR 0004 UX follow-up) — lets a voter add/change/clear the
     * optional email AFTER casting, instead of it only ever being writable
     * in the same request as the vote itself. $voterEmailEnc is already
     * ENCRYPTED by the caller (EncryptionService, context 'anon_vote_email'
     * — see VoteNotifyEmailAction); null clears it.
     *
     * Deliberately an UPDATE only, never an UPSERT: an email with no vote
     * behind it would be PII collected for no purpose (the exact flaw ADR
     * 0004 already corrected once for the cast-time path) — the caller
     * surfaces "no vote yet" as 404.
     *
     * @return bool Whether a matching vote row existed (and was updated).
     * @throws DbalException
     */
    public function updateAnonymousNotifyEmail(int $ideaId, string $voterTokenHash, ?string $voterEmailEnc): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE votes SET voter_email_enc = :email_enc
             WHERE idea_id = :idea AND voter_token_hash = :hash',
            ['email_enc' => $voterEmailEnc, 'idea' => $ideaId, 'hash' => $voterTokenHash],
        );

        return $affected > 0;
    }

    /**
     * Links an anonymous-voter cookie's HMAC to the platform user_id it was
     * seen alongside on an authenticated vote (double-vote-prevention
     * follow-up, ADR 0004 — found via live testing: log in and vote, log
     * out, vote again anonymously on the same idea previously counted
     * twice). A no-op if this token is already linked (first-linked user
     * wins — never silently reassigns a cookie already linked to a
     * different account).
     *
     * @throws DbalException
     */
    public function linkVoterIdentity(string $voterTokenHash, int $userId): void
    {
        if ($this->conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->conn->executeStatement(
                'INSERT IGNORE INTO voter_identity_links (voter_token_hash, user_id, linked_at)
                 VALUES (:hash, :user, NOW())',
                ['hash' => $voterTokenHash, 'user' => $userId],
            );
        } else {
            // SQLite-compatible (tests + non-MySQL deployments).
            $this->conn->executeStatement(
                "INSERT OR IGNORE INTO voter_identity_links (voter_token_hash, user_id, linked_at)
                 VALUES (:hash, :user, datetime('now'))",
                ['hash' => $voterTokenHash, 'user' => $userId],
            );
        }
    }

    /**
     * The id of the caller's current DOWN vote on an idea, or null when there
     * is none (no vote, or an up vote). Identity is either a signed-in user id
     * or an anonymous voter token hash (ADR 0004). Used by the P-03 reason
     * endpoint so a reason can only attach to the caller's own down vote.
     *
     * @throws DbalException
     */
    public function findDownVoteId(int $ideaId, int|string $identity, bool $anonymous): ?int
    {
        $column = $anonymous ? 'voter_token_hash' : 'user_id';
        $id     = $this->conn->fetchOne(
            "SELECT id FROM votes WHERE idea_id = :idea AND {$column} = :identity AND value = -1",
            ['idea' => $ideaId, 'identity' => $identity],
        );

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * The user_id a given anonymous-voter cookie HMAC is linked to (see
     * linkVoterIdentity()), or null if this token has never voted while
     * authenticated.
     *
     * @throws DbalException
     */
    public function findLinkedUserId(string $voterTokenHash): ?int
    {
        $userId = $this->conn->fetchOne(
            'SELECT user_id FROM voter_identity_links WHERE voter_token_hash = :hash',
            ['hash' => $voterTokenHash],
        );

        return is_numeric($userId) ? (int) $userId : null;
    }

    /**
     * The user's current vote state on an idea (for detail/list display).
     *
     * @return 'up'|'down'|'none'
     * @throws DbalException
     */
    public function findUserVote(int $ideaId, int $userId): string
    {
        $value = $this->conn->fetchOne(
            'SELECT value FROM votes WHERE idea_id = :idea AND user_id = :user',
            ['idea' => $ideaId, 'user' => $userId],
        );

        if ($value === false) {
            return 'none';
        }

        return (int) $value > 0 ? 'up' : 'down';
    }

    /**
     * DISTINCT user_ids of every voter (up or down) on this idea —
     * idea-status-follow-notification: used by IdeaStatusAction to
     * fan out an 'idea_status_changed' notification to every voter when the
     * idea's status changes.
     *
     * @return list<int>
     * @throws DbalException
     */
    public function distinctVoterIds(int $ideaId): array
    {
        /** @var list<int> $ids */
        $ids = array_map(
            static fn (mixed $id): int => (int) $id,
            $this->conn->fetchFirstColumn('SELECT DISTINCT user_id FROM votes WHERE idea_id = :idea_id', ['idea_id' => $ideaId]),
        );

        return $ids;
    }

    /**
     * Encrypted (voter_email_enc) blobs of every anonymous voter on this
     * idea who left an optional notification email — ADR 0004
     * idea-status-follow-notification fan-out: used by IdeaStatusAction to
     * mail those voters about a status change too, the same way
     * distinctVoterIds() feeds registered voters. Returns raw ciphertext;
     * the caller decrypts (EncryptionService, context 'anon_vote_email') —
     * this class never sees plaintext PII.
     *
     * @return list<string>
     * @throws DbalException
     */
    public function distinctAnonymousVoterEmailsEnc(int $ideaId): array
    {
        /** @var list<string> $blobs */
        $blobs = $this->conn->fetchFirstColumn(
            'SELECT voter_email_enc FROM votes WHERE idea_id = :idea_id AND voter_email_enc IS NOT NULL',
            ['idea_id' => $ideaId],
        );

        return $blobs;
    }

    /**
     * Lists ALL votes of an account, across all boards/ideas (customer
     * self-export). Account-scoped via a double JOIN
     * (votes → ideas → boards), since votes itself carries neither board_id nor
     * account_id — a vote of a foreign account
     * structurally never shows up here.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listForAccount(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT v.id, v.idea_id, v.user_id, v.value, v.created_at
             FROM votes v
             INNER JOIN ideas i ON i.id = v.idea_id
             INNER JOIN boards b ON b.id = i.board_id
             WHERE b.account_id = :account_id
             ORDER BY v.id ASC',
            ['account_id' => $accountId],
        );

        return $rows;
    }

    /**
     * Counts votes cast by $userId within one account. Account-scoped via a
     * double JOIN (votes -> ideas -> boards), since votes itself carries
     * neither board_id nor account_id — same chokepoint reasoning as
     * listForAccount(): a vote of a foreign account structurally never
     * contributes to this count.
     *
     * Backs the public profile's "votes cast" contribution stat
     * (social-features issue 06).
     *
     * @throws DbalException
     */
    public function countByUserForAccount(int $accountId, int $userId): int
    {
        $count = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM votes v
             INNER JOIN ideas i ON i.id = v.idea_id
             INNER JOIN boards b ON b.id = i.board_id
             WHERE b.account_id = :account_id AND v.user_id = :user_id',
            ['account_id' => $accountId, 'user_id' => $userId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Every vote $userId cast while logged in, across all accounts/boards —
     * the user's own GDPR self-export (UserDataExportService).
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listByUserWithBoard(int $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT v.idea_id, a.slug AS account_slug, b.slug AS board_slug, v.value, v.created_at
             FROM votes v
             INNER JOIN ideas i ON i.id = v.idea_id
             INNER JOIN boards b ON b.id = i.board_id
             INNER JOIN accounts a ON a.id = b.account_id
             WHERE v.user_id = :user_id
             ORDER BY v.id ASC',
            ['user_id' => $userId],
        );

        return $rows;
    }

    /**
     * Anonymous votes cast from a browser whose voter cookie was later seen
     * alongside this user's login (voter_identity_links) — also personal
     * data of the user, so part of the self-export. The encrypted optional
     * email is reported only as a has-email flag.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listLinkedAnonymousForUser(int $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT v.idea_id, a.slug AS account_slug, b.slug AS board_slug, v.value, v.created_at,
                    CASE WHEN v.voter_email_enc IS NULL THEN 0 ELSE 1 END AS has_notification_email
             FROM votes v
             INNER JOIN voter_identity_links l ON l.voter_token_hash = v.voter_token_hash
             INNER JOIN ideas i ON i.id = v.idea_id
             INNER JOIN boards b ON b.id = i.board_id
             INNER JOIN accounts a ON a.id = b.account_id
             WHERE l.user_id = :user_id
             ORDER BY v.id ASC',
            ['user_id' => $userId],
        );

        return $rows;
    }
}
