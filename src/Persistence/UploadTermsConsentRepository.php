<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Votepit\Security\IdentityHasher;

/**
 * upload_terms_consents (migration 0098): the account-level acceptance of the
 * upload clause for one terms version. Every query is keyed by account_id, so
 * an acceptance of account A never answers for account B.
 */
final readonly class UploadTermsConsentRepository
{
    public function __construct(private Connection $conn) {}

    /** Accepted-at (DB format) of this account's consent for the version, or null. */
    public function acceptedAt(int $accountId, string $termsVersion): ?string
    {
        $value = $this->conn->fetchOne(
            'SELECT accepted_at FROM upload_terms_consents WHERE account_id = :account_id AND terms_version = :terms_version',
            ['account_id' => $accountId, 'terms_version' => $termsVersion],
        );

        return is_string($value) ? $value : null;
    }

    /**
     * Idempotent: a second acceptance for the same account and version keeps
     * the first row (its time, its user). A concurrent double insert hits the
     * UNIQUE key and is treated as already accepted.
     */
    public function record(int $accountId, ?int $boardId, int $userId, string $termsVersion, \DateTimeImmutable $now, ?string $uiLanguage = null): void
    {
        if ($this->acceptedAt($accountId, $termsVersion) !== null) {
            return;
        }

        try {
            $this->conn->insert('upload_terms_consents', [
                'account_id'    => $accountId,
                'board_id'      => $boardId,
                'user_id'       => $userId,
                'terms_version' => $termsVersion,
                'ui_language'   => $uiLanguage,
                'accepted_at'   => $now->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Concurrent acceptance: the first row stands.
        }
    }

    /**
     * Account erasure, run BEFORE the account row is deleted (ExpiredAccountCleaner).
     * The proof outlives the account, unlinked: account_id, board_id and user_id
     * become NULL, the account and board ids survive only as HMAC pseudonyms, and
     * pseudonymised_at starts the retention (UploadTerms::RETENTION_YEARS).
     *
     * @return int rows pseudonymised
     */
    public function pseudonymiseForAccount(int $accountId, IdentityHasher $hasher, \DateTimeImmutable $now): int
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, board_id FROM upload_terms_consents WHERE account_id = :account_id',
            ['account_id' => $accountId],
        );
        $accountPseudonym = $hasher->hashReference('upload-terms-account', $accountId);

        foreach ($rows as $row) {
            $boardId = is_numeric($row['board_id'] ?? null) ? (int) $row['board_id'] : null;
            $this->conn->executeStatement(
                'UPDATE upload_terms_consents
                    SET account_id = NULL, board_id = NULL, user_id = NULL,
                        account_pseudonym = :account_pseudonym, board_pseudonym = :board_pseudonym,
                        pseudonymised_at = :now
                  WHERE id = :id',
                [
                    'account_pseudonym' => $accountPseudonym,
                    'board_pseudonym'   => $boardId === null ? null : $hasher->hashReference('upload-terms-board', $boardId),
                    'now'               => $now->format('Y-m-d H:i:s'),
                    'id'                => (int) $row['id'],
                ],
            );
        }

        return count($rows);
    }

    /**
     * Retention (cleanup cron): deletes pseudonymised proof whose erasure is older
     * than the cutoff. Live proof (account still exists) is never touched.
     *
     * @return int rows deleted
     */
    public function purgePseudonymisedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->conn->executeStatement(
            'DELETE FROM upload_terms_consents WHERE pseudonymised_at IS NOT NULL AND pseudonymised_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }
}
