<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Digest\ParticipantDigestService;
use Votepit\Domain\UnrestrictedPlanPolicy;
use Votepit\Mail\AccountMailLinks;
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
 * Participant mails in cloud path routing (routing_mode: cloud): board URLs are
 * "/{account-slug}/{board-slug}/...", so every link inside an e-mail must carry
 * the account segment. The in-app notification link stays relative (the SPA
 * adds the prefix itself) and is covered by the fan-out tests.
 */
final class CloudModeMailLinksTest extends IntegrationTestCase
{
    private const ACCOUNT_SLUG = 'alpha';
    private const BOARD_SLUG   = 'board-a';

    private function cloudConfig(): Config
    {
        return Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud']));
    }

    /** @return array{account_id: int, board_id: int, admin_id: int} */
    private function cloudBoardWithOwner(): array
    {
        $accountId = $this->insertAccount(['slug' => self::ACCOUNT_SLUG, 'plan' => 'pro']);
        $boardId   = $this->insertBoard(self::BOARD_SLUG, ['account_id' => $accountId]);
        $adminId   = $this->insertUser('cloud-owner@example.com');
        $this->insertAccountMember($accountId, $adminId, 'owner');

        return ['account_id' => $accountId, 'board_id' => $boardId, 'admin_id' => $adminId];
    }

    private function postStatus(int $ideaId, string $status, int $userId): ServerRequestInterface
    {
        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . self::ACCOUNT_SLUG . '/' . self::BOARD_SLUG . '/ideas/' . $ideaId . '/status')
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token), 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody(['_csrf' => $token, 'status' => $status]);
    }

    private function postComment(int $ideaId, string $body, int $userId): ServerRequestInterface
    {
        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . self::ACCOUNT_SLUG . '/' . self::BOARD_SLUG . '/ideas/' . $ideaId . '/comments')
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token), 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody(['_csrf' => $token, 'body' => $body]);
    }

    /** @return array<string, mixed> the single mail sent to $to */
    private function mailTo(InMemoryMailer $mailer, string $to): array
    {
        $mails = array_values(array_filter($mailer->sent, static fn (array $m): bool => $m['to'] === $to));
        self::assertCount(1, $mails, "exactly one mail to {$to}");

        return $mails[0];
    }

    public function test_instant_status_mail_links_to_the_account_prefixed_idea(): void
    {
        $ids      = $this->cloudBoardWithOwner();
        $authorId = $this->insertUser('cloud-author@example.com', ['notification_email' => 'cloud-author@example.com']);
        $ideaId   = $this->seedIdea($ids['board_id'], $authorId, 'Cloud idea');

        $mailer   = new InMemoryMailer();
        $response = $this->createApp($mailer, config: $this->cloudConfig())
            ->handle($this->postStatus($ideaId, 'planned', $ids['admin_id']));
        self::assertSame(200, $response->getStatusCode());

        $mail = $this->mailTo($mailer, 'cloud-author@example.com');
        self::assertStringContainsString($this->testConfig()->appUrl . '/alpha/board-a/idea/' . $ideaId, $mail['body']);
        self::assertStringNotContainsString($this->testConfig()->appUrl . '/board-a/idea/', $mail['body']);
    }

    public function test_instant_comment_mail_links_to_the_account_prefixed_comment(): void
    {
        $ids      = $this->cloudBoardWithOwner();
        $authorId = $this->insertUser('cloud-comment-author@example.com', ['notification_email' => 'cloud-comment-author@example.com']);
        $ideaId   = $this->seedIdea($ids['board_id'], $authorId, 'Commented idea');
        $commenter = $this->insertUser('cloud-commenter@example.com');

        $mailer   = new InMemoryMailer();
        $response = $this->createApp($mailer, config: $this->cloudConfig())
            ->handle($this->postComment($ideaId, 'Nice one', $commenter));
        self::assertSame(201, $response->getStatusCode());

        $mail = $this->mailTo($mailer, 'cloud-comment-author@example.com');
        self::assertStringContainsString($this->testConfig()->appUrl . '/alpha/board-a/idea/' . $ideaId . '#comment-', $mail['body']);
    }

    public function test_queued_digest_link_is_account_prefixed_in_cloud_mode(): void
    {
        $ids      = $this->cloudBoardWithOwner();
        $authorId = $this->insertUser('cloud-digest-author@example.com');
        $ideaId   = $this->seedIdea($ids['board_id'], $authorId, 'Guest-voted idea');

        // An anonymous voter with a notification address: digest-only, queued.
        $this->conn->insert('votes', [
            'idea_id'          => $ideaId,
            'voter_token_hash' => 'tok-cloud-guest',
            'voter_email_enc'  => (new EncryptionService(str_repeat('a', 64), 'anon_vote_email'))->encrypt('cloud-guest@example.com'),
            'value'            => 1,
            'created_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->createApp(new InMemoryMailer(), config: $this->cloudConfig())
            ->handle($this->postStatus($ideaId, 'planned', $ids['admin_id']));

        $mailer = new InMemoryMailer();
        $config = $this->cloudConfig();
        (new ParticipantDigestService(
            new ParticipantMailRepository($this->conn),
            BoardMailBranding::forConnection($this->conn, new AccountRepository($this->conn), new UnrestrictedPlanPolicy()),
            $mailer,
            new RateLimiter($this->conn),
            new EncryptionService($config->appKey, 'anon_vote_email'),
            ParticipantMailRouter::forConfig($this->conn, $config),
            $config,
        ))->run(new \DateTimeImmutable());

        $mail = $this->mailTo($mailer, 'cloud-guest@example.com');
        self::assertStringContainsString($config->appUrl . '/alpha/board-a/idea/' . $ideaId, $mail['body']);
        self::assertStringNotContainsString($config->appUrl . '/board-a/idea/', $mail['body']);
    }

    public function test_self_host_links_are_unchanged(): void
    {
        // Self-host runs exactly one (default) account; its boards have no account segment.
        $boardId  = $this->insertBoard('selfhost-board');
        $adminId  = $this->insertUser('selfhost-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $authorId = $this->insertUser('selfhost-author@example.com', ['notification_email' => 'selfhost-author@example.com']);
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Self-host idea');

        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/selfhost-board/ideas/' . $ideaId . '/status')
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token), 'votepit_sess' => $this->sessionCookie($adminId)])
            ->withParsedBody(['_csrf' => $token, 'status' => 'planned']);

        $mailer = new InMemoryMailer();
        $this->createApp($mailer)->handle($request);

        $mail = $this->mailTo($mailer, 'selfhost-author@example.com');
        self::assertStringContainsString($this->testConfig()->appUrl . '/selfhost-board/idea/' . $ideaId, $mail['body']);
    }

    public function test_the_helper_resolves_only_known_accounts_in_cloud_mode(): void
    {
        $accountId = $this->insertAccount(['slug' => 'helper-acct']);
        $links     = new AccountMailLinks(new AccountRepository($this->conn), 'cloud');

        self::assertSame('/helper-acct/board/idea/3', $links->path($accountId, '/board/idea/3'));

        $this->expectException(\RuntimeException::class);
        $links->path(999999, '/board/idea/3');
    }
}
