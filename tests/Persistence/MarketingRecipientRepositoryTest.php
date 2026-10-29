<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Cleanup\ExpiredUserCleaner;
use Votepit\Domain\MarketingConsent;
use Votepit\Domain\UserDeletionGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Monitoring\NullErrorReporter;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\MarketingConsentRepository;
use Votepit\Persistence\MarketingRecipientRepository;
use Votepit\Persistence\UserErasureRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Recipient queries for product-news mail (core answers only "who and where"),
 * their exclusions, and erasure of the consent proof on account deletion.
 */
final class MarketingRecipientRepositoryTest extends IntegrationTestCase
{
    private function recipients(): MarketingRecipientRepository
    {
        return new MarketingRecipientRepository($this->conn);
    }

    private function consents(): MarketingConsentRepository
    {
        return new MarketingConsentRepository($this->conn);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-26 12:00:00');
    }

    /** @param array<string, mixed> $extra */
    private function user(string $email, array $extra = []): int
    {
        return $this->insertUser($email, ['marketing_email' => $email] + $extra);
    }

    private function confirm(int $userId, string $email): void
    {
        $this->consents()->requestConfirmation($userId, MarketingConsent::SOURCE_SIGNUP, null, null, $this->now());
        $this->consents()->setMarketingEmail($userId, $email);
        $this->consents()->markConfirmed($userId, $this->now());
    }

    /**
     * @param list<array{user_id: int, email: string}> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => $row['user_id'], $rows);
    }

    public function test_confirmed_consent_with_an_address_is_a_recipient(): void
    {
        $userId = $this->user('confirmed@example.com');
        $this->confirm($userId, 'confirmed@example.com');

        self::assertSame(
            [['user_id' => $userId, 'email' => 'confirmed@example.com']],
            $this->recipients()->confirmedRecipients(),
        );
    }

    public function test_pending_or_withdrawn_consent_is_not_a_confirmed_recipient(): void
    {
        $pending = $this->user('pending@example.com');
        $this->consents()->requestConfirmation($pending, MarketingConsent::SOURCE_PROFILE, str_repeat('a', 64), $this->now()->modify('+1 day'), $this->now());
        $withdrawn = $this->user('withdrawn@example.com');
        $this->confirm($withdrawn, 'withdrawn@example.com');
        $this->consents()->withdraw($withdrawn, $this->now());

        self::assertSame([], $this->recipients()->confirmedRecipients());
    }

    public function test_blocked_and_scheduled_for_deletion_are_never_confirmed_recipients(): void
    {
        $blocked   = $this->user('blocked@example.com', ['is_blocked' => 1]);
        $scheduled = $this->user('scheduled@example.com', ['deletion_scheduled_at' => '2026-10-27 00:00:00']);
        $this->confirm($blocked, 'blocked@example.com');
        $this->confirm($scheduled, 'scheduled@example.com');

        self::assertSame([], $this->recipients()->confirmedRecipients());
    }

    public function test_confirmed_consent_without_an_address_is_skipped(): void
    {
        $userId = $this->insertUser('no-address@example.com');
        $this->conn->insert('marketing_consents', [
            'user_id'      => $userId,
            'status'       => MarketingConsent::STATUS_CONFIRMED,
            'source'       => MarketingConsent::SOURCE_SIGNUP,
            'text_version' => MarketingConsent::TEXT_VERSION,
            'confirmed_at' => '2026-10-26 12:00:00',
        ]);

        self::assertSame([], $this->recipients()->confirmedRecipients());
    }

    public function test_existing_customers_need_the_notice_and_an_address_but_no_confirmation(): void
    {
        $customer  = $this->user('customer@example.com', ['existing_customer_notice_at' => '2026-10-01 10:00:00']);
        $noNotice  = $this->user('no-notice@example.com');
        $noAddress = $this->insertUser('no-address2@example.com', ['existing_customer_notice_at' => '2026-10-01 10:00:00']);

        self::assertSame(
            [['user_id' => $customer, 'email' => 'customer@example.com']],
            $this->recipients()->existingCustomerRecipients([$customer, $noNotice, $noAddress]),
        );
    }

    public function test_an_objection_removes_an_existing_customer_from_the_list(): void
    {
        $userId = $this->user('objector@example.com', ['existing_customer_notice_at' => '2026-10-01 10:00:00']);
        $this->consents()->withdraw($userId, $this->now());

        self::assertSame([], $this->recipients()->existingCustomerRecipients([$userId]));
    }

    public function test_existing_customer_query_excludes_blocked_and_scheduled_users(): void
    {
        $blocked   = $this->user('blocked-customer@example.com', ['existing_customer_notice_at' => '2026-10-01 10:00:00', 'is_blocked' => 1]);
        $scheduled = $this->user('scheduled-customer@example.com', ['existing_customer_notice_at' => '2026-10-01 10:00:00', 'deletion_scheduled_at' => '2026-10-27 00:00:00']);

        self::assertSame([], $this->recipients()->existingCustomerRecipients([$blocked, $scheduled]));
    }

    public function test_empty_user_list_returns_no_existing_customers(): void
    {
        self::assertSame([], $this->recipients()->existingCustomerRecipients([]));
    }

    public function test_only_the_listed_customers_are_returned(): void
    {
        $listed   = $this->user('listed@example.com', ['existing_customer_notice_at' => '2026-10-01 10:00:00']);
        $unlisted = $this->user('unlisted@example.com', ['existing_customer_notice_at' => '2026-10-01 10:00:00']);

        $result = $this->ids($this->recipients()->existingCustomerRecipients([$listed]));

        self::assertSame([$listed], $result);
        self::assertNotContains($unlisted, $result);
    }

    public function test_erasing_an_account_removes_its_consent_proof(): void
    {
        $userId = $this->user('erased@example.com');
        $this->confirm($userId, 'erased@example.com');
        $this->conn->update('users', ['deletion_scheduled_at' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s')], ['id' => $userId]);
        $users   = new UserRepository($this->conn);
        $cleaner = new ExpiredUserCleaner(
            $users,
            new UserErasureRepository($this->conn, []),
            new UserDeletionGuard(new AccountMemberRepository($this->conn), $users),
            new AuditLogger($this->logFile),
            new NullErrorReporter(),
            $this->avatarDir,
        );

        $cleaner->run($users->findExpiredForDeletion(new \DateTimeImmutable()));

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM marketing_consents WHERE user_id = :id', ['id' => $userId]));
        self::assertSame([], $this->recipients()->confirmedRecipients());
    }
}
