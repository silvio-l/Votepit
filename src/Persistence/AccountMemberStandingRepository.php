<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Votepit\Domain\Premoderation;

/**
 * account_member_standing persistence (board-operator-compliance issue 10,
 * migrations/0062_create_account_member_standing.sql) — one row per
 * (account_id, user_id), tracking repeat-offender escalation.
 *
 * upsertAutomatic() is called from CommentModerationAction/
 * IdeaModerationAction after Domain\ModerationStandingService::
 * computeNextState() has determined the new violation_count/state — it
 * always clears state_changed_by_user_id back to NULL, marking the change
 * as automatic. setManual() is the ONLY writer that sets that column,
 * from the manual-override endpoint (Http\Action\
 * AccountMemberStandingAction) — the nullable column IS the "automatic vs.
 * manual" distinction the PRD calls for, no separate boolean flag.
 */
final readonly class AccountMemberStandingRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * The member's current standing row, or null if they have never been
     * moderated in this account (i.e. implicitly 'normal', violation_count
     * 0 — callers needing a row to compute against should default to that
     * shape themselves, mirroring
     * Domain\ModerationStandingService::computeNextState()'s $currentStanding
     * = null case).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findFor(int $accountId, int $userId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT account_id, user_id, violation_count, state, state_changed_at, state_changed_by_user_id, trusted_at
             FROM account_member_standing
             WHERE account_id = :account_id AND user_id = :user_id',
            ['account_id' => $accountId, 'user_id' => $userId],
        );

        return $row === false ? null : $row;
    }

    /**
     * Every standing row for an account, keyed by user_id — used by
     * MemberAction::list() (board-operator-compliance issue 10 frontend:
     * per-member standing indicator in member management). A member with
     * no row here is implicitly 'normal', violation_count 0, same
     * convention as findFor()'s null return.
     *
     * @return array<int, array<string, mixed>>
     * @throws DbalException
     */
    public function listForAccount(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT user_id, violation_count, state, state_changed_at, state_changed_by_user_id
             FROM account_member_standing
             WHERE account_id = :account_id',
            ['account_id' => $accountId],
        );

        $byUserId = [];
        foreach ($rows as $row) {
            $byUserId[(int) $row['user_id']] = $row;
        }

        return $byUserId;
    }

    /**
     * Writes the result of the automatic escalation computation
     * (Domain\ModerationStandingService::computeNextState()) — insert if
     * this is the member's first-ever violation in this account, otherwise
     * update in place. Always sets state_changed_by_user_id = NULL (this
     * is what marks the change as automatic, see class doc).
     *
     * Returns true when the move to warned/suspended cleared a trusted-author
     * mark (issue 11) — the caller audits that as `member.trust_revoked`.
     *
     * @throws DbalException
     */
    public function upsertAutomatic(int $accountId, int $userId, int $violationCount, string $state): bool
    {
        $now     = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $current = $this->findFor($accountId, $userId);

        if ($current === null) {
            $this->conn->insert('account_member_standing', [
                'account_id'               => $accountId,
                'user_id'                  => $userId,
                'violation_count'          => $violationCount,
                'state'                    => $state,
                'state_changed_at'         => $now,
                'state_changed_by_user_id' => null,
            ]);

            return false;
        }

        $this->conn->update('account_member_standing', [
            'violation_count'          => $violationCount,
            'state'                    => $state,
            'state_changed_at'         => $now,
            'state_changed_by_user_id' => null,
        ] + $this->trustReset($state), ['account_id' => $accountId, 'user_id' => $userId]);

        return $this->clearsTrust($current, $state);
    }

    /**
     * Manual override (Http\Action\AccountMemberStandingAction) — sets
     * state directly, bypassing Domain\ModerationStandingService entirely,
     * and stamps state_changed_by_user_id with the acting admin/
     * moderator's user_id (the distinguishing marker vs. upsertAutomatic()
     * above). violation_count is left untouched — an override changes what
     * the count currently MEANS for submission access, not the historical
     * count itself. Insert-or-update, same as upsertAutomatic() (a member
     * may be manually flagged/suspended before ever having a moderation
     * action recorded against them).
     *
     * Returns true when the move to warned/suspended cleared a trusted-author
     * mark (issue 11); a move back to normal never restores it.
     *
     * @throws DbalException
     */
    public function setManual(int $accountId, int $userId, string $state, int $changedByUserId): bool
    {
        $now     = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $current = $this->findFor($accountId, $userId);

        if ($current === null) {
            $this->conn->insert('account_member_standing', [
                'account_id'               => $accountId,
                'user_id'                  => $userId,
                'violation_count'          => 0,
                'state'                    => $state,
                'state_changed_at'         => $now,
                'state_changed_by_user_id' => $changedByUserId,
            ]);

            return false;
        }

        $this->conn->update('account_member_standing', [
            'state'                    => $state,
            'state_changed_at'         => $now,
            'state_changed_by_user_id' => $changedByUserId,
        ] + $this->trustReset($state), ['account_id' => $accountId, 'user_id' => $userId]);

        return $this->clearsTrust($current, $state);
    }

    /**
     * Marks an author as trusted in this account (broaden-use-cases issue
     * 11, migration 0089): their ideas skip pre-moderation (Domain\
     * Premoderation::isTrusted()). Inserts a 'normal' row for an author
     * who has none yet. Returns false when the author was already trusted
     * (the original grant is kept). Callers check the author first (no
     * placeholder, good standing).
     *
     * @throws DbalException
     */
    public function trust(int $accountId, int $userId, int $trustedByUserId): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($this->findFor($accountId, $userId) === null) {
            $this->conn->insert('account_member_standing', [
                'account_id'               => $accountId,
                'user_id'                  => $userId,
                'violation_count'          => 0,
                'state'                    => 'normal',
                'state_changed_at'         => $now,
                'state_changed_by_user_id' => null,
                'trusted_at'               => $now,
                'trusted_by_user_id'       => $trustedByUserId,
            ]);

            return true;
        }

        return $this->conn->executeStatement(
            'UPDATE account_member_standing SET trusted_at = :now, trusted_by_user_id = :by
             WHERE account_id = :account_id AND user_id = :user_id AND trusted_at IS NULL',
            ['now' => $now, 'by' => $trustedByUserId, 'account_id' => $accountId, 'user_id' => $userId],
        ) > 0;
    }

    /**
     * Clears a trusted-author mark in this account only. Returns false when
     * the author was not trusted here (callers answer 404 — the same for an
     * unknown user and one trusted only in another account).
     *
     * @throws DbalException
     */
    public function revokeTrust(int $accountId, int $userId): bool
    {
        return $this->conn->executeStatement(
            'UPDATE account_member_standing SET trusted_at = NULL, trusted_by_user_id = NULL
             WHERE account_id = :account_id AND user_id = :user_id AND trusted_at IS NOT NULL',
            ['account_id' => $accountId, 'user_id' => $userId],
        ) > 0;
    }

    /**
     * The account's trusted authors, newest first, for the moderators'
     * list. Only pseudonymous display fields (username, public_id) — never
     * an email or its HMAC.
     *
     * @return list<array{user_id: int, username: string|null, public_id: string|null, trusted_at: string, state: string}>
     * @throws DbalException
     */
    public function listTrusted(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT s.user_id, u.username, u.public_id, s.trusted_at, s.state
             FROM account_member_standing s
             JOIN users u ON u.id = s.user_id
             WHERE s.account_id = :account_id AND s.trusted_at IS NOT NULL
             ORDER BY s.trusted_at DESC, s.user_id DESC',
            ['account_id' => $accountId],
        );

        return array_map(static fn (array $row): array => [
            'user_id'    => (int) $row['user_id'],
            'username'   => is_string($row['username'] ?? null) ? $row['username'] : null,
            'public_id'  => is_string($row['public_id'] ?? null) ? $row['public_id'] : null,
            'trusted_at' => (string) $row['trusted_at'],
            'state'      => (string) $row['state'],
        ], $rows);
    }

    /**
     * Columns to reset alongside a state change: losing good standing
     * (warned/suspended) clears trust; any other state leaves it untouched,
     * so going back to normal never restores a cleared trust.
     *
     * @return array<string, null>
     */
    private function trustReset(string $state): array
    {
        return in_array($state, Premoderation::TRUST_KEEPING_STATES, true)
            ? []
            : ['trusted_at' => null, 'trusted_by_user_id' => null];
    }

    /** @param array<string, mixed> $current */
    private function clearsTrust(array $current, string $state): bool
    {
        return ($current['trusted_at'] ?? null) !== null && $this->trustReset($state) !== [];
    }
}
