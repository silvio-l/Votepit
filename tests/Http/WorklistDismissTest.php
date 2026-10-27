<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Domain\Premoderation;
use Votepit\Persistence\IdeaRepository;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * P-06 part B: "Erledigt / ausblenden" of the operator worklist —
 * POST {accountPrefix}/admin/worklist/ideas/{id}/dismiss and .../undismiss.
 *
 * Covers the authz matrix (accountModerate), the rule for which ideas may be
 * hidden (unanswered or decision due, never pending), the activity rule that
 * ends a dismissal (new non-deleted comment after it, or a status change),
 * the effect on the counts, the list and the digest number, undo, and the
 * cross-tenant rules in cloud routing mode.
 */
final class WorklistDismissTest extends IntegrationTestCase
{
    /** @return App<null> */
    private function app(?string $routingMode = null): App
    {
        $config = $routingMode === null ? $this->testConfig() : Config::fromArray($this->testConfigArray(['routing_mode' => $routingMode]));

        return $this->createApp(config: $config);
    }

    /** POST with CSRF token and optional session; GET without CSRF. */
    private function request(string $method, string $path, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)->withHeader('Accept', 'application/json');
        if ($method !== 'POST') {
            return $userId === null ? $request : $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
        }

        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();
        $cookies = [$csrf->cookieName() => $csrf->sign($token)];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return $request->withCookieParams($cookies)->withHeader('X-CSRF-Token', $token)->withParsedBody([]);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    private function daysAgo(int $days): string
    {
        return $this->now()->modify("-{$days} days")->format('Y-m-d H:i:s');
    }

    /** @return array{account: int, owner: int, board: int} */
    private function accountOwner(string $slug = 'default'): array
    {
        $accountId = $slug === 'default' ? $this->defaultAccountId() : $this->insertAccount(['slug' => $slug]);
        $owner     = $this->insertUser("{$slug}-dismiss-owner@example.com");
        $this->insertAccountMember($accountId, $owner, 'owner');
        $board = $this->insertBoard("board-{$slug}", ['account_id' => $accountId]);

        return ['account' => $accountId, 'owner' => $owner, 'board' => $board];
    }

    /** An idea that waits for an answer: four days old, no member comment. */
    private function waitingIdea(int $boardId, int $authorId, string $title = 'Waiting idea'): int
    {
        return $this->seedIdea($boardId, $authorId, $title, ['created_at' => $this->daysAgo(4)]);
    }

    /** An idea that is decision due: four days old, 6 up and 4 down (10 votes, 10 voters' rule). */
    private function dueIdea(int $boardId, int $authorId, string $title = 'Due idea'): int
    {
        return $this->seedIdea($boardId, $authorId, $title, ['created_at' => $this->daysAgo(4), 'up_count' => 6, 'down_count' => 4]);
    }

    /** @return array<string, int> */
    private function counts(int $userId, string $path = '/admin/worklist/summary'): array
    {
        return $this->json($this->app()->handle($this->request('GET', $path, $userId)))['counts'];
    }

    public function test_anonymous_caller_gets_401(): void
    {
        $seed = $this->accountOwner();
        $idea = $this->waitingIdea($seed['board'], $seed['owner']);

        $response = $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", null));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $this->dismissalCount());
    }

