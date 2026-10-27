<?php

declare(strict_types=1);

namespace Votepit\Tests\Digest;

use Votepit\Config;
use Votepit\Digest\ParticipantDigestService;
use Votepit\Domain\UnrestrictedPlanPolicy;
use Votepit\Mail\BoardMailBranding;
use Votepit\Mail\InlineImage;
use Votepit\Mail\InMemoryMailer;
use Votepit\Mail\Mailer;
use Votepit\Participant\ParticipantMailCategory;
use Votepit\Participant\ParticipantMailRouter;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\ParticipantMailRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\ParticipantUnsubscribeToken;
use Votepit\Security\RateLimiter;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Participant digest: one mail per recipient and period (bundling), the claim
 * as idempotency key, claim release on a failed send, and deferral (never a
 * drop) under the shared volume ceiling.
 */
final class ParticipantDigestServiceTest extends IntegrationTestCase
{
    private InMemoryMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new InMemoryMailer();
    }

    private function router(): ParticipantMailRouter
    {
        return new ParticipantMailRouter(
            new ParticipantMailRepository($this->conn),
            new EncryptionService(str_repeat('b', 64), 'anon_vote_email'),
            str_repeat('c', 64),
            new ParticipantUnsubscribeToken(str_repeat('a', 64)),
            'https://app.test',
        );
    }

    private function service(Mailer $mailer, int $hourlyVolumeCeiling = 40): ParticipantDigestService
    {
        return new ParticipantDigestService(
            new ParticipantMailRepository($this->conn),
            BoardMailBranding::forConnection($this->conn, new AccountRepository($this->conn), new UnrestrictedPlanPolicy()),
            $mailer,
            new RateLimiter($this->conn),
            new EncryptionService(str_repeat('b', 64), 'anon_vote_email'),
            $this->router(),
            $this->testConfig(),
            hourlyVolumeCeiling: $hourlyVolumeCeiling,
        );
    }

    /**
     * A recipient who wants batched participant mails and has queued two
     * status changes on one board.
     *
     * @return array{user_id: int, board_id: int}
     */
    private function seedQueuedUser(): array
    {
        $userId  = $this->insertUser('participant@example.com', ['notification_email' => 'participant@example.com']);
        $boardId = $this->insertBoard('participant-board');
        $ideaA   = $this->seedIdea($boardId, $userId, 'First idea');
        $ideaB   = $this->seedIdea($boardId, $userId, 'Second idea');

        $mail = new ParticipantMailRepository($this->conn);
        $mail->setCadence($userId, ParticipantMailCategory::PARTICIPATED, ParticipantMailCategory::CADENCE_DAILY);

        $now = new \DateTimeImmutable('2026-10-26 10:00:00');
        $this->router()->routeUser($userId, ParticipantMailCategory::PARTICIPATED, $boardId, $ideaA, 'status', ['title' => 'First idea', 'status' => 'Planned'], "/participant-board/idea/{$ideaA}", $now);
        $this->router()->routeUser($userId, ParticipantMailCategory::PARTICIPATED, $boardId, $ideaB, 'status', ['title' => 'Second idea', 'status' => 'Done'], "/participant-board/idea/{$ideaB}", $now);

        return ['user_id' => $userId, 'board_id' => $boardId];
    }

    public function testQueuedItemsAreBundledIntoOneMail(): void
    {
        $seed   = $this->seedQueuedUser();
        $result = $this->service($this->mailer)->run(new \DateTimeImmutable('2026-10-26 11:00:00'));

        self::assertSame(1, $result->sent);
        self::assertSame(0, $result->failed);

        $sent = $this->mailer->lastSent();
        self::assertNotNull($sent);
        self::assertStringContainsString('First idea', $sent['body']);
        self::assertStringContainsString('Second idea', $sent['body']);

        $mail = new ParticipantMailRepository($this->conn);
        self::assertSame([], $mail->pendingForUser($seed['user_id'], 10));
    }

    public function testSecondRunInSamePeriodSendsNothingAndKeepsRowsPending(): void
    {
        $seed = $this->seedQueuedUser();
        $this->service($this->mailer)->run(new \DateTimeImmutable('2026-10-26 11:00:00'));

        // Items arriving later the same day wait for the next period, not a second mail.
        $idea = $this->seedIdea($seed['board_id'], $seed['user_id'], 'Third idea');
        $this->router()->routeUser($seed['user_id'], ParticipantMailCategory::PARTICIPATED, $seed['board_id'], $idea, 'comment', ['title' => 'Third idea'], "/participant-board/idea/{$idea}", new \DateTimeImmutable('2026-10-26 12:00:00'));

        $result = $this->service($this->mailer)->run(new \DateTimeImmutable('2026-10-26 13:00:00'));

        self::assertSame(0, $result->sent);
        self::assertCount(1, (new ParticipantMailRepository($this->conn))->pendingForUser($seed['user_id'], 10));
    }

    public function testSwitchedOffInstallationQueuesNothingAndSendsNothing(): void
    {
        $seed    = $this->seedQueuedUser();
        $config  = Config::fromArray($this->testConfigArray(['participant_mails' => false]));
        $mail    = new ParticipantMailRepository($this->conn);
        $router  = ParticipantMailRouter::forConfig($this->conn, $config);
        $boardId = $seed['board_id'];
        $now     = new \DateTimeImmutable('2026-10-26 10:30:00');

        // The router drops a new change instead of queuing it.
        self::assertSame(ParticipantMailRouter::RESULT_DROPPED, $router->routeUser($seed['user_id'], ParticipantMailCategory::PARTICIPATED, $boardId, 1, 'status', ['title' => 'Off'], '/x', $now));

        // The digest job is a no-op: nothing sent, the pending rows stay untouched.
        $service = new ParticipantDigestService(
            $mail,
            BoardMailBranding::forConnection($this->conn, new AccountRepository($this->conn), new UnrestrictedPlanPolicy()),
            $this->mailer,
            new RateLimiter($this->conn),
            new EncryptionService(str_repeat('b', 64), 'anon_vote_email'),
            $router,
            $config,
        );
        $result = $service->run(new \DateTimeImmutable('2026-10-26 11:00:00'));

        self::assertSame(0, $result->sent);
        self::assertSame(0, $result->failed);
        self::assertSame(0, $this->mailer->count());
        self::assertCount(2, $mail->pendingForUser($seed['user_id'], 10));
    }

    public function testDefaultInstallationRoutesToTheQueueAsBefore(): void
    {
        $userId  = $this->insertUser('default-on@example.com', ['notification_email' => 'default-on@example.com']);
        $boardId = $this->insertBoard('default-on-board');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Default on');
        $mail    = new ParticipantMailRepository($this->conn);
        $mail->setCadence($userId, ParticipantMailCategory::PARTICIPATED, ParticipantMailCategory::CADENCE_DAILY);

        $result = ParticipantMailRouter::forConfig($this->conn, $this->testConfig())->routeUser($userId, ParticipantMailCategory::PARTICIPATED, $boardId, $ideaId, 'status', ['title' => 'Default on'], '/x', new \DateTimeImmutable('2026-10-26 10:00:00'));

        self::assertSame(ParticipantMailRouter::RESULT_QUEUED, $result);
        self::assertCount(1, $mail->pendingForUser($userId, 10));
    }

    public function testFailedSendReleasesTheClaimAndKeepsRowsPending(): void
    {
        $seed   = $this->seedQueuedUser();
        $failed = $this->service(new class () implements Mailer {
            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null, ?InlineImage $inlineImage = null, ?string $fromName = null, array $headers = []): void
            {
                throw new \RuntimeException('smtp down');
            }
        })->run(new \DateTimeImmutable('2026-10-26 11:00:00'));

        self::assertSame(0, $failed->sent);
        self::assertSame(1, $failed->failed);
        self::assertCount(2, (new ParticipantMailRepository($this->conn))->pendingForUser($seed['user_id'], 10));

        // The claim was released, so the next run in the same period delivers.
        $retry = $this->service($this->mailer)->run(new \DateTimeImmutable('2026-10-26 12:00:00'));

        self::assertSame(1, $retry->sent);
        self::assertSame([], (new ParticipantMailRepository($this->conn))->pendingForUser($seed['user_id'], 10));
    }

    public function testVolumeCeilingDefersInsteadOfDropping(): void
    {
        $seed   = $this->seedQueuedUser();
        $result = $this->service($this->mailer, hourlyVolumeCeiling: 0)->run(new \DateTimeImmutable('2026-10-26 11:00:00'));

        self::assertSame(0, $result->sent);
        self::assertTrue($result->budgetExhausted);
        self::assertCount(2, (new ParticipantMailRepository($this->conn))->pendingForUser($seed['user_id'], 10));
    }
}
