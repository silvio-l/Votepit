<?php

declare(strict_types=1);

namespace Votepit\Tests\Digest;

use Votepit\Digest\NewIdeasDigestService;
use Votepit\Domain\UnrestrictedPlanPolicy;
use Votepit\Mail\BoardMailBranding;
use Votepit\Mail\InMemoryMailer;
use Votepit\Participant\ParticipantMailCategory;
use Votepit\Participant\ParticipantMailRouter;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaFollowRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ParticipantMailRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\RateLimiter;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Opt-in weekly "new ideas" digest (close-the-loop re-engagement F): who gets
 * it, how often, and what it lists. A participant only gets it after an
 * explicit weekly opt-in; a week without new ideas sends nothing.
 */
final class NewIdeasDigestServiceTest extends IntegrationTestCase
{
    private const APP_KEY = 'new-ideas-digest-test-app-key-0123456789abcdef0123456789abcdef0123';

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('UTC'));
    }

    private function at(string $modifier, ?\DateTimeImmutable $base = null): string
    {
        return ($base ?? $this->now())->modify($modifier)->format('Y-m-d H:i:s');
    }

    private function service(InMemoryMailer $mailer): NewIdeasDigestService
    {
        $config   = $this->testConfig();
        $accounts = new AccountRepository($this->conn);

        return new NewIdeasDigestService(
            new ParticipantMailRepository($this->conn),
            new IdeaFollowRepository($this->conn),
            new IdeaRepository($this->conn),
            new BoardRepository($this->conn),
            $accounts,
            BoardMailBranding::forConnection($this->conn, $accounts, new UnrestrictedPlanPolicy()),
            $mailer,
            new RateLimiter($this->conn),
            ParticipantMailRouter::forConfig($this->conn, $config),
            $config,
        );
    }

    /**
     * A public board with one author, and a registered participant who voted
     * on an idea there (participation = vote, comment, own idea or follow).
     *
     * @return array{board_id: int, author_id: int, voter_id: int}
     */
    private function boardWithParticipant(string $tag, string $visibility = 'public'): array
    {
        $boardId  = $this->insertBoard("new-ideas-{$tag}", ['visibility' => $visibility]);
        $authorId = $this->insertUser("{$tag}-author@example.com");
        $voterId  = $this->insertUser("{$tag}-voter@example.com", ['notification_email' => "{$tag}-voter@example.com"]);

        $seed = $this->seedIdea($boardId, $authorId, 'Seed idea', ['created_at' => $this->at('-30 days')]);
        $this->seedVote($seed, $voterId, 1);

        return ['board_id' => $boardId, 'author_id' => $authorId, 'voter_id' => $voterId];
    }

    private function newIdea(int $boardId, int $authorId, string $title, string $createdAt): void
    {
        $this->seedIdea($boardId, $authorId, $title, ['created_at' => $createdAt, 'updated_at' => $createdAt]);
    }

    private function optIn(int $userId): void
    {
        (new ParticipantMailRepository($this->conn))->setCadence($userId, ParticipantMailCategory::NEW_IDEAS, ParticipantMailCategory::CADENCE_WEEKLY);
    }

    public function test_nothing_is_sent_without_an_explicit_opt_in(): void
    {
        $ids = $this->boardWithParticipant('no-optin');
        $this->newIdea($ids['board_id'], $ids['author_id'], 'Unrequested idea', $this->at('-1 day'));

        $mailer = new InMemoryMailer();
        $this->service($mailer)->run($this->now());
        self::assertSame(0, $mailer->count(), 'no preference row means off');

        // An explicit "off" is just as silent.
        (new ParticipantMailRepository($this->conn))->setCadence($ids['voter_id'], ParticipantMailCategory::NEW_IDEAS, ParticipantMailCategory::CADENCE_OFF);
        $this->service($mailer)->run($this->now());
        self::assertSame(0, $mailer->count());
    }

    public function test_an_opted_in_participant_gets_one_mail_listing_the_new_ideas(): void
    {
        $ids = $this->boardWithParticipant('weekly');
        $this->optIn($ids['voter_id']);
        $this->newIdea($ids['board_id'], $ids['author_id'], 'Fresh idea', $this->at('-1 day'));

        $mailer = new InMemoryMailer();
        $result = $this->service($mailer)->run($this->now());

        self::assertSame(1, $result->sent);
        self::assertSame(1, $mailer->count());
        $mail = $mailer->lastSent() ?? [];
        self::assertSame('weekly-voter@example.com', $mail['to'] ?? null);
        self::assertStringContainsString('Demo Board', $mail['subject']);
        self::assertStringContainsString('Fresh idea', $mail['body']);

        // Same week, second run: the window was advanced, nothing is repeated.
        self::assertSame(0, $this->service($mailer)->run($this->now())->sent);
        self::assertSame(1, $mailer->count());
    }

    public function test_a_week_without_new_ideas_sends_nothing(): void
    {
        $ids = $this->boardWithParticipant('quiet');
        $this->optIn($ids['voter_id']);
        // Created before the default one-week window: not new any more.
        $this->newIdea($ids['board_id'], $ids['author_id'], 'Old idea', $this->at('-10 days'));

        $mailer = new InMemoryMailer();
        $result = $this->service($mailer)->run($this->now());

        self::assertSame(0, $result->sent);
        self::assertSame(0, $mailer->count());
    }

    public function test_the_next_week_covers_only_ideas_added_since_the_last_send(): void
    {
        $ids = $this->boardWithParticipant('delta');
        $this->optIn($ids['voter_id']);
        $this->newIdea($ids['board_id'], $ids['author_id'], 'Idea before first send', $this->at('-1 day'));

        $mailer = new InMemoryMailer();
        self::assertSame(1, $this->service($mailer)->run($this->now())->sent);

        // An idea created after the first send, before the next run.
        $nextWeek = $this->now()->modify('+8 days');
        $this->newIdea($ids['board_id'], $ids['author_id'], 'Idea after first send', $this->at('+7 days'));
        self::assertSame(1, $this->service($mailer)->run($nextWeek)->sent);

        $second = $mailer->lastSent() ?? ['body' => ''];
        self::assertStringContainsString('Idea after first send', $second['body']);
        self::assertStringNotContainsString('Idea before first send', $second['body']);

        // Nothing new since: the next run is silent.
        self::assertSame(0, $this->service($mailer)->run($nextWeek->modify('+1 hour'))->sent);
        self::assertSame(2, $mailer->count());
    }

    public function test_private_boards_are_never_part_of_the_digest(): void
    {
        $ids = $this->boardWithParticipant('private', 'private');
        $this->optIn($ids['voter_id']);
        $this->newIdea($ids['board_id'], $ids['author_id'], 'Secret idea', $this->at('-1 day'));

        $mailer = new InMemoryMailer();
        self::assertSame(0, $this->service($mailer)->run($this->now())->sent);
        self::assertSame(0, $mailer->count());
    }

    public function test_compliance_frozen_boards_are_never_part_of_the_digest(): void
    {
        // A compliance freeze (legal hold) must keep the board's ideas out of mail.
        $ids = $this->boardWithParticipant('compliance-frozen');
        $this->conn->update('boards', ['compliance_frozen_at' => $this->at('-2 days')], ['id' => $ids['board_id']]);
        $this->optIn($ids['voter_id']);
        $this->newIdea($ids['board_id'], $ids['author_id'], 'Idea on a legal-hold board', $this->at('-1 day'));

        $mailer = new InMemoryMailer();
        self::assertSame(0, $this->service($mailer)->run($this->now())->sent);
        self::assertSame(0, $mailer->count());
    }

    public function test_guests_with_a_notification_address_never_get_the_new_ideas_digest(): void
    {
        $ids = $this->boardWithParticipant('guest');
        $this->newIdea($ids['board_id'], $ids['author_id'], 'Idea for guests', $this->at('-1 day'));
        $this->conn->insert('votes', [
            'idea_id'          => (int) $this->conn->fetchOne('SELECT id FROM ideas WHERE title = ?', ['Idea for guests']),
            'voter_token_hash' => 'tok-new-ideas-guest',
            'voter_email_enc'  => (new EncryptionService(self::APP_KEY, 'anon_vote_email'))->encrypt('guest-new-ideas@example.com'),
            'value'            => 1,
            'created_at'       => $this->at('-1 day'),
        ]);

        $mailer = new InMemoryMailer();
        $this->service($mailer)->run($this->now());

        self::assertNotContains('guest-new-ideas@example.com', array_column($mailer->sent, 'to'));
        self::assertSame(0, $mailer->count());
    }
}
