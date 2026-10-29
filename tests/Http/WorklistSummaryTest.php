<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Domain\Premoderation;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * P-06 slice 01: GET {accountPrefix}/admin/worklist/summary — the header counts of
 * the operator worklist. Gate accountModerate (owner|admin|moderator), the
 * optional board filter, and the cross-tenant rules in cloud routing mode.
 */
final class WorklistSummaryTest extends IntegrationTestCase
{
    /** @return App<null> */
    private function app(?string $routingMode = null): App
    {
        $config = $routingMode === null ? $this->testConfig() : Config::fromArray($this->testConfigArray(['routing_mode' => $routingMode]));

        return $this->createApp(config: $config);
    }

    private function get(string $path, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path)->withHeader('Accept', 'application/json');

        return $userId === null ? $request : $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    /** Ideas that wait for an answer: four days old, published, no member comment. */
    private function waitingIdea(int $boardId, int $authorId, string $title): int
    {
        return $this->seedIdea($boardId, $authorId, $title, ['created_at' => (new \DateTimeImmutable())->modify('-4 days')->format('Y-m-d H:i:s')]);
    }

    /** @return array{board: int, owner: int} */
    private function accountOwner(string $slug = 'default'): array
    {
        $accountId = $slug === 'default' ? $this->defaultAccountId() : $this->insertAccount(['slug' => $slug]);
        $owner     = $this->insertUser("{$slug}-owner@example.com");
        $this->insertAccountMember($accountId, $owner, 'owner');
        $board = $this->insertBoard("board-{$slug}", ['account_id' => $accountId]);

        return ['board' => $board, 'owner' => $owner];
    }

