<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Persistence\UserErasureRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\CsrfService;
use Votepit\Security\TokenVault;
use Votepit\Security\Webhook\WebhookSenderInterface;
use Votepit\Security\Webhook\WebhookSendResult;
use Votepit\Security\Webhook\WebhookTarget;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Trusted authors bypass pre-moderation (broaden-use-cases issue 11,
 * migration 0089): a moderator marks an author as trusted per account
 * (manually, from the approval queue), revokes it from the trusted-authors
 * list, and a drop to warned/suspended standing clears it automatically.
 * Guests and the deleted-user placeholder can never be trusted.
 */
final class TrustedAuthorsTest extends IntegrationTestCase
{
    private ?object $sender = null;

    /** @param array<string, mixed> $body */
    private function send(string $method, string $path, ?int $userId, array $body = []): ServerRequestInterface
    {
        $csrf    = new CsrfService(str_repeat('a', 64), 3600, false);
        $token   = $csrf->generate();
        $cookies = [$csrf->cookieName() => $csrf->sign($token)];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withCookieParams($cookies)
            ->withHeader('Accept', 'application/json');

        if ($method !== 'GET') {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-CSRF-Token', $token)
                ->withParsedBody($body);
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    /** @return App<null> */
    private function app(): App
    {
        $this->sender = new class () implements WebhookSenderInterface {
            /** @var list<array<string, mixed>> */
            public array $events = [];

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                $decoded = json_decode($body, true);
                $this->events[] = is_array($decoded) ? $decoded : [];

                return WebhookSendResult::response(200, null);
            }
        };

        return $this->createApp(
            webhookSender: $this->sender,
            webhookDeferRunner: static fn (\Closure $work): mixed => $work(),
        );
    }

    /** @return list<string> */
    private function webhookEvents(): array
    {
        $sender = $this->sender;
        self::assertNotNull($sender);
        /** @var list<array<string, mixed>> $events */
        $events = $sender->events ?? [];

        return array_map(static fn (array $e): string => (string) ($e['event'] ?? ''), $events);
    }

    /**
     * premoderation=all board in the default account with an owner, a
     * moderator and a plain (non-member) author.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} [boardId, ownerId, moderatorId, authorId]
     */
    private function seedBoard(string $slug, string $mode = 'all'): array
    {
        $boardId     = $this->insertBoard($slug, ['premoderation' => $mode]);
        $ownerId     = $this->insertUser($slug . '-owner@example.com');
        $moderatorId = $this->insertUser($slug . '-mod@example.com');
        $authorId    = $this->insertUser($slug . '-author@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $this->insertAccountMember($this->defaultAccountId(), $moderatorId, 'moderator');

        return [$boardId, $ownerId, $moderatorId, $authorId];
    }

    private function validTimeTrap(): string
    {
        $ts  = (string) (time() - 5);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, str_repeat('a', 64), true)), '+/', '-_'), '=');

        return $ts . '.' . $mac;
    }

    /** @param App<null> $app */
    private function submit(App $app, string $slug, int $userId, string $title): ResponseInterface
    {
        return $app->handle($this->send('POST', '/' . $slug . '/ideas', $userId, [
            'title'    => $title,
            'body'     => 'A description that is long enough to pass validation.',
            '_form_at' => $this->validTimeTrap(),
        ]));
    }

    private function trust(int $accountId, int $userId, ?int $byUserId = null, string $state = 'normal'): void
    {
        $this->seedStanding($accountId, $userId, $state, 0, [
            'trusted_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'trusted_by_user_id' => $byUserId,
        ]);
    }

    private function trustedAt(int $accountId, int $userId): mixed
    {
        return $this->conn->fetchOne(
            'SELECT trusted_at FROM account_member_standing WHERE account_id = :a AND user_id = :u',
            ['a' => $accountId, 'u' => $userId],
        );
    }

    /** @param App<null> $app */
    private function configureWebhook(App $app, string $slug, int $ownerId): void
    {
        $response = $app->handle($this->send('PUT', '/admin/boards/' . $slug . '/webhook', $ownerId, ['url' => 'https://93.184.216.34/hook']));
        self::assertSame(200, $response->getStatusCode());
    }

    private function moderationState(int $ideaId): string
    {
        return (string) $this->conn->fetchOne('SELECT moderation_state FROM ideas WHERE id = :id', ['id' => $ideaId]);
    }

    // -------------------------------------------------------------------------
    // Submit decision
    // -------------------------------------------------------------------------

    public function test_trusted_authors_idea_publishes_immediately_with_publish_side_effects(): void
    {
        [, $ownerId, , $authorId] = $this->seedBoard('ta-submit');
        $this->trust($this->defaultAccountId(), $authorId);
        $app = $this->app();
        $this->configureWebhook($app, 'ta-submit', $ownerId);

        $response = $this->submit($app, 'ta-submit', $authorId, 'Trusted idea');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['ok', 'id'], array_keys($this->json($response)), 'same response as an immediately published idea');
        self::assertSame('published', $this->moderationState((int) $this->json($response)['id']));
        self::assertSame(['idea.created'], $this->webhookEvents());
        self::assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM notifications WHERE type = 'ideas_pending_approval'"));
    }

    public function test_untrusted_authors_idea_stays_pending(): void
    {
        [, , , $authorId] = $this->seedBoard('ta-untrusted');
        $otherId          = $this->insertUser('ta-untrusted-other@example.com');
        $this->trust($this->defaultAccountId(), $otherId);

        $response = $this->submit($this->app(), 'ta-untrusted', $authorId, 'Untrusted idea');

        self::assertSame('pending', $this->json($response)['moderation_state']);
    }

    public function test_stale_trust_next_to_a_warned_standing_never_bypasses(): void
    {
        [, , , $authorId] = $this->seedBoard('ta-stale');
        $this->trust($this->defaultAccountId(), $authorId, null, 'warned');

        $response = $this->submit($this->app(), 'ta-stale', $authorId, 'Warned idea');

        self::assertSame('pending', $this->json($response)['moderation_state']);
    }

    public function test_trust_in_another_account_has_no_effect_here(): void
    {
        [, , , $authorId] = $this->seedBoard('ta-xt-read');
        $foreignAccount   = $this->insertAccount(['slug' => 'acct-ta-foreign']);
        $this->trust($foreignAccount, $authorId);

        $response = $this->submit($this->app(), 'ta-xt-read', $authorId, 'Cross-tenant idea');

        self::assertSame('pending', $this->json($response)['moderation_state']);
    }

    public function test_anonymous_mode_and_off_mode_are_unchanged_for_trusted_authors(): void
    {
        [, , , $authorId] = $this->seedBoard('ta-anon', 'anonymous');
        $this->insertBoard('ta-off', ['premoderation' => 'off', 'is_default' => 0]);
        $this->trust($this->defaultAccountId(), $authorId);
        $app = $this->app();

        self::assertSame(['ok', 'id'], array_keys($this->json($this->submit($app, 'ta-anon', $authorId, 'Anon mode idea'))));
        self::assertSame(['ok', 'id'], array_keys($this->json($this->submit($app, 'ta-off', $authorId, 'Off mode idea'))));
    }

    // -------------------------------------------------------------------------
    // Agent API (write grant, issue 15 rules)
    // -------------------------------------------------------------------------

    private function apiCreate(string $token): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/ideas')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['title' => 'Agent idea', 'body' => 'Submitted by an account-issued agent token.']);
    }

    public function test_api_write_token_of_a_trusted_author_publishes_immediately(): void
    {
        [$boardId, $ownerId, , $authorId] = $this->seedBoard('ta-api');
        $this->insertAccountMember($this->defaultAccountId(), $authorId, 'admin');
        $this->trust($this->defaultAccountId(), $authorId);
        $pair = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $authorId, $pair['hash'], scope: 'write');
        $app = $this->app();
        $this->configureWebhook($app, 'ta-api', $ownerId);

        $response = $app->handle($this->apiCreate($pair['token']));

        self::assertSame(201, $response->getStatusCode());
        self::assertArrayNotHasKey('moderation_state', $this->json($response));
        self::assertSame('published', $this->conn->fetchOne('SELECT moderation_state FROM ideas'));
        self::assertSame(['idea.created'], $this->webhookEvents());
    }

    public function test_api_write_token_of_an_untrusted_author_stays_pending(): void
    {
        [$boardId, , , $authorId] = $this->seedBoard('ta-api-untrusted');
        $this->insertAccountMember($this->defaultAccountId(), $authorId, 'admin');
        $foreignAccount = $this->insertAccount(['slug' => 'acct-ta-api-foreign']);
        $this->trust($foreignAccount, $authorId);
        $pair = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $authorId, $pair['hash'], scope: 'write');

        $response = $this->app()->handle($this->apiCreate($pair['token']));

        self::assertSame('pending', $this->json($response)['moderation_state']);
    }

    // -------------------------------------------------------------------------
    // Approve and trust (queue)
    // -------------------------------------------------------------------------

    public function test_approve_and_trust_publishes_the_idea_and_the_next_one_skips_the_queue(): void
    {
        [$boardId, , $moderatorId, $authorId] = $this->seedBoard('ta-queue');
        $app    = $this->app();
        $ideaId = (int) $this->json($this->submit($app, 'ta-queue', $authorId, 'First idea'))['id'];
        $later  = $this->seedIdea($boardId, $authorId, 'Older pending idea', ['moderation_state' => 'pending']);

        $response = $app->handle($this->send('POST', '/admin/boards/ta-queue/queue/approve-and-trust', $moderatorId, ['id' => $ideaId]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true, 'approved' => [$ideaId], 'trusted' => true], $this->json($response));
        self::assertSame('published', $this->moderationState($ideaId));
        self::assertSame('pending', $this->moderationState($later), 'other waiting ideas are not approved implicitly');
        self::assertNotFalse($this->trustedAt($this->defaultAccountId(), $authorId));
        self::assertNotNull($this->trustedAt($this->defaultAccountId(), $authorId));
        self::assertSame($moderatorId, (int) $this->conn->fetchOne('SELECT trusted_by_user_id FROM account_member_standing WHERE user_id = :u', ['u' => $authorId]));

        $audit = $this->readAuditLog();
        self::assertStringContainsString('member.trusted', $audit);
        self::assertStringContainsString('"reason":"manual"', $audit);
        self::assertStringContainsString('idea.approved', $audit);

        $next = $this->submit($app, 'ta-queue', $authorId, 'Second idea');
        self::assertSame(['ok', 'id'], array_keys($this->json($next)));
    }

    public function test_approve_and_trust_refuses_guest_ideas_with_422_and_changes_nothing(): void
    {
        [$boardId, , $moderatorId] = $this->seedBoard('ta-guest', 'anonymous');
        $guestId = (new UserRepository($this->conn))->findOrCreateGuestPlaceholder();
        $ideaId  = $this->seedIdea($boardId, $guestId, 'Guest idea', ['moderation_state' => 'pending']);

        $response = $this->app()->handle($this->send('POST', '/admin/boards/ta-guest/queue/approve-and-trust', $moderatorId, ['id' => $ideaId]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('author_not_trustable', $this->json($response)['error']['key']);
        self::assertSame('pending', $this->moderationState($ideaId));
        self::assertFalse($this->trustedAt($this->defaultAccountId(), $guestId));
    }

    public function test_approve_and_trust_refuses_the_deleted_user_placeholder(): void
    {
        [$boardId, , $moderatorId] = $this->seedBoard('ta-deleted');
        $placeholder = (new UserRepository($this->conn))->findOrCreateDeletedPlaceholder();
        $ideaId      = $this->seedIdea($boardId, $placeholder, 'Orphaned idea', ['moderation_state' => 'pending']);

        $response = $this->app()->handle($this->send('POST', '/admin/boards/ta-deleted/queue/approve-and-trust', $moderatorId, ['id' => $ideaId]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('pending', $this->moderationState($ideaId));
    }

    public function test_approve_and_trust_refuses_an_author_without_good_standing(): void
    {
        [$boardId, , $moderatorId, $authorId] = $this->seedBoard('ta-warned');
        $this->seedStanding($this->defaultAccountId(), $authorId, 'warned', 3);
        $ideaId = $this->seedIdea($boardId, $authorId, 'Warned author idea', ['moderation_state' => 'pending']);

        $response = $this->app()->handle($this->send('POST', '/admin/boards/ta-warned/queue/approve-and-trust', $moderatorId, ['id' => $ideaId]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('author_not_in_good_standing', $this->json($response)['error']['key']);
        self::assertSame('pending', $this->moderationState($ideaId));
        self::assertNull($this->trustedAt($this->defaultAccountId(), $authorId));
    }

    public function test_approve_and_trust_validates_input_and_unknown_ideas(): void
    {
        [$boardId, , $moderatorId, $authorId] = $this->seedBoard('ta-input');
        $published = $this->seedIdea($boardId, $authorId, 'Already published');
        $app       = $this->app();

        self::assertSame(422, $app->handle($this->send('POST', '/admin/boards/ta-input/queue/approve-and-trust', $moderatorId, ['id' => 'x']))->getStatusCode());
        self::assertSame(404, $app->handle($this->send('POST', '/admin/boards/ta-input/queue/approve-and-trust', $moderatorId, ['id' => $published]))->getStatusCode());
        self::assertSame(404, $app->handle($this->send('POST', '/admin/boards/ta-input/queue/approve-and-trust', $moderatorId, ['id' => 999999]))->getStatusCode());
        self::assertFalse($this->trustedAt($this->defaultAccountId(), $authorId));
    }

    public function test_approve_and_trust_requires_a_moderator(): void
    {
        [$boardId, , , $authorId] = $this->seedBoard('ta-authz');
        $ideaId = $this->seedIdea($boardId, $authorId, 'Pending', ['moderation_state' => 'pending']);
        $app    = $this->app();

        self::assertSame(403, $app->handle($this->send('POST', '/admin/boards/ta-authz/queue/approve-and-trust', $authorId, ['id' => $ideaId]))->getStatusCode());
        self::assertSame(401, $app->handle($this->send('POST', '/admin/boards/ta-authz/queue/approve-and-trust', null, ['id' => $ideaId]))->getStatusCode());
        self::assertSame('pending', $this->moderationState($ideaId));
        self::assertFalse($this->trustedAt($this->defaultAccountId(), $authorId));
    }

    public function test_approve_and_trust_cannot_reach_another_accounts_board_or_idea(): void
    {
        $foreignAccount = $this->insertAccount(['slug' => 'acct-ta-xt']);
        $foreignBoard   = $this->insertBoard('ta-xt-foreign', ['account_id' => $foreignAccount, 'premoderation' => 'all', 'is_default' => 0]);
        $authorId       = $this->insertUser('ta-xt-author@example.com');
        $foreignIdea    = $this->seedIdea($foreignBoard, $authorId, 'Foreign pending', ['moderation_state' => 'pending']);
        [, , $moderatorId] = $this->seedBoard('ta-xt-own');
        $app = $this->app();

        self::assertSame(404, $app->handle($this->send('POST', '/admin/boards/ta-xt-foreign/queue/approve-and-trust', $moderatorId, ['id' => $foreignIdea]))->getStatusCode());
        self::assertSame(404, $app->handle($this->send('POST', '/admin/boards/ta-xt-own/queue/approve-and-trust', $moderatorId, ['id' => $foreignIdea]))->getStatusCode());
        self::assertSame('pending', $this->moderationState($foreignIdea));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM account_member_standing WHERE trusted_at IS NOT NULL'));
    }

    // -------------------------------------------------------------------------
    // Trusted-authors list + revoke
    // -------------------------------------------------------------------------

    public function test_moderator_lists_only_this_accounts_trusted_authors(): void
    {
        [, , $moderatorId, $authorId] = $this->seedBoard('ta-list');
        $foreignOnly    = $this->insertUser('ta-list-foreign@example.com');
        $foreignAccount = $this->insertAccount(['slug' => 'acct-ta-list']);
        $untrusted      = $this->insertUser('ta-list-untrusted@example.com');
        $this->trust($this->defaultAccountId(), $authorId, $moderatorId);
        $this->trust($foreignAccount, $foreignOnly);
        $this->seedStanding($this->defaultAccountId(), $untrusted, 'flagged', 1);
        $this->conn->update('users', ['username' => 'trusty'], ['id' => $authorId]);

        $response = $this->app()->handle($this->send('GET', '/admin/trusted-authors', $moderatorId));

        self::assertSame(200, $response->getStatusCode());
        $authors = $this->json($response)['authors'];
        self::assertSame([$authorId], array_map(static fn (array $a): int => (int) $a['user_id'], $authors));
        self::assertSame('trusty', $authors[0]['username']);
        self::assertArrayHasKey('trusted_at', $authors[0]);
        self::assertArrayHasKey('public_id', $authors[0]);
        self::assertArrayNotHasKey('email', $authors[0]);
        self::assertArrayNotHasKey('email_hmac', $authors[0]);
    }

    public function test_revoke_clears_trust_and_the_next_idea_waits_again(): void
    {
        [, , $moderatorId, $authorId] = $this->seedBoard('ta-revoke');
        $this->trust($this->defaultAccountId(), $authorId, $moderatorId);
        $app = $this->app();

        $response = $app->handle($this->send('POST', '/admin/trusted-authors/' . $authorId . '/revoke', $moderatorId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], $this->json($response));
        self::assertNull($this->trustedAt($this->defaultAccountId(), $authorId));
        self::assertNull($this->conn->fetchOne('SELECT trusted_by_user_id FROM account_member_standing WHERE user_id = :u', ['u' => $authorId]));
        self::assertStringContainsString('member.trust_revoked', $this->readAuditLog());
        self::assertSame('pending', $this->json($this->submit($app, 'ta-revoke', $authorId, 'After revoke'))['moderation_state']);

        // Revoking again: nothing trusted any more → 404.
        self::assertSame(404, $app->handle($this->send('POST', '/admin/trusted-authors/' . $authorId . '/revoke', $moderatorId))->getStatusCode());
    }

    public function test_revoke_cannot_touch_another_accounts_trust(): void
    {
        [, , $moderatorId] = $this->seedBoard('ta-revoke-xt');
        $authorId       = $this->insertUser('ta-revoke-xt-stranger@example.com');
        $foreignAccount = $this->insertAccount(['slug' => 'acct-ta-revoke']);
        $this->trust($foreignAccount, $authorId);

        $response = $this->app()->handle($this->send('POST', '/admin/trusted-authors/' . $authorId . '/revoke', $moderatorId));

        self::assertSame(404, $response->getStatusCode());
        self::assertNotNull($this->trustedAt($foreignAccount, $authorId));
    }

    public function test_cloud_mode_moderator_of_account_a_cannot_list_or_revoke_in_account_b(): void
    {
        $accountA  = $this->insertAccount(['slug' => 'acct-ta-a']);
        $accountB  = $this->insertAccount(['slug' => 'acct-ta-b']);
        $modA      = $this->insertUser('ta-cloud-mod-a@example.com');
        $authorA   = $this->insertUser('ta-cloud-author-a@example.com');
        $authorB   = $this->insertUser('ta-cloud-author-b@example.com');
        $this->insertAccountMember($accountA, $modA, 'moderator');
        $this->trust($accountA, $authorA, $modA);
        $this->trust($accountB, $authorB);
        $app = $this->createApp(config: \Votepit\Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud'])));

        $own = $app->handle($this->send('GET', '/acct-ta-a/admin/trusted-authors', $modA));
        self::assertSame(200, $own->getStatusCode());
        self::assertSame([$authorA], array_map(static fn (array $a): int => (int) $a['user_id'], $this->json($own)['authors']));

        $foreignList = $app->handle($this->send('GET', '/acct-ta-b/admin/trusted-authors', $modA));
        self::assertContains($foreignList->getStatusCode(), [403, 404]);
        self::assertStringNotContainsString((string) $authorB, (string) $foreignList->getBody());

        $foreignRevoke = $app->handle($this->send('POST', '/acct-ta-b/admin/trusted-authors/' . $authorB . '/revoke', $modA));
        self::assertContains($foreignRevoke->getStatusCode(), [403, 404]);
        $crossPath = $app->handle($this->send('POST', '/acct-ta-a/admin/trusted-authors/' . $authorB . '/revoke', $modA));
        self::assertSame(404, $crossPath->getStatusCode());
        self::assertNotNull($this->trustedAt($accountB, $authorB));
    }

    public function test_list_and_revoke_require_a_moderator(): void
    {
        [, , , $authorId] = $this->seedBoard('ta-list-authz');
        $this->trust($this->defaultAccountId(), $authorId);
        $app = $this->app();

        self::assertSame(403, $app->handle($this->send('GET', '/admin/trusted-authors', $authorId))->getStatusCode());
        self::assertSame(401, $app->handle($this->send('GET', '/admin/trusted-authors', null))->getStatusCode());
        self::assertSame(403, $app->handle($this->send('POST', '/admin/trusted-authors/' . $authorId . '/revoke', $authorId))->getStatusCode());
        self::assertSame(401, $app->handle($this->send('POST', '/admin/trusted-authors/' . $authorId . '/revoke', null))->getStatusCode());
        self::assertNotNull($this->trustedAt($this->defaultAccountId(), $authorId));
    }

    // -------------------------------------------------------------------------
    // Standing transitions
    // -------------------------------------------------------------------------

    public function test_strike_to_warned_clears_trust_and_audits_it(): void
    {
        [$boardId, , $moderatorId, $authorId] = $this->seedBoard('ta-strike');
        $this->seedStanding($this->defaultAccountId(), $authorId, 'flagged', 2, [
            'trusted_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'trusted_by_user_id' => $moderatorId,
        ]);
        $ideaId = $this->seedIdea($boardId, $authorId, 'Will be removed');
        $app    = $this->app();

        $response = $app->handle($this->send('POST', '/ta-strike/ideas/' . $ideaId . '/moderate', $moderatorId, ['reason_code' => 'community_rule']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('warned', $this->conn->fetchOne('SELECT state FROM account_member_standing WHERE user_id = :u', ['u' => $authorId]));
        self::assertNull($this->trustedAt($this->defaultAccountId(), $authorId));
        $audit = $this->readAuditLog();
        self::assertStringContainsString('member.trust_revoked', $audit);
        self::assertStringContainsString('"reason":"standing_warned"', $audit);
    }

    public function test_strike_that_keeps_good_standing_keeps_trust(): void
    {
        [$boardId, , $moderatorId, $authorId] = $this->seedBoard('ta-strike-flag');
        $this->trust($this->defaultAccountId(), $authorId, $moderatorId);
        $ideaId = $this->seedIdea($boardId, $authorId, 'First removal');

        $this->app()->handle($this->send('POST', '/ta-strike-flag/ideas/' . $ideaId . '/moderate', $moderatorId, ['reason_code' => 'community_rule']));

        self::assertSame('flagged', $this->conn->fetchOne('SELECT state FROM account_member_standing WHERE user_id = :u', ['u' => $authorId]));
        self::assertNotNull($this->trustedAt($this->defaultAccountId(), $authorId));
        self::assertStringNotContainsString('member.trust_revoked', $this->readAuditLog());
    }

    public function test_comment_strike_to_suspended_clears_trust(): void
    {
        [$boardId, , $moderatorId, $authorId] = $this->seedBoard('ta-cstrike');
        $this->seedStanding($this->defaultAccountId(), $authorId, 'warned', 4);
        $this->conn->update('account_member_standing', ['state' => 'flagged', 'trusted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')], ['user_id' => $authorId]);
        $ideaId    = $this->seedIdea($boardId, $moderatorId, 'Host idea');
        $commentId = $this->seedComment($ideaId, $authorId);

        $response = $this->app()->handle($this->send('POST', '/ta-cstrike/ideas/' . $ideaId . '/comments/' . $commentId . '/delete', $moderatorId, ['reason_code' => 'community_rule']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('suspended', $this->conn->fetchOne('SELECT state FROM account_member_standing WHERE user_id = :u', ['u' => $authorId]));
        self::assertNull($this->trustedAt($this->defaultAccountId(), $authorId));
        self::assertStringContainsString('"reason":"standing_suspended"', $this->readAuditLog());
    }

    public function test_manual_standing_change_to_suspended_clears_trust_and_normal_does_not_restore_it(): void
    {
        [, , $moderatorId, $authorId] = $this->seedBoard('ta-manual');
        $this->insertAccountMember($this->defaultAccountId(), $authorId, 'admin');
        $this->trust($this->defaultAccountId(), $authorId, $moderatorId);
        $app = $this->app();

        $suspend = $app->handle($this->send('PATCH', '/admin/members/' . $authorId . '/standing', $moderatorId, ['state' => 'suspended']));
        self::assertSame(200, $suspend->getStatusCode());
        self::assertNull($this->trustedAt($this->defaultAccountId(), $authorId));
        self::assertStringContainsString('"reason":"standing_suspended"', $this->readAuditLog());

        $normal = $app->handle($this->send('PATCH', '/admin/members/' . $authorId . '/standing', $moderatorId, ['state' => 'normal']));
        self::assertSame(200, $normal->getStatusCode());
        self::assertNull($this->trustedAt($this->defaultAccountId(), $authorId));
    }

    public function test_manual_standing_change_to_flagged_keeps_trust(): void
    {
        [, , $moderatorId, $authorId] = $this->seedBoard('ta-manual-flag');
        $this->insertAccountMember($this->defaultAccountId(), $authorId, 'admin');
        $this->trust($this->defaultAccountId(), $authorId, $moderatorId);

        $this->app()->handle($this->send('PATCH', '/admin/members/' . $authorId . '/standing', $moderatorId, ['state' => 'flagged']));

        self::assertNotNull($this->trustedAt($this->defaultAccountId(), $authorId));
        self::assertStringNotContainsString('member.trust_revoked', $this->readAuditLog());
    }

    // -------------------------------------------------------------------------
    // User erasure
    // -------------------------------------------------------------------------

    public function test_erasing_the_granting_moderator_keeps_the_trust_without_an_actor(): void
    {
        [, , $moderatorId, $authorId] = $this->seedBoard('ta-erase-mod');
        $this->trust($this->defaultAccountId(), $authorId, $moderatorId);
        $placeholder = (new UserRepository($this->conn))->findOrCreateDeletedPlaceholder();

        (new UserErasureRepository($this->conn))->erase($moderatorId, $placeholder);

        $row = $this->conn->fetchAssociative('SELECT trusted_at, trusted_by_user_id FROM account_member_standing WHERE user_id = :u', ['u' => $authorId]);
        self::assertIsArray($row);
        self::assertNotNull($row['trusted_at']);
        self::assertNull($row['trusted_by_user_id']);
    }

    public function test_erasing_a_trusted_author_removes_the_trust(): void
    {
        [, , $moderatorId, $authorId] = $this->seedBoard('ta-erase-author');
        $this->trust($this->defaultAccountId(), $authorId, $moderatorId);
        $placeholder = (new UserRepository($this->conn))->findOrCreateDeletedPlaceholder();

        (new UserErasureRepository($this->conn))->erase($authorId, $placeholder);

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM account_member_standing WHERE trusted_at IS NOT NULL'));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM account_member_standing WHERE user_id = :p', ['p' => $placeholder]));
    }
}
