<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Votepit\Security\PublicIdGenerator;

/**
 * User persistence (arch.md §2 — persistence layer).
 *
 * Prepared-statements-only via DBAL. Board scoping does not apply here (users
 * are board-global).
 *
 * ADR 0002: identity runs exclusively via email_hmac
 * (HMAC-SHA256 of the normalized email, see Votepit\Security\IdentityHasher).
 * This class NEVER sees a plaintext email — callers hash BEFORE the call.
 *
 * Operator panel: is_operator is DELIBERATELY set NOWHERE here —
 * unlike is_admin (promoteAdmin(), self-promotable via Config::
 * isAdminEmailHmac()), there is no promote*() counterpart for is_operator. The
 * only way to set is_operator is a direct DB UPDATE by the
 * operator (see migrations/0013_add_operator_panel.sql) — no HTTP path
 * must ever be able to reach that.
 */
final readonly class UserRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Finds a user by email HMAC (exact match on a UNIQUE index).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findByEmailHmac(string $emailHmac): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, email_hmac, is_admin, is_operator, is_support, is_blocked, totp_enabled_at, verified_at, created_at FROM users WHERE email_hmac = :email_hmac',
            ['email_hmac' => $emailHmac],
        );

        return $row === false ? null : $row;
    }

    /**
     * Finds a user by email HMAC incl. password/TOTP fields — for the
     * password login path (LoginPasswordAction). Kept separate from findByEmailHmac()
     * instead of extending its SELECT: the additional columns
     * (password_hash, totp_secret_encrypted) are sensitive and shouldn't be
     * loaded incidentally everywhere findByEmailHmac() is already
     * used.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findByEmailHmacWithCredentials(string $emailHmac): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, email_hmac, is_admin, is_operator, is_blocked, token_version, verified_at,
                    password_hash, totp_secret_encrypted, totp_enabled_at, created_at
             FROM users WHERE email_hmac = :email_hmac',
            ['email_hmac' => $emailHmac],
        );

        return $row === false ? null : $row;
    }

    /**
     * Finds a user by ID incl. password/TOTP fields — for the
     * profile/2FA management actions (POST /account/password, /account/totp/*)
     * and the second 2FA login step (Login2faAction).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findByIdWithCredentials(int $id): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, public_id, email_hmac, is_admin, is_operator, is_blocked, token_version, verified_at,
                    password_hash, totp_secret_encrypted, totp_enabled_at, avatar_filename, username, created_at
             FROM users WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /**
     * Finds a user by ID. Returns token_version (for the session payload).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findById(int $id): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, public_id, email_hmac, is_admin, is_operator, is_support, is_test_account, is_blocked, token_version, totp_enabled_at, avatar_filename, profile_visible, username, verified_at, created_at
             FROM users WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /**
     * Public-safe read of another user's profile visibility + avatar +
     * username (profile-visibility feature). Deliberately narrow: never
     * returns email_hmac or any credential field — this is the read path
     * used to render OTHER users' profiles on public surfaces (idea/comment
     * authors), so it must be safe to expose to anyone regardless of caller
     * identity. Callers still gate `username` behind `profile_visible`
     * themselves (same as avatar_filename) — this method returns both
     * unconditionally, it's the presentation layer's job to hide them.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findPublicProfileById(int $id): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, is_admin, is_operator, avatar_filename, profile_visible, username
             FROM users WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /**
     * Sets or clears the caller's own optional public display name
     * (profile-visibility feature, migration 0022). $username null clears
     * it (falls back to the generic "Voter" placeholder everywhere).
     * username_lower is written alongside for the case-insensitive
     * uniqueness index — see migration 0022's class doc for why it's
     * application-maintained rather than a DB-generated column.
     *
     * @throws UsernameTakenException if another user already has this name
     *     (case-insensitive) — the DB unique index is the actual race guard,
     *     this exception is how a duplicate-key violation surfaces to the
     *     caller.
     * @throws DbalException
     */
    public function setUsername(int $id, ?string $username): void
    {
        try {
            $this->conn->executeStatement(
                'UPDATE users SET username = :username, username_lower = :username_lower WHERE id = :id',
                [
                    'username'       => $username,
                    'username_lower' => $username !== null ? mb_strtolower($username, 'UTF-8') : null,
                    'id'             => $id,
                ],
            );
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            throw new UsernameTakenException();
        }
    }

    /**
     * Sets the caller's own privacy toggle (Privacy settings —
     * profile-visibility feature). Default is anonymous (see migration 0021);
     * this is the only write path to flip it.
     *
     * @throws DbalException
     */
    public function setProfileVisible(int $id, bool $visible): void
    {
        $this->conn->executeStatement(
            'UPDATE users SET profile_visible = :visible WHERE id = :id',
            ['visible' => $visible ? 1 : 0, 'id' => $id],
        );
    }

    /**
     * Sets the opaque avatar filename (profile-avatar-social) — a random
     * token + fixed extension, NEVER derived from user input (see
     * AvatarProcessor/AccountProfileAction). Overwrites any previous value;
     * the caller is responsible for deleting the previous file from disk
     * BEFORE calling this (see AccountProfileAction::uploadAvatar()) so no
     * orphaned file is left behind.
     *
     * @throws DbalException
     */
    public function setAvatarFilename(int $id, string $filename): void
    {
        $this->conn->executeStatement(
            'UPDATE users SET avatar_filename = :filename WHERE id = :id',
            ['filename' => $filename, 'id' => $id],
        );
    }

    /**
     * Clears the avatar filename (profile-avatar-social — user removes their
     * avatar, falls back to an initials placeholder client-side). Caller
     * deletes the underlying file from disk separately.
     *
     * @throws DbalException
     */
    public function clearAvatarFilename(int $id): void
    {
        $this->conn->executeStatement(
            'UPDATE users SET avatar_filename = NULL WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * Sets verified_at = now, ONLY if still NULL (idempotent, no overwriting).
     *
     * @throws DbalException
     */
    public function markVerified(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            'UPDATE users SET verified_at = :now WHERE id = :id AND verified_at IS NULL',
            ['now' => $now, 'id' => $id],
        );
    }

    /**
     * Sets is_admin = 1 (idempotent). The caller decides via Config::isAdminEmailHmac.
     * Removing from the allowlist does NOT revoke admin (no silent downgrade).
     *
     * @throws DbalException
     */
    public function promoteAdmin(int $id): void
    {
        $this->conn->executeStatement(
            'UPDATE users SET is_admin = 1 WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * Increments token_version by 1 (logout invalidation of all active sessions).
     *
     * @throws DbalException
     */
    public function bumpTokenVersion(int $id): void
    {
        $this->conn->executeStatement(
            'UPDATE users SET token_version = token_version + 1 WHERE id = :id',
            ['id' => $id],
        );
    }

    private const CREATE_PUBLIC_ID_MAX_ATTEMPTS = 5;

    /**
     * Creates a new user with an email HMAC (no plaintext write) and a
     * random public_id (PublicIdGenerator — see migrations/0036-0038 for
     * why the raw auto-increment id is never exposed to the client).
     *
     * Throws DbalException on an email_hmac unique violation (race
     * condition → caught by the caller, that's a real duplicate signup). A
     * public_id collision carries no such meaning — it's just bad luck at
     * 50 bits of entropy — so it's retried internally instead of bubbling
     * up as if the account already existed.
     *
     * @return array<string, mixed>
     * @throws DbalException
     */
    public function create(string $emailHmac): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        for ($attempt = 0; $attempt < self::CREATE_PUBLIC_ID_MAX_ATTEMPTS; $attempt++) {
            $publicId = PublicIdGenerator::generate();

            try {
                $this->conn->executeStatement(
                    'INSERT INTO users (public_id, email_hmac, is_admin, is_operator, is_blocked, verified_at, created_at)
                     VALUES (:public_id, :email_hmac, 0, 0, 0, NULL, :created_at)',
                    ['public_id' => $publicId, 'email_hmac' => $emailHmac, 'created_at' => $now],
                );
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), 'public_id') && $attempt < self::CREATE_PUBLIC_ID_MAX_ATTEMPTS - 1) {
                    continue;
                }

                throw $e;
            }

            $id = (int) $this->conn->lastInsertId();

            return [
                'id'          => $id,
                'public_id'   => $publicId,
                'email_hmac'  => $emailHmac,
                'is_admin'    => 0,
                'is_operator' => 0,
                'is_blocked'  => 0,
                'verified_at' => null,
                'created_at'  => $now,
            ];
        }

        throw new \RuntimeException('UserRepository::create(): could not find a free public_id.');
    }

    /**
     * Sets/changes the password hash (Argon2id, see caller SetPasswordAction).
     *
     * @throws DbalException
     */
    public function setPasswordHash(int $id, string $passwordHash): void
    {
        $this->conn->executeStatement(
            'UPDATE users SET password_hash = :hash WHERE id = :id',
            ['hash' => $passwordHash, 'id' => $id],
        );
    }

    /**
     * Persists the confirmed, encrypted TOTP secret + activation time
     * (TotpConfirmAction). The unconfirmed plaintext secret NEVER goes into the DB
     * via this path — see TotpSetupToken.
     *
     * @throws DbalException
     */
    public function enableTotp(int $id, string $encryptedSecret): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            'UPDATE users SET totp_secret_encrypted = :secret, totp_enabled_at = :now WHERE id = :id',
            ['secret' => $encryptedSecret, 'now' => $now, 'id' => $id],
        );
    }

    /**
     * Disables TOTP (TotpDisableAction) — deletes secret + activation time.
     * Associated backup codes are deleted separately by the caller via
     * TotpBackupCodeRepository::deleteAllForUser().
     *
     * @throws DbalException
     */
    public function disableTotp(int $id): void
    {
        $this->conn->executeStatement(
            'UPDATE users SET totp_secret_encrypted = NULL, totp_enabled_at = NULL WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * Reads the caller's own notification settings (notification-preferences
     * feature, migration 0029): the per-event-type boolean flags plus
     * the confirmed notification_email. `notification_email` is set ONLY via
     * the confirm-link flow (NotificationEmailVerificationRepository) — a
     * non-NULL value here always means confirmed, there is no separate
     * "pending" state on this column.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findNotificationSettings(int $id): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT notification_email, notify_idea_comment_inapp, notify_idea_comment_email,
                    notify_thread_reply_inapp, notify_thread_reply_email,
                    notify_idea_status_inapp, notify_idea_status_email,
                    notify_abuse_report_inapp, notify_abuse_report_email,
                    notify_support_ticket_inapp, notify_support_ticket_email
             FROM users WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /**
     * Sets the caller's own notification-preference flags
     * (PUT /account/notification-preferences). Deliberately does NOT
     * validate that notification_email is confirmed before allowing an
     * `*_email` flag to be set to 1 — the flag is harmless to store either
     * way, since the actual send path (comment notification fan-out) checks
     * notification_email IS NOT NULL itself before ever sending (never
     * trust the client): a flag set ahead of confirmation simply has no
     * effect yet, exactly the PRD's "no effect until confirmed" rule.
     *
     * @throws DbalException
     */
    public function setNotificationPreferences(
        int $id,
        bool $ideaCommentInApp,
        bool $ideaCommentEmail,
        bool $threadReplyInApp,
        bool $threadReplyEmail,
        bool $ideaStatusInApp,
        bool $ideaStatusEmail,
        bool $abuseReportInApp,
        bool $abuseReportEmail,
        bool $supportTicketInApp,
        bool $supportTicketEmail,
    ): void {
        $this->conn->executeStatement(
            'UPDATE users
             SET notify_idea_comment_inapp   = :ic_inapp,
                 notify_idea_comment_email   = :ic_email,
                 notify_thread_reply_inapp   = :tr_inapp,
                 notify_thread_reply_email   = :tr_email,
                 notify_idea_status_inapp    = :is_inapp,
                 notify_idea_status_email    = :is_email,
                 notify_abuse_report_inapp   = :ar_inapp,
                 notify_abuse_report_email   = :ar_email,
                 notify_support_ticket_inapp = :st_inapp,
                 notify_support_ticket_email = :st_email
             WHERE id = :id',
            [
                'ic_inapp' => $ideaCommentInApp ? 1 : 0,
                'ic_email' => $ideaCommentEmail ? 1 : 0,
                'tr_inapp' => $threadReplyInApp ? 1 : 0,
                'tr_email' => $threadReplyEmail ? 1 : 0,
                'is_inapp' => $ideaStatusInApp ? 1 : 0,
                'is_email' => $ideaStatusEmail ? 1 : 0,
                'ar_inapp' => $abuseReportInApp ? 1 : 0,
                'ar_email' => $abuseReportEmail ? 1 : 0,
                'st_inapp' => $supportTicketInApp ? 1 : 0,
                'st_email' => $supportTicketEmail ? 1 : 0,
                'id'       => $id,
            ],
        );
    }

    /**
     * Confirmed notification_email addresses of every operator/support agent
     * who opted into email for one operator-scoped notification kind
     * (migrations/0045_add_operator_notification_preferences.sql). Used at
     * write time to fan out an email per-recipient — operator scope has no
     * single owning user to gate on, unlike notifyRecipient() in
     * IdeaStatusAction/CommentCreateAction. $column is never client input —
     * validated against a closed allowlist, not interpolated from a request.
     *
     * @return list<string>
     * @throws DbalException
     */
    public function findOperatorEmailRecipients(string $column): array
    {
        $allowed = ['notify_abuse_report_email', 'notify_support_ticket_email'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException("Unknown operator notification email column: {$column}");
        }

        /** @var list<string> $emails */
        $emails = $this->conn->fetchFirstColumn(
            "SELECT notification_email FROM users
             WHERE (is_operator = 1 OR is_support = 1)
               AND {$column} = 1
               AND notification_email IS NOT NULL",
        );

        return $emails;
    }

    /**
     * Confirms a pending notification_email (GET /account/notification-email/confirm).
     * The ONLY write path that ever sets this column to a non-NULL value —
     * see class doc on findNotificationSettings().
     *
     * @throws DbalException
     */
    public function setNotificationEmail(int $id, string $email): void
    {
        $this->conn->executeStatement(
            'UPDATE users SET notification_email = :email WHERE id = :id',
            ['email' => $email, 'id' => $id],
        );
    }

    /**
     * "Remove email" (Story 6/7, DELETE /account/notification-email):
     * clears notification_email AND disables all three `*_email` flags
     * atomically in one statement — a flag can never be left pointing at a
     * NULL address. The `*_inapp` flags are untouched (removing the
     * address must not silently mute in-app notifications).
     *
     * @throws DbalException
     */
    public function clearNotificationEmail(int $id): void
    {
        $this->conn->executeStatement(
            'UPDATE users
             SET notification_email = NULL, notify_idea_comment_email = 0, notify_thread_reply_email = 0, notify_idea_status_email = 0,
                 notify_abuse_report_email = 0, notify_support_ticket_email = 0
             WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * IDs of users whose notification_email is orphaned (deep-review-2026-09
     * finding j): notification_email is global on `users`, not scoped to any
     * account, so it previously survived a user losing membership in every
     * account they belonged to — with no account left to notify them about,
     * there is no purpose for keeping the address around.
     *
     * Deliberately excludes is_operator/is_support: those rely on
     * notification_email with zero account_members by design
     * (findOperatorEmailRecipients() above) — that is a valid, actively-used
     * state, not an orphan.
     *
     * @return list<int>
     * @throws DbalException
     */
    public function findOrphanedNotificationEmailUserIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->conn->fetchFirstColumn(
            'SELECT u.id FROM users u
             WHERE u.notification_email IS NOT NULL
               AND u.is_operator = 0 AND u.is_support = 0
               AND NOT EXISTS (SELECT 1 FROM account_members m WHERE m.user_id = u.id)',
        );

        return array_map(intval(...), $ids);
    }

    /**
     * Counts newly created users since a point in time (operator
     * usage overview, "recent signups"). Deliberately WITHOUT PII: only a
     * COUNT, no rows with email_hmac etc.
     *
     * @throws DbalException
     */
    public function countCreatedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM users WHERE created_at >= :since',
            ['since' => $since->format('Y-m-d H:i:s')],
        );
    }
}
