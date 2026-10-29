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
 * P-06 slice 02: GET {accountPrefix}/admin/worklist — the rows of the operator
 * worklist with category, board, age filter, sort, cursor pagination (50),
 * the payload allow-list (no PII) and the cross-tenant rules.
 */
final class WorklistListTest extends IntegrationTestCase
{
    /** Keys a row may carry (P-06 Data section). Anything else is a leak candidate. */
    private const ROW_KEYS = ['id', 'title', 'board_slug', 'board_name', 'status', 'status_category', 'status_is_system', 'status_label_de', 'status_label_en', 'moderation_state', 'up_count', 'down_count', 'voter_count', 'created_at', 'age_days', 'categories', 'author_type', 'board_frozen'];

    private const CATEGORY_KEYS = ['unanswered', 'pending_moderation', 'decision_due'];

    private const COUNT_KEYS = ['unanswered', 'unanswered_over_7_days', 'pending_moderation', 'decision_due', 'total'];

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

    /** @return list<int> */
    private function ids(ResponseInterface $response): array
    {
        return array_values(array_map(static fn (array $row): int => (int) $row['id'], $this->json($response)['ideas']));
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    private function daysAgo(float $days): string
    {
        return $this->now()->modify('-' . (int) round($days * 24 * 60) . ' minutes')->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $overrides */
    private function idea(int $boardId, int $authorId, string $title, float $daysOld = 4, array $overrides = []): int
    {
        return $this->seedIdea($boardId, $authorId, $title, array_merge(['created_at' => $this->daysAgo($daysOld)], $overrides));
    }

    /**
     * Owner of the default account with one board; returns [board, owner, moderator].
     *
     * @return array{board: int, owner: int, moderator: int}
     */
    private function defaultAccountSeed(): array
    {
        $accountId = $this->defaultAccountId();
        $owner     = $this->insertUser('list-owner@example.com');
        $moderator = $this->insertUser('list-mod@example.com');
        $this->insertAccountMember($accountId, $owner, 'owner');
        $this->insertAccountMember($accountId, $moderator, 'moderator');
        $board = $this->insertBoard('list-main', ['account_id' => $accountId]);

        return ['board' => $board, 'owner' => $owner, 'moderator' => $moderator];
    }

    /**
     * Every array key of a decoded payload, at any depth.
     *
     * @return list<string>
     */
    private function collectKeys(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $keys = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            array_push($keys, ...$this->collectKeys($item));
        }

        return $keys;
    }

    // -------------------------------------------------------------------------
    // Access
    // -------------------------------------------------------------------------

    public function test_anonymous_gets_401_and_a_plain_member_gets_403(): void
    {
        $seed   = $this->defaultAccountSeed();
        $member = $this->insertUser('list-member@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $member, 'member');
        $this->idea($seed['board'], $seed['owner'], 'Waiting');

        self::assertSame(401, $this->app()->handle($this->get('/admin/worklist', null))->getStatusCode());
        self::assertSame(403, $this->app()->handle($this->get('/admin/worklist', $member))->getStatusCode());
    }

    public function test_response_is_not_cacheable(): void
    {
        $seed = $this->defaultAccountSeed();

        $response = $this->app()->handle($this->get('/admin/worklist', $seed['moderator']));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    // -------------------------------------------------------------------------
    // Payload
    // -------------------------------------------------------------------------

    public function test_rows_carry_only_the_documented_fields_and_no_pii(): void
    {
        $seed   = $this->defaultAccountSeed();
        $author = $this->insertUser('list-pii-author@example.com');
        $this->idea($seed['board'], $author, 'Waiting with an author');

        $response = $this->app()->handle($this->get('/admin/worklist', $seed['owner']));
        $data     = $this->json($response);

        self::assertSame(['counts', 'board', 'ideas', 'next_cursor'], array_keys($data));
        self::assertSame(self::COUNT_KEYS, array_keys($data['counts']));
        self::assertCount(1, $data['ideas']);
        self::assertSame(self::ROW_KEYS, array_keys($data['ideas'][0]));
        self::assertSame(self::CATEGORY_KEYS, array_keys($data['ideas'][0]['categories']));

        $allKeys = $this->collectKeys($data);
        foreach (['author_id', 'author', 'email', 'email_hmac', 'user_id', 'ip', 'guest_token', 'public_id'] as $forbidden) {
            self::assertNotContains($forbidden, $allKeys);
        }
        self::assertStringNotContainsString('list-pii-author@example.com', (string) $response->getBody());
        self::assertSame('Waiting with an author', $data['ideas'][0]['title']);
        self::assertSame('list-main', $data['ideas'][0]['board_slug']);
        self::assertSame(4, $data['ideas'][0]['age_days']);
    }

    public function test_author_type_is_reported_for_pending_rows_only(): void
    {
        $seed  = $this->defaultAccountSeed();
        $guest = $this->insertUser('placeholder-guest@example.com', ['email_hmac' => 'placeholder:guest-author', 'is_guest_placeholder' => 1]);
        $this->idea($seed['board'], $guest, 'Guest pending', 1, ['moderation_state' => Premoderation::STATE_PENDING]);
        $this->idea($seed['board'], $seed['owner'], 'Member pending', 1, ['moderation_state' => Premoderation::STATE_PENDING]);
        $this->idea($seed['board'], $seed['owner'], 'Waiting');

        $rows = [];
        foreach ($this->json($this->app()->handle($this->get('/admin/worklist', $seed['owner'])))['ideas'] as $row) {
            $rows[$row['title']] = $row;
        }

        self::assertSame('guest', $rows['Guest pending']['author_type']);
        self::assertSame('member', $rows['Member pending']['author_type']);
        self::assertNull($rows['Waiting']['author_type']);
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    public function test_category_filter_narrows_the_rows(): void
    {
        $seed = $this->defaultAccountSeed();
        $waiting = $this->idea($seed['board'], $seed['owner'], 'Waiting', 4);
        $pending = $this->idea($seed['board'], $seed['owner'], 'Pending', 1, ['moderation_state' => Premoderation::STATE_PENDING]);
        $due     = $this->idea($seed['board'], $seed['owner'], 'Due', 1);
        $this->conn->update('ideas', ['up_count' => 6, 'down_count' => 4], ['id' => $due]);
        $app = $this->app();

        self::assertSame([$waiting], $this->ids($app->handle($this->get('/admin/worklist?category=unanswered', $seed['owner']))));
        self::assertSame([$pending], $this->ids($app->handle($this->get('/admin/worklist?category=pending_moderation', $seed['owner']))));
        self::assertSame([$due], $this->ids($app->handle($this->get('/admin/worklist?category=decision_due', $seed['owner']))));
    }

    public function test_all_lists_an_idea_once_with_a_flag_per_category(): void
    {
        $seed = $this->defaultAccountSeed();
        $both = $this->idea($seed['board'], $seed['owner'], 'Waiting and due', 4);
        $this->conn->update('ideas', ['up_count' => 6, 'down_count' => 4], ['id' => $both]);

        $data = $this->json($this->app()->handle($this->get('/admin/worklist', $seed['owner'])));

        self::assertSame([$both], array_map(static fn (array $r): int => (int) $r['id'], $data['ideas']));
        self::assertSame(['unanswered' => true, 'pending_moderation' => false, 'decision_due' => true], $data['ideas'][0]['categories']);
        self::assertSame(10, $data['ideas'][0]['voter_count']);
    }

    public function test_age_filter_over_7_days_keeps_only_older_waits(): void
    {
        $seed = $this->defaultAccountSeed();
        $young = $this->idea($seed['board'], $seed['owner'], 'Four days', 4);
        $old   = $this->idea($seed['board'], $seed['owner'], 'Eight days', 8);
        $app   = $this->app();

        self::assertSame([$old, $young], $this->ids($app->handle($this->get('/admin/worklist', $seed['owner']))));
        self::assertSame([$old], $this->ids($app->handle($this->get('/admin/worklist?age=over_7_days', $seed['owner']))));
    }

    public function test_board_filter_limits_rows_and_counts_to_that_board(): void
    {
        $seed  = $this->defaultAccountSeed();
        $other = $this->insertBoard('list-other', ['account_id' => $this->defaultAccountId()]);
        $first = $this->idea($seed['board'], $seed['owner'], 'Main');
        $this->idea($other, $seed['owner'], 'Other');

        $data = $this->json($this->app()->handle($this->get('/admin/worklist?board=list-main', $seed['owner'])));

        self::assertSame([$first], array_map(static fn (array $r): int => (int) $r['id'], $data['ideas']));
        self::assertSame(1, $data['counts']['unanswered']);
        self::assertSame('list-main', $data['board']);
    }

    public function test_default_sort_is_oldest_waiting_first_and_votes_sorts_by_votes(): void
    {
        $seed   = $this->defaultAccountSeed();
        $oldest = $this->idea($seed['board'], $seed['owner'], 'Ten days, no votes', 10);
        $voted  = $this->idea($seed['board'], $seed['owner'], 'Five days, twelve votes', 5);
        $this->conn->update('ideas', ['up_count' => 7, 'down_count' => 5], ['id' => $voted]);
        $newest = $this->idea($seed['board'], $seed['owner'], 'Four days, no votes', 4);
        $app = $this->app();

        self::assertSame([$oldest, $voted, $newest], $this->ids($app->handle($this->get('/admin/worklist', $seed['owner']))));
        self::assertSame([$voted, $oldest, $newest], $this->ids($app->handle($this->get('/admin/worklist?sort=votes', $seed['owner']))));
    }

    public function test_unknown_parameters_and_values_get_422(): void
    {
        $seed = $this->defaultAccountSeed();
        $app  = $this->app();

        foreach (['?category=bogus', '?age=bogus', '?sort=bogus', '?cursor=not-a-cursor', '?limit=500', '?foo=1', '?board='] as $query) {
            self::assertSame(422, $app->handle($this->get('/admin/worklist' . $query, $seed['owner']))->getStatusCode(), $query);
        }
    }

    public function test_unknown_board_gets_404(): void
    {
        $seed = $this->defaultAccountSeed();

        self::assertSame(404, $this->app()->handle($this->get('/admin/worklist?board=no-such', $seed['owner']))->getStatusCode());
    }

    public function test_row_carries_the_board_status_of_a_default_board(): void
    {
        $seed = $this->defaultAccountSeed();
        $this->idea($seed['board'], $seed['owner'], 'Default status');

        $row = $this->json($this->app()->handle($this->get('/admin/worklist', $seed['owner'])))['ideas'][0];

        self::assertSame('open', $row['status']);
        self::assertSame('open', $row['status_category']);
        self::assertTrue($row['status_is_system']);
        self::assertNull($row['status_label_de']);
        self::assertNull($row['status_label_en']);
    }

    public function test_row_carries_the_custom_status_key_category_and_labels(): void
    {
        $seed = $this->defaultAccountSeed();
        $this->conn->insert('board_statuses', [
            'board_id' => $seed['board'], 'status_key' => 's1a', 'category' => 'open',
            'label_de' => 'Eingegangen', 'label_en' => 'Received', 'position' => 10, 'is_system' => 0,
        ]);
        $this->idea($seed['board'], $seed['owner'], 'Custom status', 4, ['status' => 's1a']);

        $row = $this->json($this->app()->handle($this->get('/admin/worklist', $seed['owner'])))['ideas'][0];

        self::assertSame('s1a', $row['status']);
        self::assertSame('open', $row['status_category']);
        self::assertFalse($row['status_is_system']);
        self::assertSame('Eingegangen', $row['status_label_de']);
        self::assertSame('Received', $row['status_label_en']);
    }

    // -------------------------------------------------------------------------
    // Pagination
    // -------------------------------------------------------------------------

    public function test_pages_of_fifty_with_a_cursor_that_survives_new_inserts(): void
    {
        $seed = $this->defaultAccountSeed();
        for ($i = 0; $i < 120; $i++) {
            // Distinct, strictly increasing creation times, all older than the waiting cut-off.
            $this->idea($seed['board'], $seed['owner'], "Waiting {$i}", 4 + ($i / 1000));
        }
        $app = $this->app();

        $first = $this->json($app->handle($this->get('/admin/worklist', $seed['owner'])));
        self::assertCount(50, $first['ideas']);
        self::assertIsString($first['next_cursor']);

        $second = $this->json($app->handle($this->get('/admin/worklist?cursor=' . urlencode($first['next_cursor']), $seed['owner'])));
        self::assertCount(50, $second['ideas']);
        self::assertIsString($second['next_cursor']);
        $secondIds = array_map(static fn (array $r): int => (int) $r['id'], $second['ideas']);

        // A waiting idea older than everything on the list lands before the cursor and must not shift page two.
        $this->idea($seed['board'], $seed['owner'], 'Inserted before the cursor', 30);
        $again = $this->json($app->handle($this->get('/admin/worklist?cursor=' . urlencode($first['next_cursor']), $seed['owner'])));
        self::assertSame($secondIds, array_map(static fn (array $r): int => (int) $r['id'], $again['ideas']));

        $third = $this->json($app->handle($this->get('/admin/worklist?cursor=' . urlencode($second['next_cursor']), $seed['owner'])));
        self::assertCount(20, $third['ideas']);
        self::assertNull($third['next_cursor']);

        $all = array_merge(
            array_map(static fn (array $r): int => (int) $r['id'], $first['ideas']),
            $secondIds,
            array_map(static fn (array $r): int => (int) $r['id'], $third['ideas']),
        );
        self::assertCount(120, array_unique($all));
    }

    // -------------------------------------------------------------------------
    // Cross-tenant and visibility
    // -------------------------------------------------------------------------

    public function test_private_board_rows_are_visible_to_moderators_only(): void
    {
        $seed    = $this->defaultAccountSeed();
        $private = $this->insertBoard('list-private', ['account_id' => $this->defaultAccountId(), 'visibility' => 'private']);
        $secret  = $this->idea($private, $seed['owner'], 'Private waiting');
        $member  = $this->insertUser('list-private-member@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $member, 'member');
        $app = $this->app();

        self::assertSame([$secret], $this->ids($app->handle($this->get('/admin/worklist', $seed['moderator']))));
        self::assertSame(403, $app->handle($this->get('/admin/worklist', $member))->getStatusCode());
    }

    public function test_cloud_list_never_contains_rows_of_another_account(): void
    {
        $alpha = $this->insertAccount(['slug' => 'alpha-list']);
        $beta  = $this->insertAccount(['slug' => 'beta-list']);
        $alphaOwner = $this->insertUser('alpha-list-owner@example.com');
        $betaOwner  = $this->insertUser('beta-list-owner@example.com');
        $this->insertAccountMember($alpha, $alphaOwner, 'owner');
        $this->insertAccountMember($beta, $betaOwner, 'owner');
        $alphaBoard = $this->insertBoard('alpha-board', ['account_id' => $alpha]);
        $betaBoard  = $this->insertBoard('beta-board', ['account_id' => $beta]);
        $mine = $this->idea($alphaBoard, $alphaOwner, 'Alpha waiting');
        $this->idea($betaBoard, $betaOwner, 'Beta waiting');
        $this->seedIdea($betaBoard, $betaOwner, 'Beta pending', ['moderation_state' => Premoderation::STATE_PENDING]);
        $app = $this->app('cloud');

        $data = $this->json($app->handle($this->get('/alpha-list/admin/worklist', $alphaOwner)));

        self::assertSame([$mine], array_map(static fn (array $r): int => (int) $r['id'], $data['ideas']));
        self::assertSame(0, $data['counts']['pending_moderation']);
        self::assertSame(1, $data['counts']['unanswered']);
    }

    public function test_cloud_foreign_board_in_the_filter_gets_404_and_a_foreign_moderator_403(): void
    {
        $alpha = $this->insertAccount(['slug' => 'alpha-x2']);
        $beta  = $this->insertAccount(['slug' => 'beta-x2']);
        $alphaOwner = $this->insertUser('alpha-x2-owner@example.com');
        $betaMod    = $this->insertUser('beta-x2-mod@example.com');
        $this->insertAccountMember($alpha, $alphaOwner, 'owner');
        $this->insertAccountMember($beta, $betaMod, 'moderator');
        $this->insertBoard('alpha-x2-board', ['account_id' => $alpha]);
        $this->insertBoard('beta-x2-board', ['account_id' => $beta]);
        $app = $this->app('cloud');

        self::assertSame(404, $app->handle($this->get('/alpha-x2/admin/worklist?board=beta-x2-board', $alphaOwner))->getStatusCode());
        self::assertSame(403, $app->handle($this->get('/alpha-x2/admin/worklist', $betaMod))->getStatusCode());
    }
}
