<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\AnonymousVoteCookie;
use Votepit\Security\CsrfService;
use Votepit\Security\SessionService;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\FixedClock;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Optional board deadlines (broaden-use-cases issue 04,
 * migrations/0082_add_board_phase_windows.sql) through the real HTTP seam:
 * default boards unchanged, vote/submit gating on every path (signed-in,
 * anonymous, REST, MCP), the AND with the status flag (0073), admin
 * exemption, exact boundaries with an injected clock, admin settings
 * (AuthZ, CSRF, validation, audit) and cross-tenant isolation.
 */
final class BoardPhaseWindowsTest extends IntegrationTestCase
{
    private const NOW = '2026-04-15 12:00:00';

    private int $seq = 0;

    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock(self::NOW);
    }

    // ── Defaults unchanged ────────────────────────────────────────────────

    public function test_board_without_dates_is_open_and_payload_only_gains_phases(): void
    {
        $boardId = $this->insertBoard('demo');
        $member  = $this->insertUser('member@example.com');
        $ideaId  = $this->seedIdea($boardId, $member, 'An idea');
        $app     = $this->app();

        $home = $this->json($app->handle($this->get('/demo', null)));
        $open = ['state' => 'open', 'opens_at' => null, 'closes_at' => null];
        self::assertSame(['submissions' => $open, 'voting' => $open], $home['board']['phases']);
        self::assertSame(['submissions' => $open, 'voting' => $open], $this->json($app->handle($this->get('/demo/ideas/' . $ideaId, null)))['board']['phases']);
        self::assertSame(['submissions' => $open, 'voting' => $open], $this->json($app->handle($this->get('/demo/ideas/new', null)))['board']['phases']);

        self::assertSame(200, $app->handle($this->send('POST', "/demo/ideas/{$ideaId}/vote", $member, ['value' => 'up']))->getStatusCode());
        self::assertSame(201, $app->handle($this->submitIdea('demo', $member))->getStatusCode());
    }

    // ── Voting window ─────────────────────────────────────────────────────

    public function test_closed_voting_window_refuses_signed_in_votes_and_keeps_existing_ones(): void
    {
        $boardId = $this->insertBoard('demo', ['voting_closes_at' => '2026-04-01 00:00:00']);
        $author  = $this->insertUser('author@example.com');
        $voter   = $this->insertUser('voter@example.com');
        $ideaId  = $this->seedIdea($boardId, $author, 'An idea');
        $this->seedVote($ideaId, $author, 1);
        $app = $this->app();

        $response = $app->handle($this->send('POST', "/demo/ideas/{$ideaId}/vote", $voter, ['value' => 'up']));
        self::assertSame(423, $response->getStatusCode());
        self::assertSame('voting_closed', $this->json($response)['error']['key']);
        // Retracting an existing vote is a write too — refused, vote kept.
        $retract = $app->handle($this->send('POST', "/demo/ideas/{$ideaId}/vote", $author, ['value' => 'up']));
        self::assertSame('voting_closed', $this->json($retract)['error']['key']);
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = ?', [$ideaId]));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT value FROM votes WHERE idea_id = ?', [$ideaId]));

        // Reading is never affected.
        self::assertSame(200, $app->handle($this->get('/demo', null))->getStatusCode());
        self::assertSame(200, $app->handle($this->get('/demo/ideas/' . $ideaId, null))->getStatusCode());
        self::assertSame('closed', $this->json($app->handle($this->get('/demo', null)))['board']['phases']['voting']['state']);
    }

    public function test_closed_voting_window_refuses_anonymous_votes(): void
    {
        $boardId = $this->insertBoard('demo', ['anonymous_voting_enabled' => 1, 'voting_closes_at' => '2026-04-01 00:00:00']);
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'An idea');

        $response = $this->app()->handle($this->anonymousVote("/demo/ideas/{$ideaId}/vote"));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame('voting_closed', $this->json($response)['error']['key']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));
        // No anonymous voter identity is minted for a refused vote.
        self::assertStringNotContainsString(AnonymousVoteCookie::COOKIE_NAME, $response->getHeaderLine('Set-Cookie'));
    }

    public function test_upcoming_voting_window_reports_not_open_yet(): void
    {
        $boardId = $this->insertBoard('demo', ['voting_opens_at' => '2026-05-01 00:00:00']);
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'An idea');

        $response = $this->app()->handle($this->send('POST', "/demo/ideas/{$ideaId}/vote", $this->insertUser('v@example.com'), ['value' => 'up']));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame('voting_not_open_yet', $this->json($response)['error']['key']);
    }

    public function test_voting_boundaries_with_the_injected_clock(): void
    {
        $boardId = $this->insertBoard('demo', ['voting_opens_at' => '2026-04-15 12:00:00', 'voting_closes_at' => '2026-04-20 12:00:00']);
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'An idea');
        $voter   = $this->insertUser('voter@example.com');
        $app     = $this->app();

        $this->clock->set('2026-04-15 11:59:59');
        self::assertSame('voting_not_open_yet', $this->json($app->handle($this->vote($ideaId, $voter, 'up')))['error']['key']);

        $this->clock->set('2026-04-15 12:00:00');
        self::assertSame(200, $app->handle($this->vote($ideaId, $voter, 'up'))->getStatusCode());

        $this->clock->set('2026-04-20 11:59:59');
        self::assertSame(200, $app->handle($this->vote($ideaId, $voter, 'down'))->getStatusCode());

        $this->clock->set('2026-04-20 12:00:00');
        self::assertSame('voting_closed', $this->json($app->handle($this->vote($ideaId, $voter, 'up')))['error']['key']);
        self::assertSame(-1, (int) $this->conn->fetchOne('SELECT value FROM votes WHERE idea_id = ?', [$ideaId]));
    }

    public function test_effective_rule_is_timer_and_status_flag(): void
    {
        $boardId = $this->insertBoard('demo', ['voting_opens_at' => '2026-04-01 00:00:00']);
        $owner   = $this->owner();
        $voter   = $this->insertUser('voter@example.com');
        $done    = $this->seedIdea($boardId, $owner, 'Decided', ['status' => 'done']);
        $open    = $this->seedIdea($boardId, $owner, 'Still open');
        $app     = $this->app();
        $app->handle($this->send('PUT', '/admin/boards/demo/statuses/done', $owner, ['allow_votes' => false]));

        // Timer open, status closed → refused by the status flag.
        self::assertSame('status_closed_for_votes', $this->json($app->handle($this->vote($done, $voter, 'up')))['error']['key']);
        // Both open → allowed.
        self::assertSame(200, $app->handle($this->vote($open, $voter, 'up'))->getStatusCode());

        // Timer closed, status open → refused by the timer.
        $this->conn->update('boards', ['voting_closes_at' => '2026-04-10 00:00:00'], ['id' => $boardId]);
        self::assertSame('voting_closed', $this->json($app->handle($this->vote($open, $voter, 'down')))['error']['key']);
    }

    public function test_windows_never_govern_comments(): void
    {
        $boardId = $this->insertBoard('demo', ['voting_closes_at' => '2026-04-01 00:00:00', 'submissions_closes_at' => '2026-04-01 00:00:00']);
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'An idea');

        $response = $this->app()->handle($this->send('POST', "/demo/ideas/{$ideaId}/comments", $this->insertUser('c@example.com'), ['body' => 'Still worth a comment.']));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_plan_freeze_keeps_its_own_error_code(): void
    {
        $boardId = $this->insertBoard('demo', ['voting_closes_at' => '2026-04-01 00:00:00', 'frozen_at' => '2026-04-02 00:00:00']);
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'An idea');

        $response = $this->app()->handle($this->vote($ideaId, $this->insertUser('v@example.com'), 'up'));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame('board_frozen', $this->json($response)['error']['key']);
    }

    // ── Submission window ─────────────────────────────────────────────────

    public function test_closed_and_upcoming_submission_windows_refuse_members(): void
    {
        $this->insertBoard('closed', ['submissions_closes_at' => '2026-04-01 00:00:00']);
        $this->insertBoard('soon', ['submissions_opens_at' => '2026-05-01 00:00:00']);
        $member    = $this->insertUser('member@example.com');
        $moderator = $this->insertUser('mod@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $moderator, 'moderator');
        $app = $this->app();

        $closed = $app->handle($this->submitIdea('closed', $member));
        self::assertSame(423, $closed->getStatusCode());
        self::assertSame('submissions_closed', $this->json($closed)['error']['key']);

        $soon = $app->handle($this->submitIdea('soon', $member));
        self::assertSame(423, $soon->getStatusCode());
        self::assertSame('submissions_not_open_yet', $this->json($soon)['error']['key']);

        // Moderators are bound like members (only owner/admin are exempt).
        self::assertSame('submissions_not_open_yet', $this->json($app->handle($this->submitIdea('soon', $moderator)))['error']['key']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas'));
    }

    // ── Interplay with pre-moderation (issue 05) ──────────────────────────

    public function test_member_submission_inside_window_on_premoderated_board_is_pending(): void
    {
        $boardId = $this->insertBoard('demo', [
            'premoderation'         => 'all',
            'submissions_opens_at'  => '2026-04-01 00:00:00',
            'submissions_closes_at' => '2026-04-30 00:00:00',
        ]);
        $member = $this->insertUser('member@example.com');

        $response = $this->app()->handle($this->submitIdea('demo', $member));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('pending', $this->json($response)['moderation_state']);
        self::assertSame('pending', $this->conn->fetchOne('SELECT moderation_state FROM ideas WHERE board_id = ?', [$boardId]));
    }

    public function test_member_submission_outside_window_on_premoderated_board_is_423_and_nothing_is_queued(): void
    {
        $this->insertBoard('closed', ['premoderation' => 'all', 'submissions_closes_at' => '2026-04-01 00:00:00']);
        $this->insertBoard('soon', ['premoderation' => 'all', 'submissions_opens_at' => '2026-05-01 00:00:00']);
        $member = $this->insertUser('member@example.com');
        $app    = $this->app();

        $closed = $app->handle($this->submitIdea('closed', $member));
        self::assertSame(423, $closed->getStatusCode());
        self::assertSame('submissions_closed', $this->json($closed)['error']['key']);
        self::assertSame('submissions_not_open_yet', $this->json($app->handle($this->submitIdea('soon', $member)))['error']['key']);

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas'));
        self::assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM notifications WHERE type LIKE 'idea_%'"));
    }

    public function test_approving_a_pending_idea_after_the_window_closed_is_allowed(): void
    {
        $boardId = $this->insertBoard('demo', ['premoderation' => 'all', 'submissions_closes_at' => '2026-04-01 00:00:00']);
        $member  = $this->insertUser('member@example.com');
        $ideaId  = $this->seedIdea($boardId, $member, 'Queued before the deadline', ['moderation_state' => 'pending']);

        $response = $this->app()->handle($this->send('POST', '/admin/boards/demo/queue/approve', $this->owner(), ['ids' => [$ideaId]]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$ideaId], $this->json($response)['approved']);
        self::assertSame('published', $this->conn->fetchOne('SELECT moderation_state FROM ideas WHERE id = ?', [$ideaId]));
    }

    public function test_votes_on_pending_ideas_stay_impossible_inside_an_open_voting_window(): void
    {
        $boardId = $this->insertBoard('demo', ['premoderation' => 'all', 'voting_closes_at' => '2026-04-30 00:00:00']);
        $member  = $this->insertUser('member@example.com');
        $voter   = $this->insertUser('voter@example.com');
        $ideaId  = $this->seedIdea($boardId, $member, 'Pending idea', ['moderation_state' => 'pending']);

        $response = $this->app()->handle($this->vote($ideaId, $voter, 'up'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));
    }

    public function test_owner_and_admin_may_seed_ideas_before_opening(): void
    {
        $this->insertBoard('soon', ['submissions_opens_at' => '2026-05-01 00:00:00', 'submissions_closes_at' => '2026-05-31 00:00:00']);
        $admin = $this->insertUser('admin@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $admin, 'admin');
        $app = $this->app();

        self::assertSame(201, $app->handle($this->submitIdea('soon', $this->owner()))->getStatusCode());
        self::assertSame(201, $app->handle($this->submitIdea('soon', $admin))->getStatusCode());
        self::assertSame(2, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas'));
    }

    public function test_submission_boundaries_with_the_injected_clock(): void
    {
        $this->insertBoard('demo', ['submissions_opens_at' => '2026-04-15 12:00:00', 'submissions_closes_at' => '2026-04-15 18:00:00']);
        $member = $this->insertUser('member@example.com');
        $app    = $this->app();

        $this->clock->set('2026-04-15 11:59:59');
        self::assertSame(423, $app->handle($this->submitIdea('demo', $member))->getStatusCode());
        $this->clock->set('2026-04-15 12:00:00');
        self::assertSame(201, $app->handle($this->submitIdea('demo', $member))->getStatusCode());
        $this->clock->set('2026-04-15 18:00:00');
        self::assertSame('submissions_closed', $this->json($app->handle($this->submitIdea('demo', $member)))['error']['key']);
    }

    public function test_agent_api_tokens_are_bound_by_the_submission_window(): void
    {
        $boardId = $this->insertBoard('demo', ['submissions_closes_at' => '2026-04-01 00:00:00']);
        // Token issued by the OWNER — still bound (fail-secure default).
        $pair = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $this->owner(), $pair['hash']);
        $app = $this->app();

        $rest = $app->handle($this->bearerPost('/api/v1/ideas', $pair['token'], ['title' => 'Agent idea', 'body' => 'Submitted by an agent.']));
        self::assertSame(423, $rest->getStatusCode());
        self::assertSame('submissions_closed', $this->json($rest)['error']['key']);

        $mcp = $this->json($app->handle($this->bearerPost('/api/v1/mcp', $pair['token'], [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params'  => ['name' => 'create_idea', 'arguments' => ['title' => 'Agent idea', 'body' => 'Submitted by an agent.']],
        ])));
        self::assertTrue($mcp['result']['isError']);
        self::assertSame('submissions_closed', json_decode((string) $mcp['result']['content'][0]['text'], true)['error']['key']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas'));

        // Reading through the API is never affected.
        self::assertSame(200, $app->handle($this->bearerGet('/api/v1/ideas', $pair['token']))->getStatusCode());
    }

    public function test_agent_api_submission_unchanged_without_dates(): void
    {
        $boardId = $this->insertBoard('demo');
        $pair    = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $this->owner(), $pair['hash']);

        $rest = $this->app()->handle($this->bearerPost('/api/v1/ideas', $pair['token'], ['title' => 'Agent idea', 'body' => 'Submitted by an agent.']));

        self::assertSame(201, $rest->getStatusCode());
    }

    // ── Admin settings ────────────────────────────────────────────────────

    public function test_owner_sets_reads_and_clears_the_windows(): void
    {
        $boardId = $this->insertBoard('demo');
        $owner   = $this->owner();
        $app     = $this->app();

        $saved = $app->handle($this->send('POST', '/admin/boards/demo/phases', $owner, [
            'submissions_opens_at'  => null,
            'submissions_closes_at' => '2026-04-30T23:59:00+02:00',
            'voting_opens_at'       => '2026-05-01T00:00:00+02:00',
            'voting_closes_at'      => '2026-05-15T22:00:00Z',
        ]));
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $data = $this->json($saved);
        self::assertSame('2026-04-30T21:59:00Z', $data['submissions_closes_at']);
        self::assertSame('2026-04-30T22:00:00Z', $data['voting_opens_at']);
        self::assertSame('open', $data['phases']['submissions']['state']);
        self::assertSame('upcoming', $data['phases']['voting']['state']);
        self::assertSame('2026-04-15T12:00:00Z', $data['now']);
        self::assertSame('2026-04-30 21:59:00', $this->conn->fetchOne('SELECT submissions_closes_at FROM boards WHERE id = ?', [$boardId]));
        self::assertStringContainsString('"action":"board.phases_updated"', $this->readAuditLog());

        self::assertSame($data, $this->json($app->handle($this->get('/admin/boards/demo/phases', $owner))));

        // "Clear" = empty values; the board is back to the default behaviour.
        $cleared = $this->json($app->handle($this->send('POST', '/admin/boards/demo/phases', $owner, [
            'submissions_opens_at' => '', 'submissions_closes_at' => '', 'voting_opens_at' => null, 'voting_closes_at' => null,
        ])));
        self::assertNull($cleared['voting_opens_at']);
        self::assertSame(
            [null, null, null, null],
            array_values((array) $this->conn->fetchAssociative('SELECT submissions_opens_at, submissions_closes_at, voting_opens_at, voting_closes_at FROM boards WHERE id = ?', [$boardId])),
        );
    }

    public function test_settings_expose_statuses_that_close_voting(): void
    {
        $this->insertBoard('demo');
        $owner = $this->owner();
        $app   = $this->app();
        $app->handle($this->send('PUT', '/admin/boards/demo/statuses/in_progress', $owner, ['allow_votes' => false]));

        $data = $this->json($app->handle($this->get('/admin/boards/demo/phases', $owner)));

        // Default-closed decided statuses (owner decision 2026-10-26) plus the one closed explicitly.
        self::assertSame(['in_progress', 'done', 'declined', 'archived'], array_column($data['statuses_closed_for_votes'], 'key'));
    }

    public function test_closes_in_the_past_is_allowed_and_closes_immediately(): void
    {
        $this->insertBoard('demo');
        $owner = $this->owner();
        $app   = $this->app();

        $saved = $app->handle($this->send('POST', '/admin/boards/demo/phases', $owner, ['voting_closes_at' => '2026-01-01T00:00:00Z']));

        self::assertSame(200, $saved->getStatusCode());
        self::assertSame('closed', $this->json($saved)['phases']['voting']['state']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidSettingsProvider(): iterable
    {
        yield 'opens after closes'   => [['voting_opens_at' => '2026-05-02T00:00:00Z', 'voting_closes_at' => '2026-05-01T00:00:00Z'], 'voting_closes_at'];
        yield 'opens equals closes'  => [['submissions_opens_at' => '2026-05-01T00:00:00Z', 'submissions_closes_at' => '2026-05-01T00:00:00Z'], 'submissions_closes_at'];
        yield 'no time zone'         => [['voting_opens_at' => '2026-05-01T00:00'], 'voting_opens_at'];
        yield 'garbage'              => [['submissions_opens_at' => 'next week'], 'submissions_opens_at'];
        yield 'non-string'           => [['voting_closes_at' => ['2026-05-01T00:00:00Z']], 'voting_closes_at'];
    }

    /** @param array<string, mixed> $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSettingsProvider')]
    public function test_invalid_settings_are_rejected_without_writing(array $body, string $field): void
    {
        $boardId = $this->insertBoard('demo', ['voting_opens_at' => '2026-03-01 00:00:00']);

        $response = $this->app()->handle($this->send('POST', '/admin/boards/demo/phases', $this->owner(), $body));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey($field, $this->json($response)['error']['fields']);
        self::assertSame('2026-03-01 00:00:00', $this->conn->fetchOne('SELECT voting_opens_at FROM boards WHERE id = ?', [$boardId]));
    }

    public function test_settings_require_account_admin_and_csrf(): void
    {
        $boardId   = $this->insertBoard('demo');
        $moderator = $this->insertUser('mod@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $moderator, 'moderator');
        $member = $this->insertUser('member@example.com');
        $app    = $this->app();
        $body   = ['voting_closes_at' => '2026-01-01T00:00:00Z'];

        self::assertSame(401, $app->handle($this->get('/admin/boards/demo/phases', null))->getStatusCode());
        self::assertSame(403, $app->handle($this->get('/admin/boards/demo/phases', $moderator))->getStatusCode());
        self::assertSame(403, $app->handle($this->send('POST', '/admin/boards/demo/phases', $moderator, $body))->getStatusCode());
        self::assertSame(403, $app->handle($this->send('POST', '/admin/boards/demo/phases', $member, $body))->getStatusCode());
        self::assertSame(403, $app->handle($this->send('POST', '/admin/boards/demo/phases', $this->owner(), $body, false))->getStatusCode());
        self::assertNull($this->conn->fetchOne('SELECT voting_closes_at FROM boards WHERE id = ?', [$boardId]));
    }

    // ── Cross-tenant ──────────────────────────────────────────────────────

    public function test_foreign_board_is_not_addressable_in_self_host_routing(): void
    {
        $foreign      = $this->insertAccount(['slug' => 'acct-foreign']);
        $foreignBoard = $this->insertBoard('foreign-board', ['account_id' => $foreign]);
        $this->insertBoard('demo');
        $owner = $this->owner();
        $app   = $this->app();

        self::assertSame(404, $app->handle($this->get('/admin/boards/foreign-board/phases', $owner))->getStatusCode());
        self::assertSame(404, $app->handle($this->send('POST', '/admin/boards/foreign-board/phases', $owner, ['voting_closes_at' => '2026-01-01T00:00:00Z']))->getStatusCode());
        self::assertNull($this->conn->fetchOne('SELECT voting_closes_at FROM boards WHERE id = ?', [$foreignBoard]));
    }

    public function test_dates_of_account_a_do_not_affect_account_b_with_the_same_board_slug(): void
    {
        $accountA = $this->insertAccount(['slug' => 'acct-a']);
        $accountB = $this->insertAccount(['slug' => 'acct-b']);
        $this->insertBoard('demo', ['account_id' => $accountA]);
        $boardB = $this->insertBoard('demo', ['account_id' => $accountB]);
        $ownerA = $this->insertUser('owner-a@example.com');
        $this->insertAccountMember($accountA, $ownerA, 'owner');
        $voterB = $this->insertUser('voter-b@example.com');
        $ideaB  = $this->seedIdea($boardB, $voterB, 'Idea in B');
        $app    = $this->app(Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud'])));
        $closed = ['voting_closes_at' => '2026-01-01T00:00:00Z', 'submissions_closes_at' => '2026-01-01T00:00:00Z'];

        self::assertSame(200, $app->handle($this->send('POST', '/acct-a/admin/boards/demo/phases', $ownerA, $closed))->getStatusCode());
        // Owner of A cannot touch B's settings.
        self::assertSame(403, $app->handle($this->send('POST', '/acct-b/admin/boards/demo/phases', $ownerA, $closed))->getStatusCode());

        self::assertNull($this->conn->fetchOne('SELECT voting_closes_at FROM boards WHERE id = ?', [$boardB]));
        self::assertSame('open', $this->json($app->handle($this->get('/acct-b/demo', null)))['board']['phases']['voting']['state']);
        self::assertSame('closed', $this->json($app->handle($this->get('/acct-a/demo', null)))['board']['phases']['voting']['state']);
        self::assertSame(200, $app->handle($this->send('POST', "/acct-b/demo/ideas/{$ideaB}/vote", $voterB, ['value' => 'up']))->getStatusCode());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return \Slim\App<null> */
    private function app(?Config $config = null): \Slim\App
    {
        return $this->createApp(config: $config, clock: $this->clock);
    }

    private function owner(): int
    {
        $id = $this->insertUser('owner-' . (++$this->seq) . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $id, 'owner');

        return $id;
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
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
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withHeader('User-Agent', 'phase-test-' . (++$this->seq));

        return $userId !== null ? $request->withCookieParams(['votepit_sess' => $this->sessionFor($userId)]) : $request;
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

    private function vote(int $ideaId, int $userId, string $value): ServerRequestInterface
    {
        return $this->send('POST', "/demo/ideas/{$ideaId}/vote", $userId, ['value' => $value]);
    }

    private function anonymousVote(string $path): ServerRequestInterface
    {
        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();

        return (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withCookieParams(['votepit_csrf' => $csrf->sign($token)])
            ->withHeader('X-CSRF-Token', $token)
            ->withParsedBody(['value' => 'up']);
    }

    private function submitIdea(string $slug, int $userId): ServerRequestInterface
    {
        $ts  = (string) (time() - 5);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, str_repeat('a', 64), true)), '+/', '-_'), '=');

        return $this->send('POST', '/' . $slug . '/ideas', $userId, [
            'title'    => 'Idea number ' . (++$this->seq),
            'body'     => 'A sufficiently long description.',
            '_form_at' => $ts . '.' . $mac,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function bearerPost(string $path, string $token, array $body): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write((string) json_encode($body));
        $request->getBody()->rewind();

        return $request->withParsedBody($body);
    }

    private function bearerGet(string $path, string $token): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withHeader('Authorization', 'Bearer ' . $token);
    }
}
