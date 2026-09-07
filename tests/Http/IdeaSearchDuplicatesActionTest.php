<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /{board}/ideas/search-duplicates (duplicate detection).
 *
 * All assertions run exclusively through the HTTP seam (AppFactory::create +
 * IntegrationTestCase). No direct access to repository internals.
 *
 * Covered ACs:
 *  AC1 — Happy path: a logged-in user finds a very similar existing idea.
 *  AC2 — Board scoping: an idea in a foreign board of the same account never surfaces.
 *  AC3 — Cross-tenant: an idea in a foreign account never surfaces.
 *  AC4 — AuthZ: anon → 401 (matches the trust level of the surrounding submit flow).
 *  AC5 — Unknown board slug → 404.
 *  AC6 — Too-short input (< 3 characters) → 200 with an empty candidate list, no errors.
 *  AC7 — Dissimilar titles are not returned (rerank threshold applies).
 */
final class IdeaSearchDuplicatesActionTest extends IntegrationTestCase
{
    private function searchRequest(string $boardSlug, string $title, ?int $userId = null): ServerRequestInterface
    {
        $query   = $title !== '' ? '?' . http_build_query(['title' => $title]) : '';
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $boardSlug . '/ideas/search-duplicates' . $query);

        if ($userId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
        }

        return $request;
    }

    // -------------------------------------------------------------------------
    // AC1 — Happy path
    // -------------------------------------------------------------------------

    public function test_finds_a_close_existing_idea(): void
    {
        $boardId = $this->insertBoard('demo');
        $author  = $this->insertUser('author@example.com');
        $userId  = $this->insertUser('searcher@example.com');
        $this->seedIdea($boardId, $author, 'Dark Mode for the Dashboard');

        $response = $this->createApp()->handle(
            $this->searchRequest('demo', 'Dark Mode for the Dashbord', $userId),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $data['candidates']);
        self::assertSame('Dark Mode for the Dashboard', $data['candidates'][0]['title']);
        self::assertArrayHasKey('id', $data['candidates'][0]);
        self::assertArrayHasKey('status', $data['candidates'][0]);
        self::assertArrayHasKey('up_count', $data['candidates'][0]);
        self::assertArrayHasKey('down_count', $data['candidates'][0]);
        self::assertArrayHasKey('similarity', $data['candidates'][0]);
    }

    public function test_unrelated_title_returns_no_candidates(): void
    {
        $boardId = $this->insertBoard('demo');
        $author  = $this->insertUser('author@example.com');
        $userId  = $this->insertUser('searcher@example.com');
        $this->seedIdea($boardId, $author, 'Export CSV button on the roadmap page');

        $response = $this->createApp()->handle(
            $this->searchRequest('demo', 'Dark Mode for the Dashboard', $userId),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([], $data['candidates']);
    }

    // -------------------------------------------------------------------------
    // AC2 — Board scoping (same account, different board)
    // -------------------------------------------------------------------------

    public function test_duplicate_in_another_board_never_surfaces(): void
    {
        $this->insertBoard('demo');
        $otherBoardId = $this->insertBoard('other-board');
        $author       = $this->insertUser('author@example.com');
        $userId       = $this->insertUser('searcher@example.com');
        $this->seedIdea($otherBoardId, $author, 'Dark Mode for the Dashboard');

        $response = $this->createApp()->handle(
            $this->searchRequest('demo', 'Dark Mode for the Dashboard', $userId),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([], $data['candidates']);
    }

    // -------------------------------------------------------------------------
    // AC3 — Cross-tenant (foreign account)
    // -------------------------------------------------------------------------

    public function test_duplicate_in_another_account_never_surfaces(): void
    {
        $this->insertBoard('demo');
        $foreignAccountId = $this->insertAccount(['slug' => 'foreign-acct']);
        $foreignBoardId   = $this->insertBoard('foreign-board', ['account_id' => $foreignAccountId]);
        $author           = $this->insertUser('author@example.com');
        $userId           = $this->insertUser('searcher@example.com');
        $this->seedIdea($foreignBoardId, $author, 'Dark Mode for the Dashboard');

        $response = $this->createApp()->handle(
            $this->searchRequest('demo', 'Dark Mode for the Dashboard', $userId),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([], $data['candidates']);
    }

    // -------------------------------------------------------------------------
    // AC4 — AuthZ
    // -------------------------------------------------------------------------

    public function test_anon_request_returns_401(): void
    {
        $this->insertBoard('demo');

        $response = $this->createApp()->handle(
            $this->searchRequest('demo', 'Dark Mode for the Dashboard'),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC5 — Unknown board slug
    // -------------------------------------------------------------------------

    public function test_unknown_board_slug_returns_404(): void
    {
        $userId = $this->insertUser('searcher@example.com');

        $response = $this->createApp()->handle(
            $this->searchRequest('does-not-exist', 'Dark Mode for the Dashboard', $userId),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC6 — Too-short input
    // -------------------------------------------------------------------------

    public function test_too_short_query_returns_empty_candidates_without_error(): void
    {
        $boardId = $this->insertBoard('demo');
        $author  = $this->insertUser('author@example.com');
        $userId  = $this->insertUser('searcher@example.com');
        $this->seedIdea($boardId, $author, 'ab');

        $response = $this->createApp()->handle(
            $this->searchRequest('demo', 'ab', $userId),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([], $data['candidates']);
    }
}
