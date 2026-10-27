<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Persistence\IdeaRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Free-text search on the public board — GET /{board}?q= (competitor-parity H6).
 *
 * Driven through the real HTTP seam on the SQLite test schema (substring
 * path); the MySQL FULLTEXT partitioning is unit-tested in IdeaSearchQueryTest.
 * Covers: title matching (AND over terms, case-insensitive), combination with
 * status filter / sort / pagination, the no-results state, input hardening,
 * board scoping and the cross-tenant leak (mandatory acceptance criterion).
 */
final class BoardSearchTest extends IntegrationTestCase
{
    /** @param array<string, mixed> $query */
    private function get(string $path, array $query = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withQueryParams($query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function fetch(string $path, array $query = []): array
    {
        $response = $this->createApp()->handle($this->get($path, $query));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function titles(array $data): array
    {
        self::assertIsList($data['ideas']);

        $titles = [];
        foreach ($data['ideas'] as $idea) {
            self::assertIsArray($idea);
            self::assertIsString($idea['title']);
            $titles[] = $idea['title'];
        }

        return $titles;
    }

    public function test_query_filters_ideas_by_title(): void
    {
        $boardId = $this->insertBoard('search-board');
        $author  = $this->insertUser();
        $this->seedIdea($boardId, $author, 'Dark mode for the dashboard');
        $this->seedIdea($boardId, $author, 'Export to CSV');

        $data = $this->fetch('/search-board', ['q' => 'dark']);

        self::assertSame(['Dark mode for the dashboard'], $this->titles($data));
        self::assertSame('dark', $data['active_query']);
    }

    public function test_without_query_active_query_is_null_and_all_ideas_listed(): void
    {
        $boardId = $this->insertBoard('search-board');
        $author  = $this->insertUser();
        $this->seedIdea($boardId, $author, 'Dark mode');
        $this->seedIdea($boardId, $author, 'Export to CSV');

        $data = $this->fetch('/search-board');

        self::assertNull($data['active_query']);
        self::assertCount(2, $data['ideas']);
    }

    public function test_every_term_must_match_case_insensitively(): void
    {
        $boardId = $this->insertBoard('search-board');
        $author  = $this->insertUser();
        $this->seedIdea($boardId, $author, 'Dark mode for the dashboard');
        $this->seedIdea($boardId, $author, 'Dark theme for emails');

        $data = $this->fetch('/search-board', ['q' => 'DASHBOARD dark']);

        self::assertSame(['Dark mode for the dashboard'], $this->titles($data));
    }

    public function test_no_match_returns_an_empty_list_not_an_error(): void
    {
        $boardId = $this->insertBoard('search-board');
        $this->seedIdea($boardId, $this->insertUser(), 'Dark mode');

        $data = $this->fetch('/search-board', ['q' => 'kanban']);

        self::assertSame([], $data['ideas']);
        self::assertSame(1, $data['total_pages']);
        self::assertSame('kanban', $data['active_query']);
    }

    public function test_query_combines_with_status_filter(): void
    {
        $boardId = $this->insertBoard('search-board');
        $author  = $this->insertUser();
        $this->seedIdea($boardId, $author, 'Dark mode (planned)', ['status' => 'planned']);
        $this->seedIdea($boardId, $author, 'Dark mode (open)');
        $this->seedIdea($boardId, $author, 'Export (planned)', ['status' => 'planned']);

        $data = $this->fetch('/search-board', ['q' => 'dark', 'status' => 'planned']);

        self::assertSame(['Dark mode (planned)'], $this->titles($data));
        self::assertSame('planned', $data['active_status']);
    }

    public function test_query_combines_with_sort(): void
    {
        $boardId = $this->insertBoard('search-board');
        $author  = $this->insertUser();
        $this->seedIdea($boardId, $author, 'Dark mode low', ['score_cache' => 1, 'created_at' => '2026-01-02 00:00:00']);
        $this->seedIdea($boardId, $author, 'Dark mode high', ['score_cache' => 9, 'created_at' => '2026-01-01 00:00:00']);
        $this->seedIdea($boardId, $author, 'Unrelated top', ['score_cache' => 50]);

        $top    = $this->fetch('/search-board', ['q' => 'dark', 'sort' => 'top']);
        $newest = $this->fetch('/search-board', ['q' => 'dark', 'sort' => 'newest']);

        self::assertSame(['Dark mode high', 'Dark mode low'], $this->titles($top));
        self::assertSame(['Dark mode low', 'Dark mode high'], $this->titles($newest));
    }

    public function test_pagination_counts_only_matching_ideas(): void
    {
        $boardId = $this->insertBoard('search-board');
        $author  = $this->insertUser();
        $pageSize = IdeaRepository::DEFAULT_PAGE_SIZE;
        for ($i = 0; $i <= $pageSize; ++$i) {
            $this->seedIdea($boardId, $author, 'Dark mode variant ' . $i);
        }
        for ($i = 0; $i < $pageSize * 2; ++$i) {
            $this->seedIdea($boardId, $author, 'Unrelated ' . $i);
        }

        $first  = $this->fetch('/search-board', ['q' => 'dark']);
        $second = $this->fetch('/search-board', ['q' => 'dark', 'page' => '2']);

        self::assertSame(2, $first['total_pages']);
        self::assertCount($pageSize, $first['ideas']);
        self::assertCount(1, $second['ideas']);
        self::assertSame(2, $second['total_pages']);
    }

    public function test_search_keeps_excluding_moderated_and_archived_ideas(): void
    {
        $boardId = $this->insertBoard('search-board');
        $author  = $this->insertUser();
        $this->seedIdea($boardId, $author, 'Dark mode visible');
        $this->seedIdea($boardId, $author, 'Dark mode archived', ['status' => 'archived']);
        $this->seedIdea($boardId, $author, 'Dark mode removed', ['deleted_at' => '2026-01-01 00:00:00']);

        self::assertSame(['Dark mode visible'], $this->titles($this->fetch('/search-board', ['q' => 'dark'])));
        self::assertSame(
            ['Dark mode archived'],
            $this->titles($this->fetch('/search-board', ['q' => 'dark', 'status' => 'archived'])),
        );
    }

    public function test_wildcard_only_or_non_string_query_means_no_search(): void
    {
        $boardId = $this->insertBoard('search-board');
        $author  = $this->insertUser();
        $this->seedIdea($boardId, $author, 'Dark mode');
        $this->seedIdea($boardId, $author, 'Export');

        $wildcards = $this->fetch('/search-board', ['q' => '%_%']);
        $array     = $this->fetch('/search-board', ['q' => ['dark']]);

        self::assertNull($wildcards['active_query']);
        self::assertCount(2, $wildcards['ideas']);
        self::assertNull($array['active_query']);
        self::assertCount(2, $array['ideas']);
    }

    public function test_like_wildcards_inside_a_query_are_not_pattern_syntax(): void
    {
        $boardId = $this->insertBoard('search-board');
        $author  = $this->insertUser();
        $this->seedIdea($boardId, $author, 'Dark mode');

        // "a%z" must not act as LIKE "a…z" — it is two terms "a" and "z".
        $data = $this->fetch('/search-board', ['q' => 'a%z']);

        self::assertSame([], $data['ideas']);
    }

    public function test_overlong_query_is_accepted_and_truncated(): void
    {
        $boardId = $this->insertBoard('search-board');
        $this->seedIdea($boardId, $this->insertUser(), 'Dark mode');

        $data = $this->fetch('/search-board', ['q' => 'dark ' . str_repeat('x', 5000)]);

        self::assertIsString($data['active_query']);
        self::assertLessThanOrEqual(100, mb_strlen($data['active_query']));
    }

    public function test_search_is_board_scoped(): void
    {
        $boardId = $this->insertBoard('search-board');
        $other   = $this->insertBoard('other-board');
        $author  = $this->insertUser();
        $this->seedIdea($boardId, $author, 'Dark mode here');
        $this->seedIdea($other, $author, 'Dark mode on the other board');

        self::assertSame(['Dark mode here'], $this->titles($this->fetch('/search-board', ['q' => 'dark'])));
    }

    public function test_search_never_leaks_ideas_of_a_foreign_account(): void
    {
        // Same board slug in two accounts: the request resolves the default
        // account, so a matching idea on the foreign account's board must
        // structurally never appear — neither in the list nor in the count.
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-foreign', 'name' => 'Foreign']);
        $foreignBoardId   = $this->insertBoard('shared-slug', ['account_id' => $foreignAccountId]);
        $ownBoardId       = $this->insertBoard('shared-slug', ['account_id' => $this->defaultAccountId()]);
        $author           = $this->insertUser();

        for ($i = 0; $i <= IdeaRepository::DEFAULT_PAGE_SIZE; ++$i) {
            $this->seedIdea($foreignBoardId, $author, 'Secret roadmap item ' . $i);
        }
        // Exactly one full page of own matches → the pagination count runs
        // and would report 3 pages if the foreign board's ideas leaked in.
        for ($i = 0; $i < IdeaRepository::DEFAULT_PAGE_SIZE; ++$i) {
            $this->seedIdea($ownBoardId, $author, 'Public roadmap item ' . $i);
        }

        $data = $this->fetch('/shared-slug', ['q' => 'roadmap']);

        self::assertCount(IdeaRepository::DEFAULT_PAGE_SIZE, $data['ideas']);
        foreach ($this->titles($data) as $title) {
            self::assertStringStartsWith('Public roadmap item', $title);
        }
        self::assertSame(1, $data['total_pages']);

        $secret = $this->fetch('/shared-slug', ['q' => 'secret']);
        self::assertSame([], $secret['ideas']);
    }
}
