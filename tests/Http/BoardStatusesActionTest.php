<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Domain\IdeaSearchQuery;
use Votepit\Persistence\IdeaRepository;
use Votepit\Security\CsrfService;
use Votepit\Security\SessionService;
use Votepit\Security\TokenVault;
use Votepit\Security\Webhook\WebhookSenderInterface;
use Votepit\Security\Webhook\WebhookSendResult;
use Votepit\Security\Webhook\WebhookTarget;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Status categories + owner-defined statuses (broaden-use-cases issue 03,
 * migrations/0073_create_board_statuses.sql) through the real HTTP seam:
 * admin CRUD + AuthZ + cross-tenant isolation, and every read/write path
 * that hangs on the category (list/filter, roadmap, transitions, votes,
 * comments, API, MCP-facing list, webhook, notifications, profile, export).
 */
final class BoardStatusesActionTest extends IntegrationTestCase
{
    private int $seq = 0;

    // ── Defaults unchanged ────────────────────────────────────────────────

    public function test_default_board_keeps_status_and_gains_only_additive_fields(): void
    {
        $boardId = $this->insertBoard('demo');
        $author  = $this->insertUser('author@example.com');
        $this->seedIdea($boardId, $author, 'Planned idea', ['status' => 'planned']);

        $data = $this->json($this->createApp()->handle($this->get('/demo', null)));

        self::assertCount(6, $data['board']['statuses']);
        self::assertSame(['open', 'planned', 'in_progress', 'done', 'declined', 'archived'], array_column($data['board']['statuses'], 'key'));
        self::assertNull($data['active_status_key']);

        $idea = $data['ideas'][0];
        self::assertSame('planned', $idea['status']);
        self::assertSame('planned', $idea['status_key']);
        self::assertSame('planned', $idea['status_category']);
        self::assertSame('Planned', $idea['status_label']);
        // No board_statuses rows materialised by a read.
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM board_statuses'));
    }

    public function test_default_board_detail_exposes_the_status_service_graph_as_allowed_next(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'Idea');

        $data = $this->json($this->createApp()->handle($this->get('/demo/ideas/' . $ideaId, null)));