    public function test_anonymous_caller_gets_401(): void
    {
        $this->accountOwner();

        $response = $this->app()->handle($this->get('/admin/worklist/summary', null));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_member_without_moderation_right_gets_403(): void
    {
        $seed   = $this->accountOwner();
        $member = $this->insertUser('summary-member@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $member, 'member');
        $this->waitingIdea($seed['board'], $seed['owner'], 'Waiting');

        $response = $this->app()->handle($this->get('/admin/worklist/summary', $member));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_owner_admin_and_moderator_get_the_counts(): void
    {
        $seed = $this->accountOwner();
        $this->waitingIdea($seed['board'], $seed['owner'], 'Waiting');
        $app = $this->app();

        foreach (['owner', 'admin', 'moderator'] as $role) {
            $user = $this->insertUser("summary-{$role}@example.com");
            $this->insertAccountMember($this->defaultAccountId(), $user, $role);

            $response = $app->handle($this->get('/admin/worklist/summary', $user));

            self::assertSame(200, $response->getStatusCode(), $role);
            self::assertSame(1, $this->json($response)['counts']['unanswered'], $role);
        }
    }

    public function test_response_exposes_only_counts_and_the_board_filter_echo(): void
    {
        $seed = $this->accountOwner();
        $this->waitingIdea($seed['board'], $seed['owner'], 'Waiting');

        $response = $this->app()->handle($this->get('/admin/worklist/summary', $seed['owner']));
        $data     = $this->json($response);

        self::assertSame(['counts', 'board'], array_keys($data));
        self::assertSame(['unanswered', 'unanswered_over_7_days', 'pending_moderation', 'decision_due', 'total'], array_keys($data['counts']));
        self::assertNull($data['board']);
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function test_total_counts_each_idea_once_across_overlapping_categories(): void
    {
        $seed = $this->accountOwner();
        $both = $this->waitingIdea($seed['board'], $seed['owner'], 'Waiting and due');
        $this->conn->update('ideas', ['up_count' => 6, 'down_count' => 4], ['id' => $both]);
        $this->seedIdea($seed['board'], $seed['owner'], 'Pending', ['moderation_state' => Premoderation::STATE_PENDING]);

        $counts = $this->json($this->app()->handle($this->get('/admin/worklist/summary', $seed['owner'])))['counts'];

        self::assertSame(1, $counts['unanswered']);
        self::assertSame(1, $counts['decision_due']);
        self::assertSame(1, $counts['pending_moderation']);
        self::assertSame(2, $counts['total'], 'the idea that is waiting and due counts once');
    }

    public function test_board_filter_narrows_the_counts_to_that_board(): void
    {
        $seed  = $this->accountOwner();
        $other = $this->insertBoard('board-second', ['account_id' => $this->defaultAccountId()]);
        $this->waitingIdea($seed['board'], $seed['owner'], 'First board');
        $this->waitingIdea($other, $seed['owner'], 'Second board');
        $this->seedIdea($other, $seed['owner'], 'Pending second', ['moderation_state' => Premoderation::STATE_PENDING]);

        $all    = $this->json($this->app()->handle($this->get('/admin/worklist/summary', $seed['owner'])));
        $second = $this->json($this->app()->handle($this->get('/admin/worklist/summary?board=board-second', $seed['owner'])));

        self::assertSame(2, $all['counts']['unanswered']);
        self::assertSame(1, $second['counts']['unanswered']);
        self::assertSame(1, $second['counts']['pending_moderation']);
        self::assertSame('board-second', $second['board']);
    }

    public function test_unknown_board_gets_404(): void
    {
        $seed = $this->accountOwner();

        $response = $this->app()->handle($this->get('/admin/worklist/summary?board=no-such-board', $seed['owner']));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_unknown_parameter_gets_422(): void
    {
        $seed = $this->accountOwner();

        $response = $this->app()->handle($this->get('/admin/worklist/summary?sort=whatever', $seed['owner']));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_private_board_counts_for_a_moderator_of_the_account(): void
    {
        $seed    = $this->accountOwner();
        $private = $this->insertBoard('board-private', ['account_id' => $this->defaultAccountId(), 'visibility' => 'private']);
        $this->waitingIdea($private, $seed['owner'], 'Secret waiting');
        $moderator = $this->insertUser('summary-private-mod@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $moderator, 'moderator');

        $response = $this->app()->handle($this->get('/admin/worklist/summary', $moderator));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->json($response)['counts']['unanswered']);
    }

    // -------------------------------------------------------------------------
    // Cloud routing mode: the {account} segment is the tenant boundary.
    // -------------------------------------------------------------------------

    public function test_cloud_counts_never_include_another_account(): void
    {
        $alpha = $this->accountOwner('alpha');
        $beta  = $this->accountOwner('beta');
        $this->waitingIdea($alpha['board'], $alpha['owner'], 'Alpha waiting');
        $this->waitingIdea($beta['board'], $beta['owner'], 'Beta waiting');
        $this->seedIdea($beta['board'], $beta['owner'], 'Beta pending', ['moderation_state' => Premoderation::STATE_PENDING]);

        $data = $this->json($this->app('cloud')->handle($this->get('/alpha/admin/worklist/summary', $alpha['owner'])));

        self::assertSame(1, $data['counts']['unanswered']);
        self::assertSame(0, $data['counts']['pending_moderation']);
    }

    public function test_cloud_moderator_of_another_account_gets_403(): void
    {
        $alpha = $this->accountOwner('alpha-x');
        $beta  = $this->accountOwner('beta-x');
        $this->waitingIdea($alpha['board'], $alpha['owner'], 'Alpha waiting');

        $response = $this->app('cloud')->handle($this->get('/alpha-x/admin/worklist/summary', $beta['owner']));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_cloud_board_of_a_foreign_account_in_the_filter_gets_404(): void
    {
        $alpha = $this->accountOwner('alpha-f');
        $this->accountOwner('beta-f');

        $response = $this->app('cloud')->handle($this->get('/alpha-f/admin/worklist/summary?board=board-beta-f', $alpha['owner']));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_cloud_unknown_account_slug_gets_404(): void
    {
        $response = $this->app('cloud')->handle($this->get('/no-such-account/admin/worklist/summary', null));

        self::assertSame(404, $response->getStatusCode());
    }
}
