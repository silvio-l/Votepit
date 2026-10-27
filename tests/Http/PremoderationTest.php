<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Security\TokenVault;
use Votepit\Security\Webhook\WebhookSenderInterface;
use Votepit\Security\Webhook\WebhookSendResult;
use Votepit\Security\Webhook\WebhookTarget;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Pre-moderation (broaden-use-cases issue 05, migration 0083): optional
 * per-board approval queue.
 *
 * The central invariant — a pending idea is invisible on EVERY read path
 * except to its author and the account's moderators — is covered here
 * through the HTTP seam, one test per path of the issue's matrix that
 * exists today (board list + filters, stats/counters, detail, duplicate
 * suggestions, roadmap, discover/spotlight, public profile, REST + MCP,
 * webhooks, notification fan-out via vote/comment, admin board counts,
 * account export), plus the approve/reject flows, settings and the
 * cross-tenant guard. The repository default scope itself is covered by
 * Persistence\IdeaRepositoryPremoderationScopeTest.
 */
final class PremoderationTest extends IntegrationTestCase
{
    private ?object $sender = null;

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $path, ?int $userId, array $body = []): ServerRequestInterface
    {
        $csrf    = $this->csrf();
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

    private function validTimeTrap(): string
    {
        $ts  = (string) (time() - 5);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, str_repeat('a', 64), true)), '+/', '-_'), '=');

        return $ts . '.' . $mac;
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    /** @return App<null> */
    private function app(?InMemoryMailer $mailer = null): App
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
            mailer: $mailer,
            webhookSender: $this->sender,
            webhookDeferRunner: static fn (\Closure $work): mixed => $work(),
        );
    }

    /** @return list<string> event names delivered so far */
    private function webhookEvents(): array
    {
        $sender = $this->sender;
        self::assertNotNull($sender);
        /** @var list<array<string, mixed>> $events */
        $events = $sender->events ?? [];

        return array_map(static fn (array $e): string => (string) ($e['event'] ?? ''), $events);
    }

    /**
     * Board with premoderation=all, an owner, a moderator and a plain
     * member; returns [boardId, ownerId, moderatorId, memberId].
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function seedPremoderatedBoard(string $slug, string $mode = 'all'): array
    {
        $boardId     = $this->insertBoard($slug, ['premoderation' => $mode]);
        $ownerId     = $this->insertUser($slug . '-owner@example.com');
        $moderatorId = $this->insertUser($slug . '-mod@example.com');
        $memberId    = $this->insertUser($slug . '-member@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $this->insertAccountMember($this->defaultAccountId(), $moderatorId, 'moderator');

        return [$boardId, $ownerId, $moderatorId, $memberId];
    }

    /**
     * @param App<null>            $app
     * @param array<string, mixed> $extra
     */
    private function submit(App $app, string $slug, int $userId, string $title, array $extra = []): ResponseInterface
    {
        return $app->handle($this->send('POST', '/' . $slug . '/ideas', $userId, array_merge([
            'title'    => $title,
            'body'     => 'A description that is long enough to pass validation.',
            '_form_at' => $this->validTimeTrap(),
        ], $extra)));
    }

    /** @param array<string, mixed> $overrides */
    private function pendingIdea(int $boardId, int $authorId, string $title, array $overrides = []): int
    {
        return $this->seedIdea($boardId, $authorId, $title, array_merge(['moderation_state' => 'pending'], $overrides));
    }

    /** @param App<null> $app */
    private function configureWebhook(App $app, string $slug, int $ownerId): void
    {
        $response = $app->handle($this->send('PUT', '/admin/boards/' . $slug . '/webhook', $ownerId, ['url' => 'https://93.184.216.34/hook']));
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param App<null> $app
     * @return list<string> titles of the board list
     */
    private function boardListTitles(App $app, string $path, ?int $userId = null): array
    {
        $response = $app->handle($this->send('GET', $path, $userId));
        self::assertSame(200, $response->getStatusCode());

        return array_values(array_map(static fn (array $i): string => (string) $i['title'], $this->json($response)['ideas']));
    }

    // -------------------------------------------------------------------------
    // Default (off): behaviour and payloads unchanged
    // -------------------------------------------------------------------------

    public function test_off_board_publishes_immediately_with_unchanged_response_shape(): void
    {
        $this->insertBoard('pm-off');
        $memberId = $this->insertUser('pm-off-member@example.com');
        $app      = $this->app();

        $response = $this->submit($app, 'pm-off', $memberId, 'Published right away');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['ok', 'id'], array_keys($this->json($response)));
        self::assertSame('published', $this->conn->fetchOne('SELECT moderation_state FROM ideas'));
        self::assertSame(['Published right away'], $this->boardListTitles($app, '/pm-off'));

        $detail = $this->json($app->handle($this->send('GET', '/pm-off/ideas/' . (int) $this->json($response)['id'], null)));
        self::assertArrayNotHasKey('moderation_state', $detail['idea']);
    }

    // -------------------------------------------------------------------------
    // all: submission
    // -------------------------------------------------------------------------

    public function test_member_idea_on_premoderated_board_is_pending_and_fires_no_side_effects(): void
    {
        [, $ownerId, , $memberId] = $this->seedPremoderatedBoard('pm-submit');
        $app = $this->app();
        $this->configureWebhook($app, 'pm-submit', $ownerId);

        $response = $this->submit($app, 'pm-submit', $memberId, 'Waiting for approval');

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame('pending', $data['moderation_state']);
        self::assertSame('pending', $this->conn->fetchOne('SELECT moderation_state FROM ideas WHERE id = :id', ['id' => $data['id']]));
        self::assertSame([], $this->webhookEvents(), 'idea.created must fire at publish time, not at submission');
    }

    public function test_moderator_owner_and_admin_ideas_bypass_the_queue(): void
    {
        [, $ownerId, $moderatorId] = $this->seedPremoderatedBoard('pm-bypass');
        $adminId = $this->insertUser('pm-bypass-admin@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'admin');
        $app = $this->app();
        $this->configureWebhook($app, 'pm-bypass', $ownerId);

        foreach ([$ownerId => 'Owner idea', $moderatorId => 'Moderator idea', $adminId => 'Admin idea'] as $userId => $title) {
            $response = $this->submit($app, 'pm-bypass', $userId, $title);
            self::assertSame(201, $response->getStatusCode());
            self::assertSame(['ok', 'id'], array_keys($this->json($response)));
        }

        self::assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM ideas WHERE moderation_state = 'pending'"));
        self::assertSame(['idea.created', 'idea.created', 'idea.created'], $this->webhookEvents());
    }

    public function test_unknown_stored_mode_fails_secure_to_approval_required(): void
    {
        [, , , $memberId] = $this->seedPremoderatedBoard('pm-unknown', 'something-new');

        $response = $this->submit($this->app(), 'pm-unknown', $memberId, 'Unknown mode idea');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('pending', $this->json($response)['moderation_state']);
    }

    public function test_honeypot_still_runs_before_queueing(): void
    {
        [, , , $memberId] = $this->seedPremoderatedBoard('pm-honeypot');

        $response = $this->submit($this->app(), 'pm-honeypot', $memberId, 'Bot idea', ['website' => 'spam']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas'));
    }

    public function test_moderators_get_one_aggregated_in_app_notification_and_no_mail(): void
    {
        [, $ownerId, $moderatorId, $memberId] = $this->seedPremoderatedBoard('pm-notify');
        $mailer = new InMemoryMailer();
        $app    = $this->app($mailer);

        $this->submit($app, 'pm-notify', $memberId, 'First pending idea');
        $this->submit($app, 'pm-notify', $memberId, 'Second pending idea');

        foreach ([$ownerId, $moderatorId] as $recipient) {
            self::assertSame(1, (int) $this->conn->fetchOne(
                "SELECT COUNT(*) FROM notifications WHERE type = 'ideas_pending_approval' AND scope = 'user' AND user_id = :u",
                ['u' => $recipient],
            ));
        }
        self::assertSame(0, (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM notifications WHERE type = 'ideas_pending_approval' AND user_id = :u",
            ['u' => $memberId],
        ));
        // The notice never carries the pending idea's content.
        self::assertSame(0, (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM notifications WHERE type = 'ideas_pending_approval' AND (title LIKE '%pending idea%' OR body LIKE '%pending idea%')",
        ));
        self::assertSame(0, $mailer->count());

        // Once read, the next pending idea produces a fresh notice.
        $noticeId = (int) $this->conn->fetchOne("SELECT id FROM notifications WHERE type = 'ideas_pending_approval' AND user_id = :u", ['u' => $ownerId]);
        $this->conn->insert('notification_reads', ['notification_id' => $noticeId, 'user_id' => $ownerId]);
        $this->submit($app, 'pm-notify', $memberId, 'Third pending idea');
        self::assertSame(2, (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM notifications WHERE type = 'ideas_pending_approval' AND user_id = :u",
            ['u' => $ownerId],
        ));
    }

    // -------------------------------------------------------------------------
    // The invariant: pending ideas are invisible on every read path
    // -------------------------------------------------------------------------

    public function test_board_list_and_filters_exclude_pending_ideas(): void
    {
        [$boardId, , , $memberId] = $this->seedPremoderatedBoard('pm-list');
        $this->seedIdea($boardId, $memberId, 'Visible idea');
        $this->pendingIdea($boardId, $memberId, 'Hidden idea');
        $this->pendingIdea($boardId, $memberId, 'Hidden planned idea', ['status' => 'planned', 'score_cache' => 99]);
        $app = $this->app();

        self::assertSame(['Visible idea'], $this->boardListTitles($app, '/pm-list'));
        self::assertSame(['Visible idea'], $this->boardListTitles($app, '/pm-list?sort=top'));
        self::assertSame([], $this->boardListTitles($app, '/pm-list?status=planned'));
        // The author does not see it in the list either (only on its own detail page).
        self::assertSame(['Visible idea'], $this->boardListTitles($app, '/pm-list', $memberId));
    }

    public function test_free_text_search_and_its_page_count_exclude_pending_ideas(): void
    {
        [$boardId, , , $memberId] = $this->seedPremoderatedBoard('pm-search');
        $this->seedIdea($boardId, $memberId, 'Dark mode visible');
        $this->pendingIdea($boardId, $memberId, 'Dark mode hidden');
        $this->pendingIdea($boardId, $memberId, 'Only pending dark theme');
        $app = $this->app();

        self::assertSame(['Dark mode visible'], $this->boardListTitles($app, '/pm-search?q=dark'));
        self::assertSame(['Dark mode visible'], $this->boardListTitles($app, '/pm-search?q=dark', $memberId));
        self::assertSame([], $this->boardListTitles($app, '/pm-search?q=theme'));

        // A pending-only match paginates exactly like a search with no match at all.
        $pendingOnly = $this->json($app->handle($this->send('GET', '/pm-search?q=theme', null)));
        $noMatch     = $this->json($app->handle($this->send('GET', '/pm-search?q=zzzunmatched', null)));
        self::assertSame($noMatch['total_pages'], $pendingOnly['total_pages']);
    }

    public function test_board_stats_and_counters_ignore_pending_ideas(): void
    {
        [$boardId, , , $memberId] = $this->seedPremoderatedBoard('pm-stats');
        $this->seedIdea($boardId, $memberId, 'Visible idea');
        $this->pendingIdea($boardId, $memberId, 'Hidden idea');

        $stats = $this->json($this->app()->handle($this->send('GET', '/pm-stats', null)))['stats'];

        self::assertSame(1, $stats['total_ideas']);
        self::assertSame(1, $stats['weekly_new_ideas']);
    }

    public function test_detail_of_pending_idea_is_404_for_strangers_with_the_same_body_as_a_missing_id(): void
    {
        [$boardId, , , $memberId] = $this->seedPremoderatedBoard('pm-detail');
        $otherMember = $this->insertUser('pm-detail-other@example.com');
        $ideaId      = $this->pendingIdea($boardId, $memberId, 'Hidden idea');
        $app         = $this->app();

        $missing = $app->handle($this->send('GET', '/pm-detail/ideas/999999', null));
        self::assertSame(404, $missing->getStatusCode());

        foreach ([null, $otherMember] as $viewer) {
            $response = $app->handle($this->send('GET', '/pm-detail/ideas/' . $ideaId, $viewer));
            self::assertSame(404, $response->getStatusCode());
            self::assertSame((string) $missing->getBody(), (string) $response->getBody());
        }

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT view_count FROM ideas WHERE id = :id', ['id' => $ideaId]));
    }

    public function test_detail_of_pending_idea_is_visible_to_author_and_moderators(): void
    {
        [$boardId, $ownerId, $moderatorId, $memberId] = $this->seedPremoderatedBoard('pm-detail-ok');
        $ideaId = $this->pendingIdea($boardId, $memberId, 'Hidden idea');
        $app    = $this->app();

        foreach ([$memberId, $moderatorId, $ownerId] as $viewer) {
            $response = $app->handle($this->send('GET', '/pm-detail-ok/ideas/' . $ideaId, $viewer));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('pending', $this->json($response)['idea']['moderation_state']);
        }
    }

    public function test_duplicate_suggestions_exclude_pending_ideas(): void
    {
        [$boardId, , , $memberId] = $this->seedPremoderatedBoard('pm-dups');
        $this->pendingIdea($boardId, $memberId, 'Dark mode for the dashboard');

        $response = $this->app()->handle($this->send('GET', '/pm-dups/ideas/search-duplicates?title=' . rawurlencode('Dark mode for the dashboard'), $memberId));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('Dark mode', (string) $response->getBody());
    }

    public function test_roadmap_excludes_pending_ideas(): void
    {
        [$boardId, , , $memberId] = $this->seedPremoderatedBoard('pm-roadmap');
        $this->seedIdea($boardId, $memberId, 'Visible planned', ['status' => 'planned']);
        $this->pendingIdea($boardId, $memberId, 'Hidden planned', ['status' => 'planned']);

        $response = $this->app()->handle($this->send('GET', '/pm-roadmap/roadmap', null));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Visible planned', $body);
        self::assertStringNotContainsString('Hidden planned', $body);
    }

    public function test_discover_and_spotlight_count_only_published_ideas(): void
    {
        [$boardId, , , $memberId] = $this->seedPremoderatedBoard('pm-discover');
        $this->seedIdea($boardId, $memberId, 'Visible idea');
        $this->pendingIdea($boardId, $memberId, 'Hidden idea');
        // A board whose only idea is pending must not qualify for the spotlight.
        $onlyPending = $this->insertBoard('pm-only-pending', ['premoderation' => 'all', 'is_default' => 0]);
        $this->pendingIdea($onlyPending, $memberId, 'Hidden only idea');

        $data = $this->json($this->app()->handle($this->send('GET', '/discover', null)));

        $bySlug = [];
        foreach ($data['boards'] as $board) {
            $bySlug[$board['slug']] = $board['idea_count'];
        }
        self::assertSame(1, $bySlug['pm-discover']);
        self::assertSame(0, $bySlug['pm-only-pending']);
        self::assertNotContains('pm-only-pending', array_column($data['spotlight'], 'slug'));
    }

    public function test_public_profile_counts_only_published_ideas(): void
    {
        [$boardId, , , $memberId] = $this->seedPremoderatedBoard('pm-profile');
        $this->conn->update('users', ['profile_visible' => 1], ['id' => $memberId]);
        $this->seedIdea($boardId, $memberId, 'Visible idea');
        $this->pendingIdea($boardId, $memberId, 'Hidden idea');

        $data = $this->json($this->app()->handle($this->send('GET', '/members/' . $memberId . '/profile', null)));

        self::assertSame(1, $data['ideas_submitted']);
    }

    public function test_rest_api_list_and_get_exclude_pending_ideas(): void
    {
        [$boardId, $ownerId, , $memberId] = $this->seedPremoderatedBoard('pm-api');
        $this->seedIdea($boardId, $memberId, 'Visible idea');
        $pendingId = $this->pendingIdea($boardId, $memberId, 'Hidden idea');
        $pair      = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $ownerId, $pair['hash']);
        $app = $this->app();

        $list = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/ideas')->withHeader('Authorization', 'Bearer ' . $pair['token']));
        self::assertSame(['Visible idea'], array_column($this->json($list)['ideas'], 'title'));

        $get = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/ideas/' . $pendingId)->withHeader('Authorization', 'Bearer ' . $pair['token']));
        self::assertSame(404, $get->getStatusCode());
    }

    public function test_mcp_list_and_get_exclude_pending_ideas(): void
    {
        [$boardId, $ownerId, , $memberId] = $this->seedPremoderatedBoard('pm-mcp');
        $this->seedIdea($boardId, $memberId, 'Visible idea');
        $pendingId = $this->pendingIdea($boardId, $memberId, 'Hidden idea');
        $pair      = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $ownerId, $pair['hash']);
        $app = $this->app();

        $call = function (array $params) use ($app, $pair): array {
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/mcp')
                ->withHeader('Authorization', 'Bearer ' . $pair['token'])
                ->withHeader('Content-Type', 'application/json');
            $request->getBody()->write((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params]));
            $request->getBody()->rewind();

            return $this->json($app->handle($request))['result'];
        };

        $list = $call(['name' => 'list_ideas']);
        self::assertSame(['Visible idea'], array_column(json_decode((string) $list['content'][0]['text'], true)['ideas'], 'title'));

        $get = $call(['name' => 'get_idea', 'arguments' => ['id' => $pendingId]]);
        self::assertTrue($get['isError']);
    }

    public function test_api_created_ideas_with_write_grant_wait_for_approval_like_a_member_idea(): void
    {
        [$boardId, $ownerId] = $this->seedPremoderatedBoard('pm-api-create');
        $pair = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $ownerId, $pair['hash'], scope: 'write');
        $app = $this->app();
        $this->configureWebhook($app, 'pm-api-create', $ownerId);

        $response = $app->handle($this->apiCreate($pair['token']));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('pending', $this->json($response)['moderation_state']);
        self::assertSame('pending', $this->conn->fetchOne('SELECT moderation_state FROM ideas'));
        self::assertSame([], $this->webhookEvents(), 'a pending idea fires no publish webhook');
        self::assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM notifications WHERE type = 'ideas_pending_approval'"));
    }

    public function test_api_created_ideas_with_moderate_grant_publish_immediately(): void
    {
        [$boardId, $ownerId] = $this->seedPremoderatedBoard('pm-api-create-mod');
        $pair = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $ownerId, $pair['hash'], scope: 'moderate');
        $app = $this->app();
        $this->configureWebhook($app, 'pm-api-create-mod', $ownerId);

        $response = $app->handle($this->apiCreate($pair['token']));

        self::assertSame(201, $response->getStatusCode());
        self::assertArrayNotHasKey('moderation_state', $this->json($response));
        self::assertSame('published', $this->conn->fetchOne('SELECT moderation_state FROM ideas'));
        self::assertSame(['idea.created'], $this->webhookEvents());
    }

    public function test_moderate_grant_reads_pending_ideas_write_grant_does_not(): void
    {
        [$boardId, $ownerId, , $memberId] = $this->seedPremoderatedBoard('pm-api-queue');
        $pendingId = $this->pendingIdea($boardId, $memberId, 'Hidden idea');
        $writer    = (new TokenVault())->generate();
        $moderator = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $ownerId, $writer['hash'], 'w', scope: 'write');
        $this->insertApiToken($this->defaultAccountId(), $boardId, $ownerId, $moderator['hash'], 'm', scope: 'moderate');
        $app = $this->app();
        $get = static fn (string $path, string $token): ServerRequestInterface => (new ServerRequestFactory())
            ->createServerRequest('GET', $path)->withHeader('Authorization', 'Bearer ' . $token);

        self::assertSame(403, $app->handle($get('/api/v1/ideas?moderation_state=pending', $writer['token']))->getStatusCode());
        self::assertSame(404, $app->handle($get('/api/v1/ideas/' . $pendingId, $writer['token']))->getStatusCode());

        $queue = $this->json($app->handle($get('/api/v1/ideas?moderation_state=pending', $moderator['token'])));
        self::assertSame(['Hidden idea'], array_column($queue['ideas'], 'title'));
        $detail = $this->json($app->handle($get('/api/v1/ideas/' . $pendingId, $moderator['token'])));
        self::assertSame('pending', $detail['idea']['moderation_state']);
    }

    private function apiCreate(string $token): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/ideas')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['title' => 'Agent idea', 'body' => 'Submitted by an account-issued agent token.']);
    }

    public function test_votes_and_comments_on_pending_ideas_are_404_so_no_notification_fan_out(): void
    {
        [$boardId, , , $memberId] = $this->seedPremoderatedBoard('pm-interact');
        $voterId = $this->insertUser('pm-interact-voter@example.com');
        $ideaId  = $this->pendingIdea($boardId, $memberId, 'Hidden idea');
        $app     = $this->app();

        $vote = $app->handle($this->send('POST', '/pm-interact/ideas/' . $ideaId . '/vote', $voterId, ['value' => '1']));
        self::assertSame(404, $vote->getStatusCode());

        $comment = $app->handle($this->send('POST', '/pm-interact/ideas/' . $ideaId . '/comments', $voterId, ['body' => 'A comment on a hidden idea.']));
        self::assertSame(404, $comment->getStatusCode());

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM comments'));
        self::assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM notifications WHERE type <> 'ideas_pending_approval'"));
    }

    public function test_admin_board_list_counts_only_published_ideas(): void
    {
        [$boardId, $ownerId, , $memberId] = $this->seedPremoderatedBoard('pm-admin-list');
        $this->seedIdea($boardId, $memberId, 'Visible idea');
        $this->pendingIdea($boardId, $memberId, 'Hidden idea');

        $data = $this->json($this->app()->handle($this->send('GET', '/admin/boards', $ownerId)));

        $board = array_values(array_filter($data['boards'], static fn (array $b): bool => $b['slug'] === 'pm-admin-list'))[0];
        self::assertSame(1, $board['idea_count']);
    }

    public function test_account_export_marks_pending_ideas_for_the_owner(): void
    {
        [$boardId, $ownerId, , $memberId] = $this->seedPremoderatedBoard('pm-export');
        $this->pendingIdea($boardId, $memberId, 'Hidden idea');

        $response = $this->app()->handle($this->send('GET', '/admin/export', $ownerId));

        self::assertSame(200, $response->getStatusCode());
        $ideas = $this->json($response)['ideas'];
        self::assertSame('pending', $ideas[0]['moderation_state']);
    }

    // -------------------------------------------------------------------------
    // Approval queue
    // -------------------------------------------------------------------------

    public function test_queue_lists_only_pending_ideas_for_moderators(): void
    {
        [$boardId, , $moderatorId, $memberId] = $this->seedPremoderatedBoard('pm-queue');
        $this->seedIdea($boardId, $memberId, 'Visible idea');
        $pendingId = $this->pendingIdea($boardId, $memberId, 'Hidden idea');
        $app       = $this->app();

        $response = $app->handle($this->send('GET', '/admin/boards/pm-queue/queue', $moderatorId));

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame(1, $data['count']);
        self::assertSame([$pendingId], array_map(static fn (array $i): int => (int) $i['id'], $data['ideas']));
        self::assertSame('all', $data['board']['premoderation']);

        self::assertSame(403, $app->handle($this->send('GET', '/admin/boards/pm-queue/queue', $memberId))->getStatusCode());
        self::assertSame(401, $app->handle($this->send('GET', '/admin/boards/pm-queue/queue', null))->getStatusCode());
    }

    public function test_approve_publishes_and_fires_side_effects_exactly_once(): void
    {
        [$boardId, $ownerId, $moderatorId, $memberId] = $this->seedPremoderatedBoard('pm-approve');
        $app = $this->app();
        $this->configureWebhook($app, 'pm-approve', $ownerId);
        $ideaId = (int) $this->json($this->submit($app, 'pm-approve', $memberId, 'Approve me'))['id'];

        self::assertSame(0, $this->json($app->handle($this->send('GET', '/pm-approve', null)))['stats']['total_ideas']);

        $response = $app->handle($this->send('POST', '/admin/boards/pm-approve/queue/approve', $moderatorId, ['ids' => [$ideaId]]));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$ideaId], $this->json($response)['approved']);

        // Approving again is a no-op: nothing fires twice.
        $again = $app->handle($this->send('POST', '/admin/boards/pm-approve/queue/approve', $moderatorId, ['ids' => [$ideaId]]));
        self::assertSame([], $this->json($again)['approved']);

        $row = $this->conn->fetchAssociative('SELECT moderation_state, approved_by, approved_at FROM ideas WHERE id = :id', ['id' => $ideaId]);
        self::assertIsArray($row);
        self::assertSame('published', $row['moderation_state']);
        self::assertSame($moderatorId, (int) $row['approved_by']);
        self::assertNotNull($row['approved_at']);

        self::assertSame(['Approve me'], $this->boardListTitles($app, '/pm-approve'));
        self::assertSame(1, $this->json($app->handle($this->send('GET', '/pm-approve', null)))['stats']['total_ideas']);
        self::assertSame(['idea.created'], $this->webhookEvents());
        self::assertSame(1, (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM notifications WHERE type = 'idea_approved' AND user_id = :u AND link_path = :l",
            ['u' => $memberId, 'l' => '/pm-approve/idea/' . $ideaId],
        ));
        self::assertStringContainsString('idea.approved', $this->readAuditLog());
    }

    public function test_bulk_approve_handles_several_ideas_and_ignores_foreign_ids(): void
    {
        [$boardId, , $moderatorId, $memberId] = $this->seedPremoderatedBoard('pm-bulk');
        $otherBoard = $this->insertBoard('pm-bulk-other', ['premoderation' => 'all', 'is_default' => 0]);
        $a          = $this->pendingIdea($boardId, $memberId, 'Pending A');
        $b          = $this->pendingIdea($boardId, $memberId, 'Pending B');
        $foreign    = $this->pendingIdea($otherBoard, $memberId, 'Other board pending');

        $response = $this->app()->handle($this->send('POST', '/admin/boards/pm-bulk/queue/approve', $moderatorId, ['ids' => [$a, $b, $foreign]]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$a, $b], $this->json($response)['approved']);
        self::assertSame('pending', $this->conn->fetchOne('SELECT moderation_state FROM ideas WHERE id = :id', ['id' => $foreign]));
    }

    public function test_approve_rejects_invalid_input_and_non_moderators(): void
    {
        [$boardId, , $moderatorId, $memberId] = $this->seedPremoderatedBoard('pm-approve-guard');
        $ideaId = $this->pendingIdea($boardId, $memberId, 'Pending');
        $app    = $this->app();

        self::assertSame(422, $app->handle($this->send('POST', '/admin/boards/pm-approve-guard/queue/approve', $moderatorId, ['ids' => []]))->getStatusCode());
        self::assertSame(422, $app->handle($this->send('POST', '/admin/boards/pm-approve-guard/queue/approve', $moderatorId, ['ids' => 'nope']))->getStatusCode());
        self::assertSame(403, $app->handle($this->send('POST', '/admin/boards/pm-approve-guard/queue/approve', $memberId, ['ids' => [$ideaId]]))->getStatusCode());
        self::assertSame('pending', $this->conn->fetchOne('SELECT moderation_state FROM ideas WHERE id = :id', ['id' => $ideaId]));
    }

    public function test_reject_soft_deletes_notifies_author_and_never_publishes(): void
    {
        [$boardId, $ownerId, $moderatorId, $memberId] = $this->seedPremoderatedBoard('pm-reject');
        $this->conn->update('users', ['notification_email' => 'author@example.com'], ['id' => $memberId]);
        $mailer = new InMemoryMailer();
        $app    = $this->app($mailer);
        $this->configureWebhook($app, 'pm-reject', $ownerId);
        $ideaId = $this->pendingIdea($boardId, $memberId, 'Reject me');

        $response = $app->handle($this->send('POST', '/pm-reject/ideas/' . $ideaId . '/moderate', $moderatorId, ['reason_code' => 'community_rule']));
        self::assertSame(200, $response->getStatusCode());

        self::assertNotNull($this->conn->fetchOne('SELECT deleted_at FROM ideas WHERE id = :id', ['id' => $ideaId]));
        self::assertSame(1, $mailer->count());

        $approve = $app->handle($this->send('POST', '/admin/boards/pm-reject/queue/approve', $moderatorId, ['ids' => [$ideaId]]));
        self::assertSame([], $this->json($approve)['approved']);
        self::assertSame([], $this->boardListTitles($app, '/pm-reject'));
        self::assertSame(0, $this->json($app->handle($this->send('GET', '/admin/boards/pm-reject/queue', $moderatorId)))['count']);
        self::assertSame([], $this->webhookEvents());
        self::assertSame(404, $app->handle($this->send('GET', '/pm-reject/ideas/' . $ideaId, $memberId))->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Cross-tenant
    // -------------------------------------------------------------------------

    public function test_moderator_of_another_account_cannot_see_or_approve_pending_ideas(): void
    {
        $foreignAccount = $this->insertAccount(['slug' => 'acct-pm-foreign']);
        $foreignBoard   = $this->insertBoard('pm-foreign', ['account_id' => $foreignAccount, 'premoderation' => 'all', 'is_default' => 0]);
        $author         = $this->insertUser('pm-foreign-author@example.com');
        $ideaId         = $this->pendingIdea($foreignBoard, $author, 'Foreign pending idea');

        $moderatorId = $this->insertUser('pm-default-mod@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $moderatorId, 'moderator');
        $app = $this->app();

        self::assertSame(404, $app->handle($this->send('GET', '/admin/boards/pm-foreign/queue', $moderatorId))->getStatusCode());
        self::assertSame(404, $app->handle($this->send('POST', '/admin/boards/pm-foreign/queue/approve', $moderatorId, ['ids' => [$ideaId]]))->getStatusCode());
        self::assertSame(404, $app->handle($this->send('GET', '/pm-foreign/ideas/' . $ideaId, $moderatorId))->getStatusCode());

        // Same slug in the moderator's own account: the foreign id stays untouchable.
        $this->insertBoard('pm-own', ['premoderation' => 'all', 'is_default' => 0]);
        $response = $app->handle($this->send('POST', '/admin/boards/pm-own/queue/approve', $moderatorId, ['ids' => [$ideaId]]));
        self::assertSame([], $this->json($response)['approved']);
        self::assertSame('pending', $this->conn->fetchOne('SELECT moderation_state FROM ideas WHERE id = :id', ['id' => $ideaId]));
    }

    // -------------------------------------------------------------------------
    // Board settings
    // -------------------------------------------------------------------------

    public function test_owner_switches_premoderation_and_sees_pending_count(): void
    {
        $boardId = $this->insertBoard('pm-settings');
        $ownerId = $this->insertUser('pm-settings-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $app = $this->app();

        $before = $this->json($app->handle($this->send('GET', '/admin/boards/pm-settings/moderation', $ownerId)));
        self::assertSame('off', $before['premoderation']);
        self::assertSame(0, $before['pending_count']);

        $response = $app->handle($this->send('POST', '/admin/boards/pm-settings/moderation', $ownerId, ['action' => 'premoderation', 'premoderation' => 'all']));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('all', $this->conn->fetchOne('SELECT premoderation FROM boards WHERE id = :id', ['id' => $boardId]));
        self::assertStringContainsString('board.premoderation_changed', $this->readAuditLog());

        $this->pendingIdea($boardId, $ownerId, 'Pending');
        self::assertSame(1, $this->json($app->handle($this->send('GET', '/admin/boards/pm-settings/moderation', $ownerId)))['pending_count']);

        $invalid = $app->handle($this->send('POST', '/admin/boards/pm-settings/moderation', $ownerId, ['action' => 'premoderation', 'premoderation' => 'anonymous-later']));
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame('all', $this->conn->fetchOne('SELECT premoderation FROM boards WHERE id = :id', ['id' => $boardId]));
    }
}
