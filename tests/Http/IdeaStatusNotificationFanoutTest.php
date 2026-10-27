<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Digest\ParticipantDigestService;
use Votepit\Domain\UnrestrictedPlanPolicy;
use Votepit\Mail\BoardMailBranding;
use Votepit\Mail\InMemoryMailer;
use Votepit\Participant\ParticipantMailRouter;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\ParticipantMailRepository;
use Votepit\Security\CsrfService;
use Votepit\Security\EncryptionService;
use Votepit\Security\RateLimiter;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the idea-status-changed notification fan-out: an
 * admin-triggered status change creates 'idea_status_changed' rows for
 * the idea's author + every distinct voter + every distinct commenter,
 * deduplicated, excluding the triggering admin — same "no central event
 * bus" fan-out pattern as CommentNotificationFanoutTest.
 */
final class IdeaStatusNotificationFanoutTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postStatus(string $slug, int $ideaId, string $status, ?int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/status')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'status' => $status]);
    }

    /** @return list<array<string, mixed>> */
    private function notificationsForUser(int $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            "SELECT scope, account_id, user_id, type, title, body, link_path
             FROM notifications
             WHERE scope = 'user' AND user_id = :user_id
             ORDER BY id ASC",
            ['user_id' => $userId],
        );

        return $rows;
    }

    private function seedAdmin(string $email): int
    {
        $adminId = $this->insertUser($email, ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');

        return $adminId;
    }

    /**
     * Inserts a raw anonymous vote row with an ENCRYPTED notification
     * email — same encryption context ('anon_vote_email') VoteAction uses,
     * so IdeaStatusAction's decrypt-and-mail fan-out can be exercised
     * without going through the vote-casting HTTP endpoint itself.
     */
    private function seedAnonymousVoteWithEmail(int $ideaId, string $voterTokenHash, string $email): void
    {
        $encryption = new EncryptionService(str_repeat('a', 64), 'anon_vote_email');
        $this->conn->insert('votes', [
            'idea_id'          => $ideaId,
            'voter_token_hash' => $voterTokenHash,
            'voter_email_enc'  => $encryption->encrypt($email),
            'value'            => 1,
            'created_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Author, voter, commenter all get notified
    // -------------------------------------------------------------------------

    public function test_status_change_notifies_author_voter_and_commenter(): void
    {
        $boardId = $this->insertBoard('status-fanout-basic');
        $adminId = $this->seedAdmin('status-fanout-admin@example.com');
        $authorId = $this->insertUser('status-fanout-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $authorId, 'Great idea');

        $voterId     = $this->insertUser('status-fanout-voter@example.com');
        $commenterId = $this->insertUser('status-fanout-commenter@example.com');
        $this->seedVote($ideaId, $voterId, 1);
        $this->seedComment($ideaId, $commenterId, 'Nice!');

        $response = $this->createApp()->handle(
            $this->postStatus('status-fanout-basic', $ideaId, 'planned', $adminId),
        );
        self::assertSame(200, $response->getStatusCode());

        foreach ([$authorId, $voterId, $commenterId] as $userId) {
            $notes = $this->notificationsForUser($userId);
            self::assertCount(1, $notes, "user {$userId} must receive exactly one notification");
            self::assertSame('idea_status_changed', $notes[0]['type']);
            self::assertSame($this->defaultAccountId(), (int) $notes[0]['account_id']);
            self::assertStringContainsString('Great idea', $notes[0]['body']);
            self::assertStringContainsString('/status-fanout-basic/idea/' . $ideaId, (string) $notes[0]['link_path']);
        }
    }

    // -------------------------------------------------------------------------
    // Dedup — a user with multiple roles (author + voter + commenter) gets
    // exactly one notification, not one per role.
    // -------------------------------------------------------------------------

    public function test_user_with_multiple_roles_gets_exactly_one_notification(): void
    {
        $boardId = $this->insertBoard('status-fanout-dedup');
        $adminId = $this->seedAdmin('status-fanout-dedup-admin@example.com');
        $authorId = $this->insertUser('status-fanout-dedup-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $authorId);

        // The author also votes on and comments on their own idea.
        $this->seedVote($ideaId, $authorId, 1);
        $this->seedComment($ideaId, $authorId, 'My own follow-up');

        $response = $this->createApp()->handle(
            $this->postStatus('status-fanout-dedup', $ideaId, 'planned', $adminId),
        );
        self::assertSame(200, $response->getStatusCode());

        $notes = $this->notificationsForUser($authorId);
        self::assertCount(1, $notes, 'A user with multiple roles must receive exactly one notification, not one per role.');
        self::assertSame('idea_status_changed', $notes[0]['type']);
    }

    // -------------------------------------------------------------------------
    // Self-trigger — the admin who changes the status is never notified
    // themselves, even if they are also the author/voter/commenter.
    // -------------------------------------------------------------------------

    public function test_triggering_admin_receives_no_self_notification(): void
    {
        $boardId = $this->insertBoard('status-fanout-self');
        $adminId = $this->seedAdmin('status-fanout-self-admin@example.com');
        $ideaId  = $this->seedIdea($boardId, $adminId);
        $this->seedVote($ideaId, $adminId, 1);
        $this->seedComment($ideaId, $adminId, 'Admin also commented');

        $response = $this->createApp()->handle(
            $this->postStatus('status-fanout-self', $ideaId, 'planned', $adminId),
        );
        self::assertSame(200, $response->getStatusCode());

        self::assertSame([], $this->notificationsForUser($adminId));
    }

    public function test_triggering_admin_not_notified_even_as_third_party_author(): void
    {
        $boardId = $this->insertBoard('status-fanout-self2');
        $adminId = $this->seedAdmin('status-fanout-self2-admin@example.com');
        $authorId = $this->insertUser('status-fanout-self2-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $authorId);
        // The admin also voted/commented, in addition to triggering the change.
        $this->seedVote($ideaId, $adminId, 1);
        $this->seedComment($ideaId, $adminId, 'Admin note');

        $this->createApp()->handle(
            $this->postStatus('status-fanout-self2', $ideaId, 'planned', $adminId),
        );

        self::assertSame([], $this->notificationsForUser($adminId));
        self::assertCount(1, $this->notificationsForUser($authorId));
    }

    // -------------------------------------------------------------------------
    // No-op — a self→self status change creates zero notification rows.
    // -------------------------------------------------------------------------

    public function test_noop_status_change_creates_no_notifications(): void
    {
        $boardId = $this->insertBoard('status-fanout-noop');
        $adminId = $this->seedAdmin('status-fanout-noop-admin@example.com');
        $authorId = $this->insertUser('status-fanout-noop-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $authorId, 'Test idea', ['status' => 'planned']);
        $voterId = $this->insertUser('status-fanout-noop-voter@example.com');
        $this->seedVote($ideaId, $voterId, 1);

        $response = $this->createApp()->handle(
            $this->postStatus('status-fanout-noop', $ideaId, 'planned', $adminId),
        );
        self::assertSame(200, $response->getStatusCode());

        self::assertSame([], $this->notificationsForUser($authorId));
        self::assertSame([], $this->notificationsForUser($voterId));
    }

    // -------------------------------------------------------------------------
    // Invalid transition — 422, no DB write, no notifications.
    // -------------------------------------------------------------------------

    public function test_invalid_transition_creates_no_notifications(): void
    {
        $boardId = $this->insertBoard('status-fanout-invalid');
        $adminId = $this->seedAdmin('status-fanout-invalid-admin@example.com');
        $authorId = $this->insertUser('status-fanout-invalid-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $authorId, 'Test idea', ['status' => 'declined']);
        $voterId = $this->insertUser('status-fanout-invalid-voter@example.com');
        $this->seedVote($ideaId, $voterId, 1);

        // declined -> done is not a valid transition (see StatusService).
        $response = $this->createApp()->handle(
            $this->postStatus('status-fanout-invalid', $ideaId, 'done', $adminId),
        );
        self::assertSame(422, $response->getStatusCode());

        self::assertSame([], $this->notificationsForUser($authorId));
        self::assertSame([], $this->notificationsForUser($voterId));
    }

    // -------------------------------------------------------------------------
    // Preference gating — notify_idea_status_inapp = 0 suppresses the in-app row.
    // -------------------------------------------------------------------------

    public function test_recipient_with_inapp_flag_off_receives_no_row(): void
    {
        $boardId = $this->insertBoard('status-fanout-pref-off');
        $adminId = $this->seedAdmin('status-fanout-pref-off-admin@example.com');
        $authorId = $this->insertUser('status-fanout-pref-off-author@example.com', [
            'notify_idea_status_inapp' => 0,
        ]);
        $ideaId = $this->seedIdea($boardId, $authorId);

        $response = $this->createApp()->handle(
            $this->postStatus('status-fanout-pref-off', $ideaId, 'planned', $adminId),
        );
        self::assertSame(200, $response->getStatusCode());

        self::assertSame([], $this->notificationsForUser($authorId));
    }

    // -------------------------------------------------------------------------
    // ADR 0004 extension: anonymous voters who left an optional
    // notification email actually receive the status-change email — the
    // whole reason voter_email_enc is reversibly encrypted, not hashed.
    // -------------------------------------------------------------------------

    /** Delivers the participant digest the way the hourly cron does. */
    private function runParticipantDigest(InMemoryMailer $mailer): void
    {
        $config = $this->testConfig();
        (new ParticipantDigestService(
            new ParticipantMailRepository($this->conn),
            BoardMailBranding::forConnection($this->conn, new AccountRepository($this->conn), new UnrestrictedPlanPolicy()),
            $mailer,
            new RateLimiter($this->conn),
            new EncryptionService($config->appKey, 'anon_vote_email'),
            ParticipantMailRouter::forConfig($this->conn, $config),
            $config,
        ))->run(new \DateTimeImmutable());
    }

    public function test_status_change_emails_anonymous_voter_with_notification_email(): void
    {
        $boardId  = $this->insertBoard('status-fanout-anon');
        $adminId  = $this->seedAdmin('status-fanout-anon-admin@example.com');
        $authorId = $this->insertUser('status-fanout-anon-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Anon-voted idea');

        $this->seedAnonymousVoteWithEmail($ideaId, 'tok-anon-status', 'anon-voter@example.com');

        $mailer   = new InMemoryMailer();
        $response = $this->createApp($mailer)->handle(
            $this->postStatus('status-fanout-anon', $ideaId, 'planned', $adminId),
        );
        self::assertSame(200, $response->getStatusCode());

        // Guests are batched: the status change is queued and the hourly
        // participant digest delivers it.
        $this->runParticipantDigest($mailer);

        $sent = array_values(array_filter($mailer->sent, static fn (array $m): bool => $m['to'] === 'anon-voter@example.com'));
        self::assertCount(1, $sent, 'the anonymous voter must receive exactly one status-change email');
        self::assertStringContainsString('Anon-voted idea', $sent[0]['subject']);
        self::assertStringContainsString('Anon-voted idea', $sent[0]['body']);
        self::assertStringContainsString('/status-fanout-anon/idea/' . $ideaId, $sent[0]['body']);

        // No plaintext PII leaks into the audit log either.
        self::assertStringNotContainsString('anon-voter@example.com', $this->readAuditLog());
    }

    public function test_status_change_emails_each_distinct_anonymous_voter_address_only_once(): void
    {
        $boardId  = $this->insertBoard('status-fanout-anon-dedup');
        $adminId  = $this->seedAdmin('status-fanout-anon-dedup-admin@example.com');
        $authorId = $this->insertUser('status-fanout-anon-dedup-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        // Two different anonymous voters leaving the SAME (differently
        // capitalized/spaced) address must be mailed exactly once — the
        // ciphertexts differ (nonce-randomized encryption), only the
        // decrypted, normalized address is deduplicated.
        $this->seedAnonymousVoteWithEmail($ideaId, 'tok-anon-dup-1', 'Same@Example.com');
        $this->seedAnonymousVoteWithEmail($ideaId, 'tok-anon-dup-2', ' same@example.com ');

        $mailer = new InMemoryMailer();
        $this->createApp($mailer)->handle(
            $this->postStatus('status-fanout-anon-dedup', $ideaId, 'planned', $adminId),
        );
        $this->runParticipantDigest($mailer);

        $normalizedRecipients = array_map(
            static fn (string $to): string => strtolower(trim($to)),
            array_column($mailer->sent, 'to'),
        );
        $sent = array_filter($normalizedRecipients, static fn (string $to): bool => $to === 'same@example.com');
        self::assertCount(1, $sent, 'the same normalized address must be mailed exactly once');
    }

    public function test_status_change_skips_corrupted_anonymous_voter_ciphertext_without_failing(): void
    {
        $boardId  = $this->insertBoard('status-fanout-anon-corrupt');
        $adminId  = $this->seedAdmin('status-fanout-anon-corrupt-admin@example.com');
        $authorId = $this->insertUser('status-fanout-anon-corrupt-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        $this->conn->insert('votes', [
            'idea_id'          => $ideaId,
            'voter_token_hash' => 'tok-anon-corrupt',
            'voter_email_enc'  => 'not-a-valid-ciphertext',
            'value'            => 1,
            'created_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $mailer   = new InMemoryMailer();
        $response = $this->createApp($mailer)->handle(
            $this->postStatus('status-fanout-anon-corrupt', $ideaId, 'planned', $adminId),
        );

        // Fail-secure: the status change itself must still succeed, and no
        // email is sent for the un-decryptable row.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $mailer->count());
    }
}