    public function test_member_without_moderation_right_gets_403(): void
    {
        $seed   = $this->accountOwner();
        $idea   = $this->waitingIdea($seed['board'], $seed['owner']);
        $member = $this->insertUser('dismiss-member@example.com');
        $this->insertAccountMember($seed['account'], $member, 'member');

        $response = $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $member));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->dismissalCount());
    }

    public function test_owner_admin_and_moderator_may_dismiss(): void
    {
        $seed = $this->accountOwner();
        $app  = $this->app();

        foreach (['owner', 'admin', 'moderator'] as $role) {
            $user = $this->insertUser("dismiss-{$role}@example.com");
            $this->insertAccountMember($seed['account'], $user, $role);
            $idea = $this->waitingIdea($seed['board'], $seed['owner'], "For {$role}");

            $response = $app->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $user));

            self::assertSame(200, $response->getStatusCode(), $role);
            self::assertSame(['ok' => true, 'idea_id' => $idea, 'dismissed' => true], $this->json($response));
        }
    }

    public function test_dismissed_idea_leaves_counts_list_and_total(): void
    {
        $seed = $this->accountOwner();
        $idea = $this->waitingIdea($seed['board'], $seed['owner']);
        $this->waitingIdea($seed['board'], $seed['owner'], 'Stays waiting');

        $response = $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']));
        $list     = $this->json($this->app()->handle($this->request('GET', '/admin/worklist', $seed['owner'])));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->counts($seed['owner'])['unanswered']);
        self::assertSame(1, $this->counts($seed['owner'])['total']);
        self::assertSame(['Stays waiting'], array_column($list['ideas'], 'title'));
    }

    public function test_dismissed_decision_due_idea_leaves_the_decision_count(): void
    {
        $seed = $this->accountOwner();
        $due  = $this->dueIdea($seed['board'], $seed['owner']);
        self::assertSame(1, $this->counts($seed['owner'])['decision_due']);

        $response = $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$due}/dismiss", $seed['owner']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->counts($seed['owner'])['decision_due']);
        self::assertSame(0, $this->counts($seed['owner'])['total']);
    }

    public function test_pending_idea_cannot_be_dismissed(): void
    {
        $seed    = $this->accountOwner();
        $pending = $this->seedIdea($seed['board'], $seed['owner'], 'Pending', [
            'created_at'       => $this->daysAgo(9),
            'moderation_state' => Premoderation::STATE_PENDING,
        ]);

        $response = $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$pending}/dismiss", $seed['owner']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('not_dismissable', $this->json($response)['error']['key']);
        self::assertSame(0, $this->dismissalCount());
        self::assertSame(1, $this->counts($seed['owner'])['pending_moderation']);
    }

    public function test_fresh_idea_that_does_not_wait_yet_cannot_be_dismissed(): void
    {
        $seed  = $this->accountOwner();
        $fresh = $this->seedIdea($seed['board'], $seed['owner'], 'Fresh', ['created_at' => $this->daysAgo(1)]);

        $response = $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$fresh}/dismiss", $seed['owner']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->dismissalCount());
    }

    public function test_unknown_idea_gets_404(): void
    {
        $seed = $this->accountOwner();

        $response = $this->app()->handle($this->request('POST', '/admin/worklist/ideas/999999/dismiss', $seed['owner']));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_dismissing_twice_is_idempotent(): void
    {
        $seed = $this->accountOwner();
        $idea = $this->waitingIdea($seed['board'], $seed['owner']);
        $app  = $this->app();

        self::assertSame(200, $app->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']))->getStatusCode());
        self::assertSame(200, $app->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']))->getStatusCode());
        self::assertSame(1, $this->dismissalCount());
    }

    public function test_stored_row_holds_no_user_and_the_status_at_dismissal(): void
    {
        $seed = $this->accountOwner();
        $idea = $this->waitingIdea($seed['board'], $seed['owner']);

        $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']));
        $row = $this->conn->fetchAssociative('SELECT * FROM worklist_dismissals WHERE idea_id = :i', ['i' => $idea]);

        self::assertIsArray($row);
        self::assertSame(['account_id', 'idea_id', 'dismissed_at', 'status_at_dismissal'], array_keys($row));
        self::assertSame('open', $row['status_at_dismissal']);
    }

    public function test_new_comment_from_outside_the_account_brings_the_idea_back(): void
    {
        $seed        = $this->accountOwner();
        $idea        = $this->waitingIdea($seed['board'], $seed['owner']);
        $participant = $this->insertUser('dismiss-participant@example.com');
        $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']));
        self::assertSame(0, $this->counts($seed['owner'])['unanswered']);

        $this->seedComment($idea, $participant, 'Any news?', ['created_at' => $this->now()->modify('+1 minute')->format('Y-m-d H:i:s')]);

        self::assertSame(1, $this->counts($seed['owner'])['unanswered']);
    }

    public function test_comment_written_before_the_dismissal_does_not_bring_it_back(): void
    {
        $seed        = $this->accountOwner();
        $idea        = $this->waitingIdea($seed['board'], $seed['owner']);
        $participant = $this->insertUser('dismiss-earlier@example.com');
        $this->seedComment($idea, $participant, 'Asked before', ['created_at' => $this->daysAgo(2)]);

        $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']));

        self::assertSame(0, $this->counts($seed['owner'])['unanswered']);
    }

    public function test_soft_deleted_comment_after_the_dismissal_does_not_bring_it_back(): void
    {
        $seed        = $this->accountOwner();
        $idea        = $this->waitingIdea($seed['board'], $seed['owner']);
        $participant = $this->insertUser('dismiss-deleted@example.com');
        $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']));

        $this->seedComment($idea, $participant, 'Removed by moderation', [
            'created_at' => $this->now()->modify('+1 minute')->format('Y-m-d H:i:s'),
            'deleted_at' => $this->now()->modify('+1 minute')->format('Y-m-d H:i:s'),
        ]);

        self::assertSame(0, $this->counts($seed['owner'])['unanswered']);
    }

    public function test_status_change_brings_the_idea_back(): void
    {
        $seed = $this->accountOwner();
        $this->conn->insert('board_statuses', [
            'board_id'   => $seed['board'],
            'status_key' => 'review',
            'category'   => 'open',
            'label_en'   => 'In review',
            'position'   => 1,
        ]);
        $due = $this->dueIdea($seed['board'], $seed['owner']);
        $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$due}/dismiss", $seed['owner']));
        self::assertSame(0, $this->counts($seed['owner'])['decision_due']);

        $this->conn->update('ideas', ['status' => 'review'], ['id' => $due]);

        self::assertSame(1, $this->counts($seed['owner'])['decision_due']);
    }

    public function test_new_votes_do_not_end_a_dismissal(): void
    {
        $seed  = $this->accountOwner();
        $due   = $this->dueIdea($seed['board'], $seed['owner']);
        $voter = $this->insertUser('dismiss-voter@example.com');
        $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$due}/dismiss", $seed['owner']));

        $this->seedVote($due, $voter, 1, ['created_at' => $this->now()->modify('+1 minute')->format('Y-m-d H:i:s')]);

        self::assertSame(0, $this->counts($seed['owner'])['decision_due']);
    }

    public function test_votes_do_not_reactivate_a_dismissed_waiting_idea(): void
    {
        $seed  = $this->accountOwner();
        $idea  = $this->waitingIdea($seed['board'], $seed['owner']);
        $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']));
        $voter = $this->insertUser('dismiss-waiting-voter@example.com');

        $this->seedVote($idea, $voter, 1, ['created_at' => $this->now()->modify('+1 minute')->format('Y-m-d H:i:s')]);
        $this->conn->update('ideas', ['up_count' => 9, 'down_count' => 1], ['id' => $idea]);

        self::assertSame(0, $this->counts($seed['owner'])['unanswered']);
        self::assertSame(0, $this->counts($seed['owner'])['decision_due']);
    }

    public function test_dismissal_only_hides_and_does_not_close_voting(): void
    {
        $seed  = $this->accountOwner();
        $idea  = $this->waitingIdea($seed['board'], $seed['owner']);

        $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']));

        self::assertSame('open', $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$idea]));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_status_decisions WHERE idea_id = ?', [$idea]));
    }

    public function test_dismiss_on_a_frozen_board_is_rejected_and_writes_nothing(): void
    {
        $seed = $this->accountOwner();
        $this->conn->update('boards', ['frozen_at' => $this->now()->format('Y-m-d H:i:s')], ['id' => $seed['board']]);
        $idea = $this->waitingIdea($seed['board'], $seed['owner']);

        $response = $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame('board_frozen', $this->json($response)['error']['key']);
        self::assertSame(0, $this->dismissalCount());
    }

    public function test_dismiss_on_a_compliance_frozen_board_is_rejected(): void
    {
        $seed = $this->accountOwner();
        $this->conn->update('boards', ['compliance_frozen_at' => $this->now()->format('Y-m-d H:i:s')], ['id' => $seed['board']]);
        $idea = $this->waitingIdea($seed['board'], $seed['owner']);

        $response = $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame('board_frozen', $this->json($response)['error']['key']);
        self::assertSame(0, $this->dismissalCount());
    }

    public function test_undo_on_a_frozen_board_is_rejected_and_keeps_the_dismissal(): void
    {
        $seed = $this->accountOwner();
        $idea = $this->waitingIdea($seed['board'], $seed['owner']);
        $app  = $this->app();
        $app->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']));
        $this->conn->update('boards', ['frozen_at' => $this->now()->format('Y-m-d H:i:s')], ['id' => $seed['board']]);

        $response = $app->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/undismiss", $seed['owner']));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame('board_frozen', $this->json($response)['error']['key']);
        self::assertSame(1, $this->dismissalCount(), 'the dismissal stays in force until the board is unfrozen');
    }

    public function test_worklist_rows_flag_a_frozen_board_so_the_spa_hides_the_write_actions(): void
    {
        $seed = $this->accountOwner();
        $this->waitingIdea($seed['board'], $seed['owner'], 'Open board idea');
        $this->conn->update('boards', ['compliance_frozen_at' => $this->now()->format('Y-m-d H:i:s')], ['id' => $seed['board']]);

        $ideas = $this->json($this->app()->handle($this->request('GET', '/admin/worklist', $seed['owner'])))['ideas'];

        self::assertCount(1, $ideas);
        self::assertTrue($ideas[0]['board_frozen']);
    }

    public function test_undo_brings_the_idea_back(): void
    {
        $seed = $this->accountOwner();
        $idea = $this->waitingIdea($seed['board'], $seed['owner']);
        $app  = $this->app();
        $app->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']));

        $response = $app->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/undismiss", $seed['owner']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true, 'idea_id' => $idea, 'dismissed' => false], $this->json($response));
        self::assertSame(1, $this->counts($seed['owner'])['unanswered']);
        self::assertSame(0, $this->dismissalCount());
    }

    public function test_dismissal_leaves_the_weekly_digest_number(): void
    {
        $seed = $this->accountOwner();
        $idea = $this->waitingIdea($seed['board'], $seed['owner']);
        $repo = new IdeaRepository($this->conn);
        self::assertSame(1, $repo->countAwaitingAnswer($seed['board'], $this->now()));

        $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']));

        self::assertSame(0, $repo->countAwaitingAnswer($seed['board'], $this->now()));
    }

    public function test_export_data_lists_the_dismissals_of_the_account(): void
    {
        $seed = $this->accountOwner();
        $idea = $this->waitingIdea($seed['board'], $seed['owner']);
        $this->app()->handle($this->request('POST', "/admin/worklist/ideas/{$idea}/dismiss", $seed['owner']));

        $rows = (new IdeaRepository($this->conn))->listWorklistDismissalsForAccount($seed['account']);

        self::assertCount(1, $rows);
        self::assertSame($idea, (int) $rows[0]['idea_id']);
    }

    // -------------------------------------------------------------------------
    // Cloud routing mode: the {account} segment is the tenant boundary.
    // -------------------------------------------------------------------------

    public function test_cloud_foreign_idea_gets_404_and_stays_listed_for_its_owner(): void
    {
        $alpha = $this->accountOwner('alpha');
        $beta  = $this->accountOwner('beta');
        $betaIdea = $this->waitingIdea($beta['board'], $beta['owner'], 'Beta idea');
        $moderator = $this->insertUser('dismiss-alpha-mod@example.com');
        $this->insertAccountMember($alpha['account'], $moderator, 'moderator');

        $response = $this->app('cloud')->handle($this->request('POST', "/alpha/admin/worklist/ideas/{$betaIdea}/dismiss", $moderator));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->dismissalCount());
        $betaCounts = $this->json($this->app('cloud')->handle($this->request('GET', '/beta/admin/worklist/summary', $beta['owner'])))['counts'];
        self::assertSame(1, $betaCounts['unanswered']);
    }

    public function test_cloud_frozen_foreign_idea_gets_404_not_423(): void
    {
        $alpha = $this->accountOwner('alpha-f');
        $beta  = $this->accountOwner('beta-f');
        $this->conn->update('boards', ['frozen_at' => $this->now()->format('Y-m-d H:i:s')], ['id' => $beta['board']]);
        $betaIdea  = $this->waitingIdea($beta['board'], $beta['owner']);
        $moderator = $this->insertUser('dismiss-frozen-alpha-mod@example.com');
        $this->insertAccountMember($alpha['account'], $moderator, 'moderator');

        $dismiss = $this->app('cloud')->handle($this->request('POST', "/alpha-f/admin/worklist/ideas/{$betaIdea}/dismiss", $moderator));
        $undo    = $this->app('cloud')->handle($this->request('POST', "/alpha-f/admin/worklist/ideas/{$betaIdea}/undismiss", $moderator));

        self::assertSame(404, $dismiss->getStatusCode());
        self::assertSame(404, $undo->getStatusCode());
        self::assertSame(0, $this->dismissalCount());
    }

    public function test_cloud_moderator_of_another_account_gets_403(): void
    {
        $alpha = $this->accountOwner('alpha-d');
        $beta  = $this->accountOwner('beta-d');
        $idea  = $this->waitingIdea($alpha['board'], $alpha['owner']);
        $intruder = $this->insertUser('dismiss-beta-mod@example.com');
        $this->insertAccountMember($beta['account'], $intruder, 'moderator');

        $response = $this->app('cloud')->handle($this->request('POST', "/alpha-d/admin/worklist/ideas/{$idea}/dismiss", $intruder));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->dismissalCount());
    }

    public function test_cloud_undismiss_of_a_foreign_idea_gets_404(): void
    {
        $alpha = $this->accountOwner('alpha-u');
        $beta  = $this->accountOwner('beta-u');
        $betaIdea = $this->waitingIdea($beta['board'], $beta['owner']);
        $this->app('cloud')->handle($this->request('POST', "/beta-u/admin/worklist/ideas/{$betaIdea}/dismiss", $beta['owner']));
        $moderator = $this->insertUser('dismiss-undo-alpha@example.com');
        $this->insertAccountMember($alpha['account'], $moderator, 'moderator');

        $response = $this->app('cloud')->handle($this->request('POST', "/alpha-u/admin/worklist/ideas/{$betaIdea}/undismiss", $moderator));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->dismissalCount(), 'the foreign dismissal is untouched');
    }

    public function test_cloud_private_board_of_the_same_account_can_be_dismissed_by_a_moderator(): void
    {
        $alpha   = $this->accountOwner('alpha-p');
        $private = $this->insertBoard('board-alpha-p-private', ['account_id' => $alpha['account'], 'visibility' => 'private']);
        $idea    = $this->waitingIdea($private, $alpha['owner']);
        $moderator = $this->insertUser('dismiss-private-mod@example.com');
        $this->insertAccountMember($alpha['account'], $moderator, 'moderator');

        $response = $this->app('cloud')->handle($this->request('POST', "/alpha-p/admin/worklist/ideas/{$idea}/dismiss", $moderator));

        self::assertSame(200, $response->getStatusCode());
    }

    private function dismissalCount(): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM worklist_dismissals');
    }
}
