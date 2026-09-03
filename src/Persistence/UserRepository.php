<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

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
            'SELECT id, email_hmac, is_admin, is_operator, is_blocked, verified_at, created_at FROM users WHERE email_hmac = :email_hmac',
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
            'SELECT id, email_hmac, is_admin, is_operator, is_blocked, token_version, verified_at,
                    password_hash, totp_secret_encrypted, totp_enabled_at, avatar_filename, created_at
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
            'SELECT id, email_hmac, is_admin, is_operator, is_blocked, token_version, avatar_filename, profile_visible, username, verified_at, created_at
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

    /**
     * Creates a new user with an email HMAC (no plaintext write).
     * Throws DbalException on a unique violation (race condition → caught by the caller).
     *
     * @return array<string, mixed>
     * @throws DbalException
     */
    public function create(string $emailHmac): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            'INSERT INTO users (email_hmac, is_admin, is_operator, is_blocked, verified_at, created_at)
             VALUES (:email_hmac, 0, 0, 0, NULL, :created_at)',
            ['email_hmac' => $emailHmac, 'created_at' => $now],
        );

        $id = (int) $this->conn->lastInsertId();

        return [
            'id'          => $id,
            'email_hmac'  => $emailHmac,
            'is_admin'    => 0,
            'is_operator' => 0,
            'is_blocked'  => 0,
            'verified_at' => null,
            'created_at'  => $now,
        ];
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
