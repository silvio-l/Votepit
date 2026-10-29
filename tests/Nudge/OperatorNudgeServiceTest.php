<?php

declare(strict_types=1);

namespace Votepit\Tests\Nudge;

use Votepit\Config;
use Votepit\Mail\InlineImage;
use Votepit\Mail\InMemoryMailer;
use Votepit\Mail\Mailer;
use Votepit\Nudge\OperatorNudgeDispatcher;
use Votepit\Nudge\OperatorNudgeService;
use Votepit\Persistence\OperatorNudgeRepository;
use Votepit\Security\OperatorNudgeUnsubscribeToken;
use Votepit\Security\RateLimiter;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Operator nudges (onboarding, quiet board): who gets which mail, when, and
 * how often. Every run takes an explicit $now so the timing rules are exact.
 */
final class OperatorNudgeServiceTest extends IntegrationTestCase
{
    private const KEY = 'operator-nudge-service-test-key-0123456789abcdef0123456789abcdef';

    private InMemoryMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new InMemoryMailer();
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('UTC'));
    }

    private function at(string $modifier): string
    {
        return $this->now()->modify($modifier)->format('Y-m-d H:i:s');
    }

    private function service(int $maxPerRun = 20, ?Mailer $mailer = null, ?Config $config = null): OperatorNudgeService
    {
        $config ??= $this->testConfig();
        $nudges = new OperatorNudgeRepository($this->conn);

        return new OperatorNudgeService(
            $nudges,
            new OperatorNudgeDispatcher(
                $nudges,
                $mailer ?? $this->mailer,
                new RateLimiter($this->conn),
                new OperatorNudgeUnsubscribeToken(self::KEY),
                $config,
            ),
            $config,
            maxPerRun: $maxPerRun,
        );
    }

    /**
     * An account with one owner who logged in recently and one live board.
     *
     * @return array{account_id: int, owner_id: int, board_id: int, board_slug: string}
     */
    private function account(string $tag, string $createdModifier, bool $publicBoard = true): array
    {
        $accountId = $this->insertAccount([
            'slug'       => "acct-{$tag}",
            'name'       => "Account {$tag}",
            'created_at' => $this->at($createdModifier),
        ]);
        $ownerId = $this->insertUser("{$tag}-owner@example.com", [
            'notification_email' => "{$tag}-owner@example.com",
            'created_at'         => $this->at('-400 days'),
            'verified_at'        => $this->at('-400 days'),
        ]);
        $this->insertAccountMember($accountId, $ownerId, 'owner');
        $this->loginAt($ownerId, '-1 day');

        $boardSlug = "board-{$tag}";
        $boardId   = $this->insertBoard($boardSlug, [
            'account_id' => $accountId,
            'name'       => "Board {$tag}",
            'visibility' => $publicBoard ? 'public' : 'private',
            'created_at' => $this->at($createdModifier),
        ]);

        return ['account_id' => $accountId, 'owner_id' => $ownerId, 'board_id' => $boardId, 'board_slug' => $boardSlug];
    }

    private function loginAt(int $userId, string $modifier): void
    {
        $this->conn->insert('login_tokens', [
            'user_id'    => $userId,
            'token_hash' => str_repeat('c', 64),
            'expires_at' => $this->at('+1 day'),
            'used_at'    => $this->at($modifier),
            'created_at' => $this->at($modifier),
        ]);
    }

    /** @return array{account_id: int, owner_id: int, board_id: int, board_slug: string} */
    private function quietBoardFixture(string $tag): array
    {
        $account = $this->account($tag, '-90 days');
        // Last activity 30 days ago: quiet for longer than the 21-day threshold.
        $this->seedIdea($account['board_id'], $account['owner_id'], "Old idea {$tag}", [
            'created_at' => $this->at('-30 days'),
            'updated_at' => $this->at('-30 days'),
        ]);

        return $account;
    }

    public function test_switched_off_installation_sends_no_nudge_and_reports_no_failure(): void
    {
        $this->account('koff', '-3 days -1 hour');
        $config = Config::fromArray($this->testConfigArray(['participant_mails' => false]));

        $result = $this->service(config: $config)->run($this->now());

        self::assertSame(0, $result->sent);
        self::assertSame(0, $result->failed);
        self::assertSame(0, $this->mailer->count());
    }

    public function test_day_three_mail_goes_to_the_owner_when_no_public_idea_exists(): void
    {
        $this->account('d3', '-3 days -1 hour');

        $result = $this->service()->run($this->now());

        self::assertSame(1, $result->sent);
        self::assertSame(1, $this->mailer->count());
        self::assertSame('d3-owner@example.com', $this->mailer->lastSent()['to'] ?? null);
    }

    public function test_no_onboarding_mail_before_day_three(): void
    {
        $this->account('early', '-2 days');

        self::assertSame(0, $this->service()->run($this->now())->sent);
        self::assertSame(0, $this->mailer->count());
    }

    public function test_a_done_first_idea_step_is_not_nudged(): void
    {
        $account = $this->account('done', '-3 days -1 hour');
        $this->seedIdea($account['board_id'], $account['owner_id'], 'Published', ['moderation_state' => 'published']);

        // Day 3 is skipped (a public idea exists); day 7 is not due yet.
        self::assertSame(0, $this->service()->run($this->now())->sent);
    }

    public function test_never_more_than_one_onboarding_mail_per_day_and_none_twice(): void
    {
        $this->account('twice', '-8 days');

        // Day 3 and day 7 are both due, but only one mail goes out on a given day.
        $service = $this->service();
        self::assertSame(1, $service->run($this->now())->sent);
        self::assertSame(0, $service->run($this->now())->sent);
        self::assertSame(1, $this->mailer->count());
    }

    public function test_day_seven_mail_when_the_first_idea_exists_but_no_outside_vote_does(): void
    {
        $account = $this->account('d7', '-8 days');
        $this->seedIdea($account['board_id'], $account['owner_id'], 'Mine', ['moderation_state' => 'published']);

        self::assertSame(1, $this->service()->run($this->now())->sent);
    }

    public function test_quiet_board_mail_once_per_quiet_period_and_rearms_after_activity(): void
    {
        $account = $this->quietBoardFixture('quiet');
        $service = $this->service();

        self::assertSame(1, $service->run($this->now())->sent);
        self::assertSame(0, $service->run($this->now())->sent, 'same quiet period: no second mail');

        // Activity returns: the nudge re-arms.
        $this->seedIdea($account['board_id'], $account['owner_id'], 'Fresh', [
            'created_at' => $this->at('-1 day'),
            'updated_at' => $this->at('-1 day'),
        ]);
        self::assertSame(0, $service->run($this->now())->sent);

        // Quiet again 25 days later: one new mail.
        self::assertSame(1, $service->run($this->now()->modify('+25 days'))->sent);
        self::assertSame(2, $this->mailer->count());
    }

    public function test_admin_gets_the_nudge_only_with_the_digest_opt_in(): void
    {
        $account = $this->quietBoardFixture('admin');
        $adminId = $this->insertUser('admin-member@example.com', [
            'notification_email'          => 'admin-member@example.com',
            'notify_weekly_digest_email'  => 0,
            'verified_at'                 => $this->at('-400 days'),
            'created_at'                  => $this->at('-400 days'),
        ]);
        $this->insertAccountMember($account['account_id'], $adminId, 'admin');
        $this->loginAt($adminId, '-1 day');

        // Only the owner is nudged while the admin has not opted in.
        self::assertSame(1, $this->service()->run($this->now())->sent);
        self::assertSame(['admin-owner@example.com'], array_column($this->mailer->sent, 'to'));

        $this->conn->update('users', ['notify_weekly_digest_email' => 1], ['id' => $adminId]);

        self::assertSame(1, $this->service()->run($this->now())->sent);
        self::assertSame('admin-member@example.com', $this->mailer->lastSent()['to'] ?? null);
    }

    public function test_switched_off_kind_sends_nothing_to_that_owner(): void
    {
        $account = $this->quietBoardFixture('off');
        $this->conn->update('users', ['notify_quiet_board_email' => 0], ['id' => $account['owner_id']]);

        self::assertSame(0, $this->service()->run($this->now())->sent);
        self::assertSame(0, $this->mailer->count());
    }

    public function test_owner_not_active_for_ninety_days_is_skipped(): void
    {
        $account = $this->quietBoardFixture('decay');
        $this->conn->executeStatement(
            'UPDATE login_tokens SET used_at = :used WHERE user_id = :user',
            ['used' => $this->at('-100 days'), 'user' => $account['owner_id']],
        );

        self::assertSame(0, $this->service()->run($this->now())->sent);
    }

    public function test_decay_follows_last_seen_at_when_it_is_recorded(): void
    {
        $account = $this->quietBoardFixture('seen-recent');
        // Login token says 100 days ago, but the app visit was yesterday: active.
        $this->conn->executeStatement(
            'UPDATE login_tokens SET used_at = :used WHERE user_id = :user',
            ['used' => $this->at('-100 days'), 'user' => $account['owner_id']],
        );
        $this->conn->executeStatement(
            'UPDATE users SET last_seen_at = :seen WHERE id = :user',
            ['seen' => $this->at('-1 day'), 'user' => $account['owner_id']],
        );

        self::assertSame(1, $this->service()->run($this->now())->sent);
    }

    public function test_a_recorded_last_seen_at_overrides_a_recent_login_token(): void
    {
        $account = $this->quietBoardFixture('seen-stale');
        // The account fixture's login token is 1 day old, but the recorded app
        // visit is 100 days old: last_seen_at is the signal once it exists.
        $this->conn->executeStatement(
            'UPDATE users SET last_seen_at = :seen WHERE id = :user',
            ['seen' => $this->at('-100 days'), 'user' => $account['owner_id']],
        );

        self::assertSame(0, $this->service()->run($this->now())->sent);
        self::assertSame(0, $this->mailer->count());
    }

    public function test_each_account_mails_only_its_own_owner_and_board(): void
    {
        $this->quietBoardFixture('tenant-a');
        $this->quietBoardFixture('tenant-b');

        self::assertSame(2, $this->service()->run($this->now())->sent);

        $recipients = array_column($this->mailer->sent, 'to');
        sort($recipients);
        self::assertSame(['tenant-a-owner@example.com', 'tenant-b-owner@example.com'], $recipients);

        foreach ($this->mailer->sent as $mail) {
            $board = str_starts_with($mail['to'], 'tenant-a') ? 'Board tenant-a' : 'Board tenant-b';
            $other = $board === 'Board tenant-a' ? 'Board tenant-b' : 'Board tenant-a';
            self::assertStringContainsString($board, $mail['body']);
            self::assertStringNotContainsString($other, $mail['body']);
        }
    }

    public function test_the_per_run_budget_caps_sends_and_reports_it(): void
    {
        $this->quietBoardFixture('budget-a');
        $this->quietBoardFixture('budget-b');

        $result = $this->service(maxPerRun: 1)->run($this->now());

        self::assertSame(1, $result->sent);
        self::assertTrue($result->budgetExhausted);
        self::assertSame(1, $this->mailer->count());
    }

    public function test_a_failed_send_releases_its_claim_and_the_next_run_retries(): void
    {
        $this->quietBoardFixture('retry');
        $failing = new class () implements Mailer {
            public int $calls = 0;

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null, ?InlineImage $inlineImage = null, ?string $fromName = null, array $headers = []): void
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new \RuntimeException('smtp down');
                }
            }
        };

        $first = $this->service(mailer: $failing)->run($this->now());
        self::assertSame(0, $first->sent);
        self::assertSame(1, $first->failed);

        self::assertSame(1, $this->service(mailer: $failing)->run($this->now())->sent);
        self::assertSame(2, $failing->calls);
    }
}
