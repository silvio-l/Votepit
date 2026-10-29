<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Votepit\Domain\MarketingConsent;

/**
 * Persistence of the proof-carrying marketing consent (migration 0112): one
 * `marketing_consents` row per user plus the two users-row facts the senders
 * need (`existing_customer_notice_at`, `marketing_email`).
 *
 * Prepared-statements-only. Stores only the SHA-256 hash of a pending profile
 * confirmation token (never the plaintext), same convention as login_tokens.
 * No IP address is ever written here (data minimisation).
 *
 * Writes that touch both tables run in one transaction. Upserts follow the
 * repo convention: UPDATE first, INSERT on miss, and a concurrent INSERT that
 * loses the race is caught and turned into the UPDATE (double submit safe).
 */
final readonly class MarketingConsentRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findForUser(int $userId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT user_id, status, source, text_version, confirm_expires_at, requested_at, confirmed_at, withdrawn_at
             FROM marketing_consents WHERE user_id = :user_id',
            ['user_id' => $userId],
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Stamps the § 7 Abs. 3 UWG notice as shown at signup (first time only) and
     * keeps the address the notice refers to. An earlier objection (withdrawn
     * consent) is never undone by a later signup request: the address then
     * stays cleared.
     *
     * @throws DbalException
     */
    public function recordExistingCustomerNotice(int $userId, string $email, \DateTimeImmutable $at): void
    {
        $this->conn->executeStatement(
            'UPDATE users
             SET existing_customer_notice_at = COALESCE(existing_customer_notice_at, :at),
                 marketing_email = CASE
                     WHEN EXISTS (SELECT 1 FROM marketing_consents WHERE user_id = :user_id AND status = :withdrawn)
                     THEN marketing_email
                     ELSE :email
                 END
             WHERE id = :user_id',
            [
                'at'        => $at->format('Y-m-d H:i:s'),
                'user_id'   => $userId,
                'withdrawn' => MarketingConsent::STATUS_WITHDRAWN,
                'email'     => $email,
            ],
        );
    }

    /**
     * Sets a consent to pending (double opt-in requested). A confirmed consent is
     * left untouched — callers check that first and do not send a new mail.
     * Writes no address: the delivery address is set only once it is proven
     * (see setMarketingEmail()).
     *
     * @throws DbalException
     */
    public function requestConfirmation(
        int $userId,
        string $source,
        ?string $confirmTokenHash,
        ?\DateTimeImmutable $confirmExpiresAt,
        \DateTimeImmutable $at,
    ): void {
        $now = $at->format('Y-m-d H:i:s');

        $this->conn->transactional(function (Connection $conn) use ($userId, $source, $confirmTokenHash, $confirmExpiresAt, $now): void {
            $params = [
                'user_id'    => $userId,
                'status'     => MarketingConsent::STATUS_PENDING,
                'source'     => $source,
                'version'    => MarketingConsent::TEXT_VERSION,
                'token'      => $confirmTokenHash,
                'expires'    => $confirmExpiresAt?->format('Y-m-d H:i:s'),
                'now'        => $now,
                'confirmed'  => MarketingConsent::STATUS_CONFIRMED,
            ];

            $updated = $conn->executeStatement(
                'UPDATE marketing_consents
                 SET status = :status, source = :source, text_version = :version,
                     confirm_token_hash = :token, confirm_expires_at = :expires,
                     requested_at = :now, confirmed_at = NULL, withdrawn_at = NULL, updated_at = :now
                 WHERE user_id = :user_id AND status <> :confirmed',
                $params,
            );

            if ($updated === 0 && $this->consentExists($conn, $userId)) {
                // Already confirmed: a new request changes nothing (callers do not send a mail either).
                return;
            }

            if ($updated === 0) {
                try {
                    $conn->executeStatement(
                        'INSERT INTO marketing_consents
                            (user_id, status, source, text_version, confirm_token_hash, confirm_expires_at,
                             requested_at, confirmed_at, withdrawn_at, created_at, updated_at)
                         VALUES
                            (:user_id, :status, :source, :version, :token, :expires,
                             :now, NULL, NULL, :now, :now)',
                        $params,
                    );
                } catch (UniqueConstraintViolationException) {
                    // A concurrent request inserted the row first; the UPDATE above already ran on a miss, so
                    // repeat it once for the row that now exists.
                    $conn->executeStatement(
                        'UPDATE marketing_consents
                         SET status = :status, source = :source, text_version = :version,
                             confirm_token_hash = :token, confirm_expires_at = :expires,
                             requested_at = :now, confirmed_at = NULL, withdrawn_at = NULL, updated_at = :now
                         WHERE user_id = :user_id AND status <> :confirmed',
                        $params,
                    );
                }
            }

        });
    }

    /**
     * Sets the delivery address once its owner has proven control of it: at the
     * redeem of the signup magic link, or at the confirmation of a profile opt-in.
     *
     * @throws DbalException
     */
    public function setMarketingEmail(int $userId, string $email): void
    {
        $this->conn->executeStatement(
            'UPDATE users SET marketing_email = :email WHERE id = :user_id',
            ['email' => $email, 'user_id' => $userId],
        );
    }

    /**
     * Confirms a pending consent (double opt-in link redeemed). Returns false when
     * nothing was pending, which makes a replayed link a harmless no-op.
     *
     * @throws DbalException
     */
    public function markConfirmed(int $userId, \DateTimeImmutable $at): bool
    {
        $now = $at->format('Y-m-d H:i:s');

        $updated = $this->conn->executeStatement(
            'UPDATE marketing_consents
             SET status = :confirmed, confirmed_at = :now, confirm_token_hash = NULL, confirm_expires_at = NULL, updated_at = :now
             WHERE user_id = :user_id AND status = :pending',
            [
                'confirmed' => MarketingConsent::STATUS_CONFIRMED,
                'now'       => $now,
                'user_id'   => $userId,
                'pending'   => MarketingConsent::STATUS_PENDING,
            ],
        );

        return $updated > 0;
    }

    /**
     * Removes pending signup consents that nothing can confirm any more: no open
     * (unused, unexpired) login link with the product-news flag exists for the user.
     * Covers expired links and links replaced by a later request, e.g. a stranger's
     * ticked signup POST for someone else's address. Profile opt-ins keep their own
     * confirm token and expiry and are not touched.
     *
     * @throws DbalException
     */
    public function deleteUnconfirmableSignupPending(\DateTimeImmutable $now): void
    {
        $this->conn->executeStatement(
            'DELETE FROM marketing_consents
             WHERE status = :pending AND source = :signup
               AND NOT EXISTS (
                   SELECT 1 FROM login_tokens lt
                   WHERE lt.user_id = marketing_consents.user_id
                     AND lt.confirms_product_news = 1 AND lt.used_at IS NULL AND lt.expires_at >= :now
               )',
            [
                'pending' => MarketingConsent::STATUS_PENDING,
                'signup'  => MarketingConsent::SOURCE_SIGNUP,
                'now'     => $now->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Pending profile opt-in whose confirm token hash matches and is not yet expired.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findPendingByConfirmTokenHash(string $tokenHash, \DateTimeImmutable $now): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT user_id FROM marketing_consents
             WHERE confirm_token_hash = :token_hash AND status = :pending AND confirm_expires_at > :now',
            [
                'token_hash' => $tokenHash,
                'pending'    => MarketingConsent::STATUS_PENDING,
                'now'        => $now->format('Y-m-d H:i:s'),
            ],
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Objection / immediate opt-out: the consent becomes withdrawn (the row stays
     * as proof), the delivery address is cleared so no further mail can go out.
     * Works for a user who never had a consent row too (the § 7 Abs. 3 objection
     * path). The first withdrawal timestamp is kept on repeated calls.
     *
     * @throws DbalException
     */
    public function withdraw(int $userId, \DateTimeImmutable $at): void
    {
        $now = $at->format('Y-m-d H:i:s');

        $this->conn->transactional(function (Connection $conn) use ($userId, $now): void {
            $updated = $conn->executeStatement(
                'UPDATE marketing_consents
                 SET status = :withdrawn, withdrawn_at = COALESCE(withdrawn_at, :now),
                     confirm_token_hash = NULL, confirm_expires_at = NULL, updated_at = :now
                 WHERE user_id = :user_id',
                ['withdrawn' => MarketingConsent::STATUS_WITHDRAWN, 'now' => $now, 'user_id' => $userId],
            );

            if ($updated === 0) {
                try {
                    $conn->executeStatement(
                        'INSERT INTO marketing_consents
                            (user_id, status, source, text_version, confirm_token_hash, confirm_expires_at,
                             requested_at, confirmed_at, withdrawn_at, created_at, updated_at)
                         VALUES
                            (:user_id, :withdrawn, :source, :version, NULL, NULL, NULL, NULL, :now, :now, :now)',
                        [
                            'user_id'   => $userId,
                            'withdrawn' => MarketingConsent::STATUS_WITHDRAWN,
                            'source'    => MarketingConsent::SOURCE_PROFILE,
                            'version'   => MarketingConsent::TEXT_VERSION,
                            'now'       => $now,
                        ],
                    );
                } catch (UniqueConstraintViolationException) {
                    $conn->executeStatement(
                        'UPDATE marketing_consents
                         SET status = :withdrawn, withdrawn_at = COALESCE(withdrawn_at, :now),
                             confirm_token_hash = NULL, confirm_expires_at = NULL, updated_at = :now
                         WHERE user_id = :user_id',
                        ['withdrawn' => MarketingConsent::STATUS_WITHDRAWN, 'now' => $now, 'user_id' => $userId],
                    );
                }
            }

            $conn->executeStatement(
                'UPDATE users SET marketing_email = NULL WHERE id = :user_id',
                ['user_id' => $userId],
            );
        });
    }

    /**
     * Everything the self-service export says about the user's marketing consent,
     * including the delivery address (the user's own plaintext data).
     *
     * @return array{status: string|null, source: string|null, text_version: string|null,
     *     requested_at: string|null, confirmed_at: string|null, withdrawn_at: string|null,
     *     existing_customer_notice_at: string|null, marketing_email: string|null}
     * @throws DbalException
     */
    public function exportForUser(int $userId): array
    {
        $user    = $this->conn->fetchAssociative(
            'SELECT existing_customer_notice_at, marketing_email FROM users WHERE id = :user_id',
            ['user_id' => $userId],
        );
        $consent = $this->findForUser($userId);

        return [
            'status'                      => $consent['status'] ?? null,
            'source'                      => $consent['source'] ?? null,
            'text_version'                => $consent['text_version'] ?? null,
            'requested_at'                => $consent['requested_at'] ?? null,
            'confirmed_at'                => $consent['confirmed_at'] ?? null,
            'withdrawn_at'                => $consent['withdrawn_at'] ?? null,
            'existing_customer_notice_at' => is_array($user) ? ($user['existing_customer_notice_at'] ?? null) : null,
            'marketing_email'             => is_array($user) ? ($user['marketing_email'] ?? null) : null,
        ];
    }

    /** @throws DbalException */
    private function consentExists(Connection $conn, int $userId): bool
    {
        return $conn->fetchOne(
            'SELECT 1 FROM marketing_consents WHERE user_id = :user_id',
            ['user_id' => $userId],
        ) !== false;
    }
}
