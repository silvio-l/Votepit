<?php

declare(strict_types=1);

namespace Votepit\Tests\Digest;

use Votepit\Config;
use Votepit\Digest\WeeklyDigestService;
use Votepit\Mail\InMemoryMailer;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailVolumeMonitor;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\DigestUnsubscribeToken;
use Votepit\Security\RateLimiter;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Weekly owner digest (competitor-parity issue 14): who gets it, what it
 * contains, skip-empty-weeks, weekly cadence, and the mail-volume guards
 * (per-run budget + shared-hosting headroom via MailVolumeMonitor's buckets).
 */
final class WeeklyDigestServiceTest extends IntegrationTestCase
{
    private InMemoryMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new InMemoryMailer();
    }

    private function service(int $maxPerRun = 30, ?Config $config = null, ?Mailer $mailer = null): WeeklyDigestService
    {
        return new WeeklyDigestService(
            new UserRepository($this->conn),
            new AccountMemberRepository($this->conn),
            new BoardRepository($this->conn),
            new IdeaRepository($this->conn),
            $mailer ?? $this->mailer,
            new RateLimiter($this->conn),
            new DigestUnsubscribeToken(str_repeat('a', 64)),
            $config ?? $this->testConfig(),
            maxPerRun: $maxPerRun,
        );
    }

    /**
     * An account with one owner (confirmed notification email) and one active board.
     *
     * @param array<string, mixed> $ownerOverrides
     *
     * @return array{account_id: int, owner_id: int, board_id: int}
     */
    private function seedActiveAccount(string $tag, array $ownerOverrides = []): array
    {
        $accountId = $this->insertAccount(['slug' => "acct-{$tag}", 'name' => "Account {$tag}"]);
        $ownerId   = $this->insertUser("{$tag}-owner@example.com", ['notification_email' => "{$tag}-owner@example.com", ...$ownerOverrides]);
        $this->insertAccountMember($accountId, $ownerId, 'owner');
        $boardId = $this->insertBoard("board-{$tag}", ['account_id' => $accountId, 'name' => "Board {$tag}"]);
        $this->seedIdea($boardId, $ownerId, "Fresh idea {$tag}");

        return ['account_id' => $accountId, 'owner_id' => $ownerId, 'board_id' => $boardId];
    }

    /** @return array{to: string, subject: string, body: string, html: string|null, image: \Votepit\Mail\InlineImage|null} */
    private function lastSent(): array
    {
        $sent = $this->mailer->lastSent();
        self::assertNotNull($sent, 'expected a digest mail to be sent');

        return $sent;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    public function test_owner_with_confirmed_email_receives_the_digest_by_default(): void
    {
        $this->seedActiveAccount('def');

        $result = $this->service()->run($this->now());

        self::assertSame(1, $result->sent);
        self::assertSame(1, $this->mailer->count());
        $mail = $this->mailer->lastSent();
        self::assertNotNull($mail);
        self::assertSame('def-owner@example.com', $mail['to']);
        self::assertStringContainsString('Board def', $mail['body']);
        self::assertStringContainsString('Fresh idea def', $mail['body']);
    }

    public function test_owner_without_confirmed_notification_email_gets_nothing(): void
    {
        $seed = $this->seedActiveAccount('noaddr');
        $this->conn->update('users', ['notification_email' => null], ['id' => $seed['owner_id']]);

        $this->service()->run($this->now());

        self::assertSame(0, $this->mailer->count());
    }

    public function test_explicit_opt_out_is_respected(): void
    {
        $this->seedActiveAccount('optout', ['notify_weekly_digest_email' => 0]);

        $this->service()->run($this->now());

        self::assertSame(0, $this->mailer->count());
    }

    public function test_blocked_user_gets_nothing(): void
    {
        $this->seedActiveAccount('blocked', ['is_blocked' => 1]);

        $this->service()->run($this->now());

        self::assertSame(0, $this->mailer->count());
    }

    public function test_admin_gets_it_only_after_explicit_opt_in_and_moderator_never(): void
    {
        $seed    = $this->seedActiveAccount('roles');
        $admin   = $this->insertUser('roles-admin@example.com', ['notification_email' => 'roles-admin@example.com']);
        $optedIn = $this->insertUser('roles-admin2@example.com', ['notification_email' => 'roles-admin2@example.com', 'notify_weekly_digest_email' => 1]);
        $mod     = $this->insertUser('roles-mod@example.com', ['notification_email' => 'roles-mod@example.com', 'notify_weekly_digest_email' => 1]);
        $this->insertAccountMember($seed['account_id'], $admin, 'admin');
        $this->insertAccountMember($seed['account_id'], $optedIn, 'admin');
        $this->insertAccountMember($seed['account_id'], $mod, 'moderator');

        $this->service()->run($this->now());

        $recipients = array_column($this->mailer->sent, 'to');
        sort($recipients);
        self::assertSame(['roles-admin2@example.com', 'roles-owner@example.com'], $recipients);
    }

    public function test_digest_only_covers_the_recipients_own_accounts(): void
    {
        $this->seedActiveAccount('mine');
        $this->seedActiveAccount('theirs');

        $this->service()->run($this->now());

        foreach ($this->mailer->sent as $mail) {
            $own   = str_starts_with($mail['to'], 'mine') ? 'mine' : 'theirs';
            $other = $own === 'mine' ? 'theirs' : 'mine';
            self::assertStringContainsString("Board {$own}", $mail['body']);
            self::assertStringNotContainsString("Board {$other}", $mail['body'], 'cross-tenant leak in digest');
            self::assertStringNotContainsString("Fresh idea {$other}", (string) $mail['html']);
        }
        self::assertSame(2, $this->mailer->count());
    }

    public function test_empty_week_sends_nothing_but_marks_the_user_processed(): void
    {
        $accountId = $this->insertAccount(['slug' => 'acct-quiet']);
        $ownerId   = $this->insertUser('quiet@example.com', ['notification_email' => 'quiet@example.com']);
        $this->insertAccountMember($accountId, $ownerId, 'owner');
        $boardId = $this->insertBoard('board-quiet', ['account_id' => $accountId]);
        $old     = $this->now()->modify('-20 days')->format('Y-m-d H:i:s');
        $ideaId  = $this->seedIdea($boardId, $ownerId, 'Old idea', ['created_at' => $old]);
        $this->seedVote($ideaId, $ownerId, 1, ['created_at' => $old]);

        $result = $this->service()->run($this->now());

        self::assertSame(0, $this->mailer->count());
        self::assertSame(1, $result->skippedEmpty);
        self::assertNotNull($this->conn->fetchOne('SELECT digest_processed_at FROM users WHERE id = :id', ['id' => $ownerId]));
    }

    public function test_a_user_is_mailed_at_most_once_per_week(): void
    {
        $seed = $this->seedActiveAccount('weekly');
        $now  = $this->now();

        $this->service()->run($now);
        $this->service()->run($now->modify('+1 hour'));
        $this->service()->run($now->modify('+6 days'));

        self::assertSame(1, $this->mailer->count());

        // A week later, with fresh activity in that new week.
        $later = $now->modify('+7 days');
        $this->seedIdea($seed['board_id'], $seed['owner_id'], 'Next week idea', ['created_at' => $later->format('Y-m-d H:i:s')]);
        $this->service()->run($later);
        self::assertSame(2, $this->mailer->count());
    }

    public function test_waiting_line_counts_unanswered_ideas_older_than_three_days(): void
    {
        // Shared predicate with the worklist (P-06 Q-W2b): 3 days, not the 7-day digest window.
        $seed = $this->seedActiveAccount('wait3');
        $this->seedIdea($seed['board_id'], $seed['owner_id'], 'Four days waiting', ['created_at' => $this->now()->modify('-4 days')->format('Y-m-d H:i:s')]);
        $this->seedIdea($seed['board_id'], $seed['owner_id'], 'Two days old', ['created_at' => $this->now()->modify('-2 days')->format('Y-m-d H:i:s')]);

        $this->service()->run($this->now());

        // Subject carries the waiting count; the four-day-old idea is the only one that waits.
        self::assertSame('Votepit: 1 idea is waiting for your answer', $this->lastSent()['subject']);
        self::assertStringContainsString('On Board wait3 for more than 3 days without your answer.', $this->lastSent()['body']);
    }

    public function test_per_board_highlights_keep_up_and_down_votes_separate(): void
    {
        $seed  = $this->seedActiveAccount('hl');
        $board = $seed['board_id'];
        $voters = [];
        for ($i = 0; $i < 6; $i++) {
            $voters[] = $this->insertUser("hl-voter{$i}@example.com");
        }

        $loved    = $this->seedIdea($board, $seed['owner_id'], 'Loved idea');
        $hated    = $this->seedIdea($board, $seed['owner_id'], 'Hated idea');
        $split    = $this->seedIdea($board, $seed['owner_id'], 'Split idea');
        foreach (array_slice($voters, 0, 5) as $v) {
            $this->seedVote($loved, $v, 1);
        }
        foreach (array_slice($voters, 0, 4) as $v) {
            $this->seedVote($hated, $v, -1);
        }
        foreach (array_slice($voters, 0, 3) as $v) {
            $this->seedVote($split, $v, 1);
        }
        foreach (array_slice($voters, 3, 3) as $v) {
            $this->seedVote($split, $v, -1);
        }

        $this->service()->run($this->now());

        $body = $this->lastSent()['body'];
        // Each idea once, each highlight kind once, up and down votes separately.
        self::assertStringContainsString("Loved idea\nMost voted · 5 for / 0 against", $body);
        self::assertStringContainsString("Split idea\nDivided opinions · 3 for / 3 against", $body);
        self::assertStringContainsString("Hated idea\nMost down votes · 0 for / 4 against", $body);
        self::assertSame(1, substr_count($body, "Loved idea\n"));
        self::assertMatchesRegularExpression('/\d+% approval/', $body);
        // Review rules: approval wording only, no consensus, no decorative glyph.
        self::assertStringNotContainsStringIgnoringCase('consensus', $body);
        self::assertStringNotContainsString('■', $body);
    }

    public function test_german_language_preference_renders_german(): void
    {
        $this->seedActiveAccount('de', ['digest_language' => 'de']);

        $this->service()->run($this->now());

        $mail = $this->mailer->lastSent();
        self::assertNotNull($mail);
        self::assertStringContainsString('Deine Votepit-Woche', $mail['subject']); // export-ok: comment-language
        self::assertStringContainsString('Wochenüberblick abbestellen', $mail['body']); // export-ok: comment-language
    }

    public function test_mail_carries_a_working_unsubscribe_link(): void
    {
        $seed = $this->seedActiveAccount('unsub');

        $this->service()->run($this->now());

        $body = $this->lastSent()['body'];
        self::assertSame(1, preg_match('#http://localhost:8000/digest/unsubscribe\?token=([A-Za-z0-9_.-]+)#', $body, $m));
        self::assertSame($seed['owner_id'], (new DigestUnsubscribeToken(str_repeat('a', 64)))->verify($m[1]));
    }

    public function test_board_links_carry_the_account_slug_in_cloud_mode(): void
    {
        $this->seedActiveAccount('cloudy');
        $config = Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud']));

        $this->service(config: $config)->run($this->now());

        // The button and its plain-text link carry the account slug.
        self::assertStringContainsString('http://localhost:8000/acct-cloudy/board-cloudy', $this->lastSent()['body']);
    }

    public function test_per_run_budget_caps_sends_and_the_rest_follow_next_run(): void
    {
        $this->seedActiveAccount('b1');
        $this->seedActiveAccount('b2');
        $this->seedActiveAccount('b3');

        $first = $this->service(maxPerRun: 2)->run($this->now());
        self::assertSame(2, $first->sent);
        self::assertTrue($first->budgetExhausted);

        $this->service(maxPerRun: 2)->run($this->now()->modify('+1 hour'));
        self::assertSame(3, $this->mailer->count());
    }

    public function test_stops_when_shared_mail_volume_leaves_no_headroom(): void
    {
        $this->seedActiveAccount('busy');
        $limiter = new RateLimiter($this->conn);
        for ($i = 0; $i < 45; $i++) {
            $limiter->count(MailVolumeMonitor::HOURLY_BUCKET, MailVolumeMonitor::HOURLY_WINDOW_SECONDS);
        }

        $result = $this->service()->run($this->now());

        self::assertSame(0, $this->mailer->count());
        self::assertTrue($result->budgetExhausted);
        self::assertNull($this->conn->fetchOne('SELECT digest_processed_at FROM users WHERE email_hmac IS NOT NULL AND notification_email = :e', ['e' => 'busy-owner@example.com']));
    }

    public function test_a_failing_send_does_not_mark_processed_and_does_not_stop_the_run(): void
    {
        $this->seedActiveAccount('f1');
        $this->seedActiveAccount('f2');
        $failing = new class () implements Mailer {
            public int $calls = 0;

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null, ?\Votepit\Mail\InlineImage $inlineImage = null, ?string $fromName = null, array $headers = []): void
            {
                $this->calls++;
                throw new \RuntimeException('smtp down');
            }
        };

        $result = $this->service(mailer: $failing)->run($this->now());

        self::assertSame(2, $failing->calls);
        self::assertSame(2, $result->failed);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users WHERE digest_processed_at IS NOT NULL'));
    }

    public function test_idea_titles_are_escaped_in_the_html_part(): void
    {
        $seed = $this->seedActiveAccount('xss');
        $this->seedIdea($seed['board_id'], $seed['owner_id'], '<script>alert(1)</script>');

        $this->service()->run($this->now());

        $html = (string) $this->lastSent()['html'];
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
