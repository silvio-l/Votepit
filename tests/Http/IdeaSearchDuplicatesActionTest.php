<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\RecordingDuplicateConfirmer;
use Votepit\Tests\Support\StubExtension;

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
    private function searchRequest(string $boardSlug, string $title, ?int $userId = null, bool $confirm = false): ServerRequestInterface
    {
        $params  = $title !== '' ? ['title' => $title] : [];
        if ($confirm) {
            $params['confirm'] = '1';
        }
        $query   = $params !== [] ? '?' . http_build_query($params) : '';
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

    public function test_rephrased_duplicate_is_hinted_while_typing(): void
    {
        $boardId  = $this->insertBoard('demo');
        $author   = $this->insertUser('author@example.com');
        $userId   = $this->insertUser('searcher@example.com');
        $existing = $this->seedIdea($boardId, $author, 'Wir brauchen eine mobile App'); // export-ok: comment-language

        $data = json_decode((string) $this->createApp()->handle(
            $this->searchRequest('demo', 'Ich möchte eine mobile App haben.', $userId), // export-ok: comment-language
        )->getBody(), true);

        self::assertSame([$existing], array_column($data['candidates'], 'id'));
    }

    // -------------------------------------------------------------------------
    // Optional confirmation step (competitor-parity issue 11)
    // -------------------------------------------------------------------------

    public function test_confirm_flag_asks_the_confirmer_only_without_a_deterministic_match(): void
    {
        $boardId = $this->insertBoard('demo');
        $author  = $this->insertUser('author@example.com');
        $userId  = $this->insertUser('searcher@example.com');
        $match   = $this->seedIdea($boardId, $author, 'Dark Mode for the Dashboard');
        $related = $this->seedIdea($boardId, $author, 'Smartphone app for voting');
        $confirmer = new RecordingDuplicateConfirmer([$related]);
        $app       = $this->createApp(extensions: [new StubExtension(['duplicate_confirmer' => $confirmer])]);

        $data = json_decode((string) $app->handle($this->searchRequest('demo', 'Dark Mode for the Dashboard', $userId, confirm: true))->getBody(), true);
        self::assertSame([$match], array_column($data['candidates'], 'id'));
        self::assertSame([null], array_column($data['candidates'], 'confirmed'));
        self::assertCount(0, $confirmer->calls, 'A deterministic match costs no confirmation.');

        $data = json_decode((string) $app->handle($this->searchRequest('demo', 'Offline mode for the app', $userId, confirm: true))->getBody(), true);
        self::assertSame([$related], array_column($data['candidates'], 'id'));
        self::assertSame([true], array_column($data['candidates'], 'confirmed'));
        self::assertCount(1, $confirmer->calls);
        self::assertSame($this->defaultAccountId(), $confirmer->calls[0]['account']);
    }

    public function test_without_confirm_flag_the_confirmer_is_never_asked(): void
    {
        $boardId = $this->insertBoard('demo');
        $author  = $this->insertUser('author@example.com');
        $userId  = $this->insertUser('searcher@example.com');
        $this->seedIdea($boardId, $author, 'Dark Mode for the Dashboard');
        $confirmer = new RecordingDuplicateConfirmer([]);
        $app       = $this->createApp(extensions: [new StubExtension(['duplicate_confirmer' => $confirmer])]);

        $data = json_decode((string) $app->handle($this->searchRequest('demo', 'Dark Mode for the Dashboard', $userId))->getBody(), true);

        self::assertCount(1, $data['candidates']);
        self::assertNull($data['candidates'][0]['confirmed']);
        self::assertSame([], $confirmer->calls, 'As-you-type searches stay deterministic.');
    }

    public function test_confirmer_only_sees_published_ideas_of_this_board_and_account(): void
    {
        $boardId          = $this->insertBoard('demo');
        $otherBoardId     = $this->insertBoard('other-board');
        $foreignAccountId = $this->insertAccount(['slug' => 'foreign-acct']);
        $foreignBoardId   = $this->insertBoard('foreign-board', ['account_id' => $foreignAccountId]);
        $author           = $this->insertUser('author@example.com');
        $userId           = $this->insertUser('searcher@example.com');
        $own              = $this->seedIdea($boardId, $author, 'Smartphone app for voting');
        $this->seedIdea($boardId, $author, 'Smartphone apps for voting', ['moderation_state' => 'pending']);
        $this->seedIdea($otherBoardId, $author, 'Smartphone app for voting');
        $this->seedIdea($foreignBoardId, $author, 'Smartphone app for voting');
        $confirmer = new RecordingDuplicateConfirmer([]);
        $app       = $this->createApp(extensions: [new StubExtension(['duplicate_confirmer' => $confirmer])]);

        $app->handle($this->searchRequest('demo', 'Offline mode for the app', $userId, confirm: true));

        self::assertCount(1, $confirmer->calls);
        self::assertSame([['id' => $own, 'title' => 'Smartphone app for voting']], $confirmer->calls[0]['candidates']);
    }
}
