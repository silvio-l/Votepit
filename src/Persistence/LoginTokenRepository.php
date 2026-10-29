<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Login token persistence (arch.md §4 — TokenVault persistence).
 *
 * Prepared-statements-only. Stores ONLY the SHA-256 hash (never the plaintext).
 * Deletes open (unused) tokens of the same user before every new insert (no piling up).
 */
final readonly class LoginTokenRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Deletes all open (used_at IS NULL) login tokens of the user.
     * Best-effort: called before the new insert to
     * prevent tokens from piling up. An error here interrupts the flow (caller catches it).
     *
     * @throws DbalException
     */
    public function deleteOpenForUser(int $userId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM login_tokens WHERE user_id = :user_id AND used_at IS NULL',
            ['user_id' => $userId],
        );
    }

    /**
     * Finds an active (unused, not expired) login token by hash.
     * Comparison against a bound :now parameter (portable MySQL/SQLite;
     * datetime strings 'Y-m-d H:i:s' compare correctly lexicographically).
     *
     * $purpose deliberately restricts this (default 'login', the existing magic-link
     * purpose): a '2fa_pending' token (short-lived, own capability for
     * POST /login/2fa — see insertPending()) must NOT also pass as a magic link
     * and vice versa, even though both use the same table/hash form.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findActiveByHash(string $tokenHash, string $purpose = 'login'): ?array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $row = $this->conn->fetchAssociative(
            'SELECT id, user_id, token_hash, purpose, expires_at, used_at, confirms_product_news, signup_marketing_email
             FROM login_tokens
             WHERE token_hash = :token_hash AND purpose = :purpose AND used_at IS NULL AND expires_at > :now',
            ['token_hash' => $tokenHash, 'purpose' => $purpose, 'now' => $now],
        );

        return $row === false ? null : $row;
    }

    /**
     * Finds a RECENTLY consumed token by hash — compensation against
     * mail-security-gateway prescanning: a gateway that opens the magic link before
     * the real user would otherwise silently consume the token, and the real
     * click would then land on "invalid link" (a real but harmless race, not an
     * attack — the scanner sees the same response as the real user, no
     * session takeover). Within a short grace window, the same
     * token is therefore still treated as valid (LoginVerifyAction makes the
     * verify transaction idempotent anyway). Deliberately no larger
     * window than necessary — extends the effective validity by only seconds,
     * not minutes.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findRecentlyUsedByHash(string $tokenHash, int $graceSeconds, string $purpose = 'login'): ?array
    {
        $cutoff = (new \DateTimeImmutable())->modify(sprintf('-%d seconds', $graceSeconds))->format('Y-m-d H:i:s');

        $row = $this->conn->fetchAssociative(
            'SELECT id, user_id, token_hash, purpose, expires_at, used_at, confirms_product_news, signup_marketing_email
             FROM login_tokens
             WHERE token_hash = :token_hash AND purpose = :purpose AND used_at IS NOT NULL AND used_at > :cutoff',
            ['token_hash' => $tokenHash, 'purpose' => $purpose, 'cutoff' => $cutoff],
        );

        return $row === false ? null : $row;
    }

    /**
     * Marks a token as consumed (used_at = now) and clears the signup address it
     * carried: once redeemed, the address lives on the users row only. One-time-use backstop.
     *
     * @throws DbalException
     */
    public function markUsed(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            'UPDATE login_tokens SET used_at = :now, signup_marketing_email = NULL WHERE id = :id',
            ['now' => $now, 'id' => $id],
        );
    }

    /**
     * Clears the signup address of open links that expired without being redeemed
     * (data minimisation for the unverified plaintext address, migration 0112).
     *
     * @throws DbalException
     */
    public function clearExpiredSignupEmails(\DateTimeImmutable $now): void
    {
        $this->conn->executeStatement(
            'UPDATE login_tokens SET signup_marketing_email = NULL
             WHERE signup_marketing_email IS NOT NULL AND expires_at < :now',
            ['now' => $now->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Stores a new login token record (hash, purpose='login').
     * Pass ONLY the hash — never the plaintext token.
     *
     * $confirmsProductNews: the magic link was requested with the signup
     * product-news checkbox ticked — redeeming it also confirms that marketing
     * $signupMarketingEmail: the address of a signup magic link (marketing enabled).
     * It is NOT a verified fact until the link is redeemed; LoginVerifyAction copies
     * it to the users row only then (migration 0112).
     *
     * @throws DbalException
     */
    public function insert(
        int $userId,
        string $tokenHash,
        string $expiresAt,
        string $purpose = 'login',
        bool $confirmsProductNews = false,
        ?string $signupMarketingEmail = null,
    ): void {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            'INSERT INTO login_tokens (user_id, token_hash, purpose, expires_at, used_at, created_at, confirms_product_news, signup_marketing_email)
             VALUES (:user_id, :token_hash, :purpose, :expires_at, NULL, :created_at, :confirms_product_news, :signup_marketing_email)',
            [
                'user_id'               => $userId,
                'token_hash'            => $tokenHash,
                'purpose'               => $purpose,
                'expires_at'            => $expiresAt,
                'created_at'            => $now,
                'confirms_product_news' => $confirmsProductNews ? 1 : 0,
                'signup_marketing_email' => $signupMarketingEmail,
            ],
        );
    }

    /**
     * Stores a "pending 2FA" token (purpose='2fa_pending', short TTL —
     * see callers LoginVerifyAction/LoginPasswordAction, ~5 minutes each).
     * Reuses the same table instead of a dedicated one — exactly the same
     * hash+expiry+single-use shape is already needed.
     *
     * @throws DbalException
     */
    public function insertPending(int $userId, string $tokenHash, string $expiresAt): void
    {
        $this->insert($userId, $tokenHash, $expiresAt, '2fa_pending');
    }
}
