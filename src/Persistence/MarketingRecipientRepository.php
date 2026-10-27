<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Votepit\Domain\MarketingConsent;

/**
 * Query API for the senders of product-news and promotion mails (cloud,
 * W2). Core only answers "who may receive this kind of mail, and where": it
 * holds no plan logic. The cloud decides which users are paying customers and
 * passes their ids to existingCustomerRecipients().
 *
 * Both queries exclude blocked users and accounts scheduled for deletion. Every
 * recipient has a deliverable address on file (`users.marketing_email`).
 *
 * Mailing rules the callers must apply on top (see the spec, W2): one-click
 * unsubscribe in every mail, batching through the volume limits, an audit trail
 * and never twice for the same promotion and user.
 */
final readonly class MarketingRecipientRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Users with a confirmed (double opt-in) product-news consent.
     *
     * @return list<array{user_id: int, email: string}>
     * @throws DbalException
     */
    public function confirmedRecipients(): array
    {
        /** @var list<array{user_id: int|string, email: string}> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT u.id AS user_id, u.marketing_email AS email
             FROM marketing_consents c
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.status = :confirmed
               AND u.marketing_email IS NOT NULL
               AND u.is_blocked = 0
               AND u.deletion_scheduled_at IS NULL
             ORDER BY u.id',
            ['confirmed' => MarketingConsent::STATUS_CONFIRMED],
        );

        return $this->normalise($rows);
    }

    /**
     * Existing-customer (§ 7 Abs. 3 UWG) recipients among the given users: the
     * § 7 Abs. 3 notice was shown at signup, an address is on file, and the user
     * has not objected (a withdrawn consent is an objection). Confirmation is not
     * required on this path. The caller passes only users it already knows to be
     * paying customers.
     *
     * @param list<int> $userIds
     * @return list<array{user_id: int, email: string}>
     * @throws DbalException
     */
    public function existingCustomerRecipients(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map(intval(...), $userIds)));
        if ($userIds === []) {
            return [];
        }

        /** @var list<array{user_id: int|string, email: string}> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT u.id AS user_id, u.marketing_email AS email
             FROM users u
             WHERE u.id IN (:user_ids)
               AND u.existing_customer_notice_at IS NOT NULL
               AND u.marketing_email IS NOT NULL
               AND u.is_blocked = 0
               AND u.deletion_scheduled_at IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM marketing_consents c
                   WHERE c.user_id = u.id AND c.status = :withdrawn
               )
             ORDER BY u.id',
            ['user_ids' => $userIds, 'withdrawn' => MarketingConsent::STATUS_WITHDRAWN],
            ['user_ids' => ArrayParameterType::INTEGER],
        );

        return $this->normalise($rows);
    }

    /**
     * @param list<array{user_id: int|string, email: string}> $rows
     * @return list<array{user_id: int, email: string}>
     */
    private function normalise(array $rows): array
    {
        $recipients = [];
        foreach ($rows as $row) {
            $recipients[] = ['user_id' => (int) $row['user_id'], 'email' => $row['email']];
        }

        return $recipients;
    }
}
