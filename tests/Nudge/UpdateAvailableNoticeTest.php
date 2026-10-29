<?php

declare(strict_types=1);

namespace Votepit\Tests\Nudge;

use Votepit\Config;
use Votepit\Mail\InMemoryMailer;
use Votepit\Nudge\OperatorNudgeDispatcher;
use Votepit\Nudge\OperatorNudgeService;
use Votepit\Persistence\OperatorNudgeRepository;
use Votepit\Security\OperatorNudgeUnsubscribeToken;
use Votepit\Security\RateLimiter;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * The "new Community release" operator notice: one mail per version to the
 * owner, opt-out respected, nothing when participant mails are off.
 */
final class UpdateAvailableNoticeTest extends IntegrationTestCase
{
    private const KEY = 'update-available-notice-test-key-0123456789abcdef0123456789abcdef';

    private InMemoryMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new InMemoryMailer();
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-28 06:31:00', new \DateTimeZone('UTC'));
    }

    private function service(?Config $config = null): OperatorNudgeService
    {
        $config ??= $this->testConfig();
        $nudges = new OperatorNudgeRepository($this->conn);

        return new OperatorNudgeService(
            $nudges,
            new OperatorNudgeDispatcher(
                $nudges,
                $this->mailer,
                new RateLimiter($this->conn),
                new OperatorNudgeUnsubscribeToken(self::KEY),
                $config,
            ),
            $config,
        );
    }

    /** One live account with an owner; returns the owner's user id. */
    private function ownerOfAccount(string $tag, ?int $notify = null, string $since = '2026-10-01 00:00:00'): int
    {
        $accountId = $this->insertAccount([
            'slug'       => "upd-{$tag}",
            'name'       => "Update {$tag}",
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $extra = ['notification_email' => "{$tag}@example.com", 'created_at' => $since, 'verified_at' => $since];
        if ($notify !== null) {
            $extra['notify_update_available_email'] = $notify;
        }
        $ownerId = $this->insertUser("{$tag}@example.com", $extra);
        $this->insertAccountMember($accountId, $ownerId, 'owner');

        return $ownerId;
    }

    public function test_the_owner_gets_one_notice_per_version(): void
    {
        $this->ownerOfAccount('once');

        self::assertSame(1, $this->service()->updateAvailable($this->now(), '0.1.7', '0.1.8')->sent);
        self::assertSame(0, $this->service()->updateAvailable($this->now(), '0.1.7', '0.1.8')->sent);
        self::assertSame(1, $this->service()->updateAvailable($this->now(), '0.1.8', '0.1.9')->sent);
        self::assertSame(2, $this->mailer->count());
    }

    public function test_an_owner_inactive_for_months_still_gets_the_notice(): void
    {
        // Unlike the other operator nudges, update notices ignore the 90-day activity window:
        // an owner who rarely logs in is exactly the one who would miss a (security) release.
        $this->ownerOfAccount('dormant', null, '2026-01-01 00:00:00');

        self::assertSame(1, $this->service()->updateAvailable($this->now(), '0.1.7', '0.1.8')->sent);
    }

    public function test_an_owner_who_opted_out_gets_nothing(): void
    {
        $this->ownerOfAccount('quiet', 0);

        self::assertSame(0, $this->service()->updateAvailable($this->now(), '0.1.7', '0.1.8')->sent);
        self::assertSame(0, $this->mailer->count());
    }

    public function test_switched_off_participant_mails_send_nothing(): void
    {
        $this->ownerOfAccount('off');
        $config = Config::fromArray($this->testConfigArray(['participant_mails' => false]));

        self::assertSame(0, $this->service($config)->updateAvailable($this->now(), '0.1.7', '0.1.8')->sent);
        self::assertSame(0, $this->mailer->count());
    }
}
