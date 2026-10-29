<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Votepit\Extension\AppExtension;
use Votepit\Extension\ExtensionLoader;

/**
 * Irreversible erasure of one user (GDPR Art. 17 self-deletion,
 * competitor-parity issue 12) — only ever called by ExpiredUserCleaner
 * after the 48h grace period, never from an HTTP action.
 *
 * Decision 2b, in ONE transaction:
 *   - authored ideas/comments stay, re-attributed to the shared "deleted
 *     user" placeholder (UserRepository::findOrCreateDeletedPlaceholder());
 *   - the user's votes stay with their value (score_cache/up_count/
 *     down_count untouched) but are unlinked: user_id → NULL plus a fresh
 *     random voter_token_hash per row, the same shape as an anonymous vote
 *     whose cookie nobody holds (satisfies 0068's identity CHECK and the
 *     UNIQUE (idea_id, voter_token_hash) key);
 *   - anonymous votes this user's browser cast (voter_identity_links) lose
 *     their optional notification email and get a fresh token too, so the
 *     old cookie can no longer be tied back to them;
 *   - rows that reference the user with ON DELETE RESTRICT (moderation
 *     log actor, invites.invited_by, blocked_users.created_by,
 *     api_tokens.created_by_user_id — the user's own MCP OAuth grants are
 *     revoked first), the pre-moderation approver
 *     (ideas.approved_by — ON DELETE SET NULL, but kept like the
 *     moderation log actor) and support replies the user wrote
 *     in other people's tickets are re-attributed to the placeholder —
 *     they are the account's records, not the user's;
 *   - registered extensions re-point their own user references
 *     (AppExtension::reassignUserReferences(), e.g. Cloud's legal
 *     acceptance/acknowledgment evidence) to the placeholder — a failing
 *     hook rolls the whole erasure back, the user stays;
 *   - finally the users row is deleted; ON DELETE CASCADE removes every
 *     remaining personal row (sessions/login tokens, OAuth identities, TOTP
 *     backup codes, memberships, standing, reactions, notifications and
 *     read/dismiss marks, own support tickets, own appeals, pending
 *     notification-email verifications, voter identity links). The users
 *     row itself carries the profile, notification email and credentials.
 *
 * Re-attribution never changes which account/board a row belongs to, so
 * account scoping is unaffected.
 */
final readonly class UserErasureRepository
{
    /** @param list<AppExtension> $extensions */
    public function __construct(
        private Connection $conn,
        private array $extensions = [],
    ) {}

    /**
     * @throws DbalException
     */
    public function erase(int $userId, int $placeholderId): void
    {
        if ($userId === $placeholderId) {
            throw new \LogicException('The deleted-user placeholder itself can never be erased.');
        }
        // The guest-author placeholder (ADR 0006 §1) stands for many
        // unrelated guests — erasing it would re-attribute their ideas.
        $isGuest = $this->conn->fetchOne('SELECT is_guest_placeholder FROM users WHERE id = :id', ['id' => $userId]);
        if ((int) $isGuest === 1) {
            throw new \LogicException('The guest-author placeholder can never be erased.');
        }

        $this->conn->transactional(function (Connection $conn) use ($userId, $placeholderId): void {
            // MCP OAuth grants (issue 21) are the erased user's personal
            // delegation, not an account record an admin created: revoke
            // them before the re-attribution below, so no client keeps
            // acting under the placeholder.
            $conn->executeStatement(
                'UPDATE api_tokens SET revoked_at = :now
                 WHERE created_by_user_id = :user AND oauth_client_id IS NOT NULL AND revoked_at IS NULL',
                ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'user' => $userId],
            );

            $reattribute = [
                'UPDATE ideas SET author_id = :placeholder WHERE author_id = :user',
                'UPDATE comments SET author_id = :placeholder WHERE author_id = :user',
                'UPDATE content_moderation_actions SET actor_user_id = :placeholder WHERE actor_user_id = :user',
                'UPDATE ideas SET approved_by = :placeholder WHERE approved_by = :user',
                'UPDATE invites SET invited_by = :placeholder WHERE invited_by = :user',
                'UPDATE blocked_users SET created_by = :placeholder WHERE created_by = :user',
                'UPDATE api_tokens SET created_by_user_id = :placeholder WHERE created_by_user_id = :user',
                'UPDATE support_messages SET author_user_id = :placeholder WHERE author_user_id = :user',
            ];
            foreach ($reattribute as $sql) {
                $conn->executeStatement($sql, ['placeholder' => $placeholderId, 'user' => $userId]);
            }

            $conn->executeStatement(
                'UPDATE account_member_standing SET state_changed_by_user_id = NULL WHERE state_changed_by_user_id = :user',
                ['user' => $userId],
            );
            // Trusted-author grants (issue 11, migration 0089) stay valid
            // after the granting moderator is erased; only the actor goes
            // (the FK is ON DELETE SET NULL too — explicit for clarity).
            // The erased user's own standing rows (incl. their trust) go
            // with the user row (ON DELETE CASCADE).
            $conn->executeStatement(
                'UPDATE account_member_standing SET trusted_by_user_id = NULL WHERE trusted_by_user_id = :user',
                ['user' => $userId],
            );

            /** @var list<int|string> $ownVoteIds */
            $ownVoteIds = $conn->fetchFirstColumn('SELECT id FROM votes WHERE user_id = :user', ['user' => $userId]);
            /** @var list<int|string> $linkedVoteIds */
            $linkedVoteIds = $conn->fetchFirstColumn(
                'SELECT v.id FROM votes v
                 INNER JOIN voter_identity_links l ON l.voter_token_hash = v.voter_token_hash
                 WHERE l.user_id = :user',
                ['user' => $userId],
            );

            foreach ([...$ownVoteIds, ...$linkedVoteIds] as $voteId) {
                $conn->executeStatement(
                    'UPDATE votes SET user_id = NULL, voter_token_hash = :token, voter_email_enc = NULL WHERE id = :id',
                    ['token' => bin2hex(random_bytes(32)), 'id' => (int) $voteId],
                );
            }
            // P-03: the reason choice stays, the free text the user may have typed goes.
            (new VoteReasonRepository($conn))->clearNotesForVotes(array_map(intval(...), [...$ownVoteIds, ...$linkedVoteIds]));

            ExtensionLoader::reassignUserReferences($this->extensions, $conn, $userId, $placeholderId);

            $conn->executeStatement('DELETE FROM users WHERE id = :user', ['user' => $userId]);
        });
    }
}