        self::assertSame(['planned', 'in_progress', 'done', 'declined'], $data['idea']['allowed_next']);
        self::assertCount(6, $data['board']['statuses']);
    }

    // ── AuthZ + cross-tenant ──────────────────────────────────────────────

    public function test_admin_routes_require_account_admin(): void
    {
        $this->insertBoard('demo');
        $moderator = $this->insertUser('mod@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $moderator, 'moderator');
        $app = $this->createApp();

        self::assertSame(401, $app->handle($this->get('/admin/boards/demo/statuses', null))->getStatusCode());
        self::assertSame(403, $app->handle($this->get('/admin/boards/demo/statuses', $moderator))->getStatusCode());
        self::assertSame(403, $app->handle($this->send('POST', '/admin/boards/demo/statuses', $moderator, ['category' => 'done', 'label_de' => 'X']))->getStatusCode());
        self::assertSame(403, $app->handle($this->send('PUT', '/admin/boards/demo/statuses/planned', $moderator, ['label_de' => 'Bald']))->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM board_statuses'));
    }

    public function test_writes_without_csrf_are_rejected(): void
    {
        $this->insertBoard('demo');
        $owner = $this->owner();

        $response = $this->createApp()->handle($this->send('POST', '/admin/boards/demo/statuses', $owner, ['category' => 'done', 'label_de' => 'X'], false));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM board_statuses'));
    }

    public function test_foreign_accounts_statuses_are_neither_visible_nor_addressable(): void
    {
        $foreign      = $this->insertAccount(['slug' => 'acct-foreign-status']);
        $foreignBoard = $this->insertBoard('foreign-board', ['account_id' => $foreign, 'statuses_customized' => 1]);
        $this->insertStatusRows($foreignBoard, [['s9', 'done', 'Geheim', null]]);
        $ownBoard = $this->insertBoard('demo');
        $owner    = $this->owner();
        $app      = $this->createApp();

        self::assertSame(404, $app->handle($this->get('/admin/boards/foreign-board/statuses', $owner))->getStatusCode());
        self::assertSame(404, $app->handle($this->send('POST', '/admin/boards/foreign-board/statuses', $owner, ['category' => 'done', 'label_de' => 'X']))->getStatusCode());
        self::assertSame(404, $app->handle($this->send('PUT', '/admin/boards/foreign-board/statuses/s9', $owner, ['label_de' => 'Mine']))->getStatusCode());
        self::assertSame(404, $app->handle($this->send('POST', '/admin/boards/foreign-board/statuses/s9/delete', $owner, []))->getStatusCode());
        // The foreign key is not addressable through the own board either.
        self::assertSame(404, $app->handle($this->send('PUT', '/admin/boards/demo/statuses/s9', $owner, ['label_de' => 'Mine']))->getStatusCode());
        $ideaId = $this->seedIdea($ownBoard, $owner, 'Own idea');
        self::assertSame(422, $app->handle($this->send('POST', '/demo/ideas/' . $ideaId . '/status', $owner, ['status' => 's9']))->getStatusCode());

        $own = $this->json($app->handle($this->get('/admin/boards/demo/statuses', $owner)));
        self::assertNotContains('s9', array_column($own['statuses'], 'key'));
        self::assertSame('Geheim', $this->conn->fetchOne("SELECT label_de FROM board_statuses WHERE status_key = 's9'"));
    }

    // ── Admin CRUD + validation ───────────────────────────────────────────

    public function test_owner_creates_a_custom_status_which_materialises_the_defaults(): void
    {
        $boardId = $this->insertBoard('demo');
        $owner   = $this->owner();

        $response = $this->createApp()->handle($this->send('POST', '/admin/boards/demo/statuses', $owner, [
            'category' => 'done', 'label_de' => '  Beschlossen ', 'label_en' => 'Agreed',
        ]));

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response);
        self::assertMatchesRegularExpression('/^s[0-9a-z]+$/', $data['key']);
        self::assertSame(['open', 'planned', 'in_progress', 'done', $data['key'], 'declined', 'archived'], array_column($data['statuses'], 'key'));
        $custom = $data['statuses'][4];
        self::assertSame('Beschlossen', $custom['label_de']);
        self::assertSame('completed', $custom['category_name']);
        self::assertFalse($custom['is_system']);
        self::assertFalse($custom['allow_votes']);
        self::assertSame(7, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM board_statuses WHERE board_id = ?', [$boardId]));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT statuses_customized FROM boards WHERE id = ?', [$boardId]));
        self::assertStringContainsString('"action":"board.status.created"', $this->readAuditLog());
    }

    public function test_a_new_custom_status_takes_its_category_vote_default_unless_set(): void
    {
        $this->insertBoard('demo');
        $owner = $this->owner();
        $app   = $this->createApp();

        $created = fn (array $body): array => $this->json($app->handle($this->send('POST', '/admin/boards/demo/statuses', $owner, $body + ['label_de' => 'X'])));
        $flag    = static fn (array $data): bool => (bool) array_values(array_filter($data['statuses'], static fn (array $s): bool => $s['key'] === $data['key']))[0]['allow_votes'];

        self::assertTrue($flag($created(['category' => 'planned'])));
        self::assertFalse($flag($created(['category' => 'declined'])));
        self::assertTrue($flag($created(['category' => 'archived', 'allow_votes' => true])));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidCreateProvider(): iterable
    {
        yield 'markup' => [['category' => 'done', 'label_de' => '<b>x</b>'], 'label_de'];
        yield 'control char' => [['category' => 'done', 'label_en' => "a\u{202E}b"], 'label_en'];
        yield 'too long' => [['category' => 'done', 'label_de' => str_repeat('a', 41)], 'label_de'];
        yield 'no label' => [['category' => 'done'], 'label_de'];
        yield 'unknown category' => [['category' => 'shipped', 'label_de' => 'X'], 'category'];
        yield 'bad flag' => [['category' => 'done', 'label_de' => 'X', 'allow_votes' => 'maybe'], 'allow_votes'];
    }

    /** @param array<string, mixed> $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCreateProvider')]
    public function test_create_validation(array $body, string $field): void
    {
        $this->insertBoard('demo');
        $response = $this->createApp()->handle($this->send('POST', '/admin/boards/demo/statuses', $this->owner(), $body));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey($field, $this->json($response)['error']['fields']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM board_statuses'));
    }

    public function test_at_most_twelve_statuses_per_board(): void
    {
        $this->insertBoard('demo');
        $owner = $this->owner();
        $app   = $this->createApp();
        for ($i = 0; $i < 6; ++$i) {
            self::assertSame(201, $app->handle($this->send('POST', '/admin/boards/demo/statuses', $owner, ['category' => 'planned', 'label_de' => 'P' . $i]))->getStatusCode());
        }

        $response = $app->handle($this->send('POST', '/admin/boards/demo/statuses', $owner, ['category' => 'planned', 'label_de' => 'Too many']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('status_limit_reached', $this->json($response)['error']['key']);
    }

    public function test_open_cannot_be_hidden_and_categories_are_immutable(): void
    {
        $this->insertBoard('demo');
        $owner = $this->owner();
        $app   = $this->createApp();

        $hide = $app->handle($this->send('PUT', '/admin/boards/demo/statuses/open', $owner, ['is_hidden' => true]));
        self::assertSame(422, $hide->getStatusCode());
        self::assertArrayHasKey('is_hidden', $this->json($hide)['error']['fields']);

        $move = $app->handle($this->send('PUT', '/admin/boards/demo/statuses/planned', $owner, ['category' => 'done']));
        self::assertSame(422, $move->getStatusCode());

        $delete = $app->handle($this->send('POST', '/admin/boards/demo/statuses/planned/delete', $owner, []));
        self::assertSame(422, $delete->getStatusCode());
        self::assertSame('system_status', $this->json($delete)['error']['key']);
    }

    // ── Rename / custom / hide / delete behaviour ─────────────────────────

    public function test_renamed_system_status_shows_its_label_but_keeps_the_contract(): void
    {
        $boardId = $this->insertBoard('demo');
        $owner   = $this->owner();
        $author  = $this->insertUser('author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author, 'Lighting');
        $sender  = new class () implements WebhookSenderInterface {
            /** @var list<string> */
            public array $bodies = [];

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                $this->bodies[] = $body;

                return WebhookSendResult::response(200, null);
            }
        };
        $app     = $this->createApp(webhookSender: $sender, webhookDeferRunner: static fn (\Closure $job) => $job());
        $app->handle($this->send('PUT', '/admin/boards/demo/webhook', $owner, ['url' => 'https://93.184.216.34/hook']));

        self::assertSame(200, $app->handle($this->send('PUT', '/admin/boards/demo/statuses/planned', $owner, ['label_de' => 'Zur Abstimmung', 'label_en' => 'Up for vote']))->getStatusCode());
        $status = $app->handle($this->send('POST', '/demo/ideas/' . $ideaId . '/status', $owner, ['status' => 'planned']));

        self::assertSame(['ok' => true, 'status' => 'planned', 'status_key' => 'planned', 'allowed_next' => ['open', 'in_progress', 'done', 'declined']], $this->json($status));
        $home = $this->json($app->handle($this->get('/demo', null)));
        self::assertSame('Zur Abstimmung', $home['board']['statuses'][1]['label_de']);
        self::assertSame('Up for vote', $home['ideas'][0]['status_label']);
        self::assertSame('planned', $home['ideas'][0]['status']);

        self::assertCount(1, $sender->bodies);
        $webhook = json_decode($sender->bodies[0], true);
        self::assertSame('planned', $webhook['data']['status_to']);
        self::assertSame('planned', $webhook['data']['status_key_to']);
        self::assertSame('"Lighting" is now Up for vote.', $this->conn->fetchOne("SELECT body FROM notifications WHERE type = 'idea_status_changed'"));
    }

    public function test_custom_completed_status_behaves_like_done_everywhere(): void
    {
        $boardId = $this->insertBoard('demo');
        $owner   = $this->owner();
        $author  = $this->insertUser('author@example.com', ['profile_visible' => 1]);
        $ideaId  = $this->seedIdea($boardId, $author, 'Bigger stage');
        $app     = $this->createApp();
        $key     = $this->json($app->handle($this->send('POST', '/admin/boards/demo/statuses', $owner, ['category' => 'done', 'label_de' => 'Beschlossen'])))['key'];

        $detail = $this->json($app->handle($this->get('/demo/ideas/' . $ideaId, $owner)));
        self::assertContains($key, $detail['idea']['allowed_next']);

        $response = $app->handle($this->send('POST', '/demo/ideas/' . $ideaId . '/status', $owner, ['status' => $key]));
        self::assertSame(['ok' => true, 'status' => 'done', 'status_key' => $key, 'allowed_next' => ['in_progress', 'done', 'declined', 'archived']], $this->json($response));

        // List + category filter + exact filter.
        $home = $this->json($app->handle($this->get('/demo?status=done', null)));
        self::assertSame([$ideaId], array_column($home['ideas'], 'id'));
        self::assertSame('done', $home['ideas'][0]['status']);
        self::assertSame($key, $home['ideas'][0]['status_key']);
        self::assertSame('completed', $home['ideas'][0]['status_category']);
        self::assertSame('Beschlossen', $home['ideas'][0]['status_label']);
        self::assertSame([$ideaId], array_column($this->json($app->handle($this->get('/demo?status_key=' . $key, null)))['ideas'], 'id'));
        self::assertSame([], $this->json($app->handle($this->get('/demo?status_key=done', null)))['ideas']);

        // Roadmap completed column.
        $roadmap = $this->json($app->handle($this->get('/demo/roadmap', null)));
        self::assertSame([$ideaId], array_column($roadmap['groups']['done'], 'id'));
        self::assertSame($key, $roadmap['groups']['done'][0]['status_key']);

        // Counts as shipped.
        $profile = $this->json($app->handle($this->get('/members/' . $author . '/profile', null)));
        self::assertSame(1, $profile['ideas_shipped']);

        // Agent API: status = canonical slug, filters by category or key.
        $pair = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $owner, $pair['hash'], 'Status test', scope: 'read');
        $api = $this->json($app->handle($this->bearer('/api/v1/ideas?status=done', $pair['token'])));
        self::assertSame('done', $api['ideas'][0]['status']);
        self::assertSame($key, $api['ideas'][0]['status_key']);
        self::assertSame($key, $this->json($app->handle($this->bearer('/api/v1/ideas?status_key=' . $key, $pair['token'])))['active_status_key']);
        self::assertCount(1, $this->json($app->handle($this->bearer('/api/v1/ideas?status_key=' . $key, $pair['token'])))['ideas']);
        self::assertSame('done', $this->json($app->handle($this->bearer('/api/v1/ideas/' . $ideaId, $pair['token'])))['idea']['status']);
    }

    public function test_custom_archived_status_is_hidden_from_the_default_list(): void
    {
        $boardId = $this->insertBoard('demo', ['statuses_customized' => 1]);
        $this->insertStatusRows($boardId, [['s1', 'archived', 'Abgelegt', null]]);
        $author = $this->insertUser('author@example.com');
        $this->seedIdea($boardId, $author, 'Visible');
        $this->seedIdea($boardId, $author, 'Put away', ['status' => 's1']);

        $home = $this->json($this->createApp()->handle($this->get('/demo', null)));

        self::assertSame(['Visible'], array_column($home['ideas'], 'title'));
    }

    public function test_search_combines_with_category_and_status_key_filters(): void
    {
        $boardId = $this->insertBoard('demo', ['statuses_customized' => 1]);
        $this->insertStatusRows($boardId, [['s1', 'done', 'Beschlossen', null], ['s2', 'archived', 'Abgelegt', null]]);
        $author = $this->insertUser('author@example.com');
        $this->seedIdea($boardId, $author, 'Dark mode export', ['status' => 's1']);
        $this->seedIdea($boardId, $author, 'Dark mode toggle', ['status' => 'done']);
        $this->seedIdea($boardId, $author, 'Dark mode sidebar');
        $this->seedIdea($boardId, $author, 'Light mode', ['status' => 's1']);
        $this->seedIdea($boardId, $author, 'Dark mode legacy', ['status' => 's2']);
        $app    = $this->createApp();
        $titles = function (string $path) use ($app): array {
            $t = array_column($this->json($app->handle($this->get($path, null)))['ideas'], 'title');
            sort($t);

            return $t;
        };

        // q + category: every status of the category (system + custom), narrowed by q.
        self::assertSame(['Dark mode export', 'Dark mode toggle'], $titles('/demo?q=dark&status=done'));
        // q + exact key.
        self::assertSame(['Dark mode export'], $titles('/demo?q=dark&status_key=s1'));
        // q alone still hides the archived CATEGORY (custom archived key s2).
        self::assertSame(['Dark mode export', 'Dark mode sidebar', 'Dark mode toggle'], $titles('/demo?q=dark'));
        // q + archived category opts the custom archived status back in.
        self::assertSame(['Dark mode legacy'], $titles('/demo?q=dark&status=archived'));

        // The pagination count shares the same WHERE (listWhere()).
        $repo = new IdeaRepository($this->conn);
        $q    = IdeaSearchQuery::fromRaw('dark');
        self::assertSame(2, $repo->countListed($boardId, 'done', $q));
        self::assertSame(1, $repo->countListed($boardId, 'done', $q, 's1'));
        self::assertSame(3, $repo->countListed($boardId, null, $q));
        self::assertSame(1, $repo->countListed($boardId, 'archived', $q));
    }

    public function test_hidden_status_keeps_its_ideas_visible_but_is_not_assignable(): void
    {
        $boardId = $this->insertBoard('demo');
        $owner   = $this->owner();
        $author  = $this->insertUser('author@example.com');
        $planned = $this->seedIdea($boardId, $author, 'Already planned', ['status' => 'planned']);
        $fresh   = $this->seedIdea($boardId, $author, 'Fresh');
        $app     = $this->createApp();

        self::assertSame(200, $app->handle($this->send('PUT', '/admin/boards/demo/statuses/planned', $owner, ['is_hidden' => true]))->getStatusCode());

        $home = $this->json($app->handle($this->get('/demo', null)));
        self::assertContains($planned, array_column($home['ideas'], 'id'));
        self::assertTrue($home['board']['statuses'][1]['is_hidden']);
        self::assertSame(1, $home['board']['statuses'][1]['idea_count']);

        $detail = $this->json($app->handle($this->get('/demo/ideas/' . $fresh, $owner)));
        self::assertNotContains('planned', $detail['idea']['allowed_next']);
        $refused = $app->handle($this->send('POST', '/demo/ideas/' . $fresh . '/status', $owner, ['status' => 'planned']));
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('planned', $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$planned]));
        // The idea in the hidden status can still move on.
        self::assertSame(200, $app->handle($this->send('POST', '/demo/ideas/' . $planned . '/status', $owner, ['status' => 'in_progress']))->getStatusCode());
    }

    public function test_deleting_a_custom_status_with_ideas_requires_a_move_within_the_category(): void
    {
        $boardId = $this->insertBoard('demo');
        $owner   = $this->owner();
        $app     = $this->createApp();
        $key     = $this->json($app->handle($this->send('POST', '/admin/boards/demo/statuses', $owner, ['category' => 'done', 'label_de' => 'Beschlossen'])))['key'];
        $ideaId  = $this->seedIdea($boardId, $owner, 'In custom', ['status' => $key]);

        $refused = $app->handle($this->send('POST', "/admin/boards/demo/statuses/{$key}/delete", $owner, []));
        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('status_in_use', $this->json($refused)['error']['key']);

        $wrongCategory = $app->handle($this->send('POST', "/admin/boards/demo/statuses/{$key}/delete", $owner, ['move_to' => 'planned']));
        self::assertSame(422, $wrongCategory->getStatusCode());
        self::assertSame($key, $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$ideaId]));

        $ok = $app->handle($this->send('POST', "/admin/boards/demo/statuses/{$key}/delete", $owner, ['move_to' => 'done']));
        self::assertSame(200, $ok->getStatusCode());
        self::assertNotContains($key, array_column($this->json($ok)['statuses'], 'key'));
        self::assertSame('done', $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$ideaId]));
        self::assertStringContainsString('"action":"board.status.deleted"', $this->readAuditLog());
    }

    // ── Status-driven phases ──────────────────────────────────────────────

    public function test_status_closed_for_votes_refuses_votes_and_keeps_existing_ones(): void
    {
        $boardId = $this->insertBoard('demo');
        $owner   = $this->owner();
        $voter   = $this->insertUser('voter@example.com');
        $ideaId  = $this->seedIdea($boardId, $owner, 'Decided', ['status' => 'done']);
        $this->seedVote($ideaId, $owner, 1);
        $app = $this->createApp();
        $app->handle($this->send('PUT', '/admin/boards/demo/statuses/done', $owner, ['allow_votes' => false]));

        $response = $app->handle($this->send('POST', '/demo/ideas/' . $ideaId . '/vote', $voter, ['value' => 'up']));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame('status_closed_for_votes', $this->json($response)['error']['key']);
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = ?', [$ideaId]));
        // Other statuses keep voting open.
        $openIdea = $this->seedIdea($boardId, $owner, 'Open one');
        self::assertSame(200, $app->handle($this->send('POST', '/demo/ideas/' . $openIdea . '/vote', $voter, ['value' => 'up']))->getStatusCode());
    }

    public function test_default_board_closes_votes_on_decided_ideas_but_not_on_open_ones(): void
    {
        $boardId = $this->insertBoard('demo');
        $owner   = $this->owner();
        $voter   = $this->insertUser('voter@example.com');
        $app     = $this->createApp();
        $decided = [];
        foreach (['done', 'declined', 'archived'] as $status) {
            $decided[$status] = $this->seedIdea($boardId, $owner, 'Decided ' . $status, ['status' => $status]);
        }
        $open = $this->seedIdea($boardId, $owner, 'Still open', ['status' => 'in_progress']);

        foreach ($decided as $ideaId) {
            $response = $app->handle($this->send('POST', '/demo/ideas/' . $ideaId . '/vote', $voter, ['value' => 'up']));
            self::assertSame(423, $response->getStatusCode());
            self::assertSame('status_closed_for_votes', $this->json($response)['error']['key']);
        }
        // Un-voting is locked the same way (the lock runs before the value is parsed).
        self::assertSame(423, $app->handle($this->send('POST', '/demo/ideas/' . $decided['done'] . '/vote', $voter, ['value' => 'none']))->getStatusCode());
        self::assertSame(200, $app->handle($this->send('POST', '/demo/ideas/' . $open . '/vote', $voter, ['value' => 'up']))->getStatusCode());
    }

    public function test_materialising_the_board_keeps_the_default_vote_flags(): void
    {
        $boardId = $this->insertBoard('demo');
        $owner   = $this->owner();
        $app     = $this->createApp();

        // Renaming an open status is the first admin edit: it materialises all six rows.
        self::assertSame(200, $app->handle($this->send('PUT', '/admin/boards/demo/statuses/planned', $owner, ['label_de' => 'Bald']))->getStatusCode());

        $flags = $this->conn->fetchAllKeyValue('SELECT status_key, allow_votes FROM board_statuses WHERE board_id = ?', [$boardId]);
        $flags = array_map(intval(...), $flags);
        ksort($flags);
        self::assertSame(
            ['archived' => 0, 'declined' => 0, 'done' => 0, 'in_progress' => 1, 'open' => 1, 'planned' => 1],
            $flags,
        );
        $statuses = array_column($this->json($app->handle($this->get('/demo', null)))['board']['statuses'], 'allow_votes', 'key');
        self::assertSame(['open' => true, 'planned' => true, 'in_progress' => true, 'done' => false, 'declined' => false, 'archived' => false], $statuses);
    }

    public function test_admin_can_reopen_votes_on_a_decided_status(): void
    {
        $boardId = $this->insertBoard('demo');
        $owner   = $this->owner();
        $voter   = $this->insertUser('voter@example.com');
        $ideaId  = $this->seedIdea($boardId, $owner, 'Decided', ['status' => 'done']);
        $app     = $this->createApp();
        self::assertSame(423, $app->handle($this->send('POST', '/demo/ideas/' . $ideaId . '/vote', $voter, ['value' => 'up']))->getStatusCode());

        self::assertSame(200, $app->handle($this->send('PUT', '/admin/boards/demo/statuses/done', $owner, ['allow_votes' => true]))->getStatusCode());

        self::assertSame(200, $app->handle($this->send('POST', '/demo/ideas/' . $ideaId . '/vote', $voter, ['value' => 'up']))->getStatusCode());
    }

    public function test_status_closed_for_comments_refuses_comments(): void
    {
        $boardId = $this->insertBoard('demo');
        $owner   = $this->owner();
        $ideaId  = $this->seedIdea($boardId, $owner, 'Decided', ['status' => 'declined']);
        $app     = $this->createApp();
        $app->handle($this->send('PUT', '/admin/boards/demo/statuses/declined', $owner, ['allow_comments' => false]));

        $response = $app->handle($this->send('POST', '/demo/ideas/' . $ideaId . '/comments', $this->insertUser('c@example.com'), ['body' => 'One more thought on this.']));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame('status_closed_for_comments', $this->json($response)['error']['key']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM comments'));
    }

    // ── Export ────────────────────────────────────────────────────────────

    public function test_export_contains_the_board_statuses_with_canonical_idea_status(): void
    {
        $boardId = $this->insertBoard('demo', ['statuses_customized' => 1]);
        $this->insertStatusRows($boardId, [['s1', 'done', 'Beschlossen', null]]);
        $owner = $this->owner();
        $this->seedIdea($boardId, $owner, 'Agreed idea', ['status' => 's1']);

        $data = $this->json($this->createApp()->handle($this->get('/admin/export', $owner)));

        self::assertSame(['s1'], array_column(array_filter($data['board_statuses'], static fn (array $r): bool => !(bool) $r['is_system']), 'status_key'));
        self::assertSame('done', $data['ideas'][0]['status']);
        self::assertSame('s1', $data['ideas'][0]['status_key']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Seeds the six system rows plus custom rows [key, category, label_de, label_en].
     *
     * @param list<array{0: string, 1: string, 2: ?string, 3: ?string}> $custom
     */
    private function insertStatusRows(int $boardId, array $custom): void
    {
        foreach (['open', 'planned', 'in_progress', 'done', 'declined', 'archived'] as $i => $key) {
            $this->conn->insert('board_statuses', ['board_id' => $boardId, 'status_key' => $key, 'category' => $key, 'position' => $i, 'is_system' => 1]);
        }
        foreach ($custom as $i => [$key, $category, $de, $en]) {
            $this->conn->insert('board_statuses', [
                'board_id' => $boardId, 'status_key' => $key, 'category' => $category,
                'label_de' => $de, 'label_en' => $en, 'position' => 10 + $i, 'is_system' => 0,
            ]);
        }
    }

    private function owner(): int
    {
        $id = $this->insertUser('owner-' . (++$this->seq) . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $id, 'owner');

        return $id;
    }

    /** @return array<string, mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data, (string) $response->getBody());

        return $data;
    }

    private function sessionFor(int $userId): string
    {
        return (new SessionService(str_repeat('a', 64), 3600, false))->sign(['uid' => $userId, 'v' => 0]);
    }

    private function get(string $path, ?int $userId): ServerRequestInterface
    {
        [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($q, $query);
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path)->withQueryParams($query)
            ->withHeader('User-Agent', 'status-test-' . (++$this->seq));

        return $userId !== null ? $request->withCookieParams(['votepit_sess' => $this->sessionFor($userId)]) : $request;
    }

    private function bearer(string $path, string $token): ServerRequestInterface
    {
        [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($q, $query);

        return (new ServerRequestFactory())->createServerRequest('GET', $path)->withQueryParams($query)
            ->withHeader('Authorization', 'Bearer ' . $token);
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $path, int $userId, array $body, bool $withCsrf = true): ServerRequestInterface
    {
        $csrf    = new CsrfService(str_repeat('a', 64), 3600, false);
        $token   = $csrf->generate();
        $cookies = ['votepit_sess' => $this->sessionFor($userId)];
        if ($withCsrf) {
            $cookies['votepit_csrf'] = $csrf->sign($token);
        }

        $request = (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withCookieParams($cookies)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json');
        if ($withCsrf) {
            $request = $request->withHeader('X-CSRF-Token', $token);
        }

        return $request->withParsedBody($body);
    }

}
