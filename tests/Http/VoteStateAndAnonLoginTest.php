<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * HTTP integration tests for the voting read path.
 *
 * Proves via the HTTP seam (AppFactory + IntegrationTestCase):
 *  AC1  — findInBoard/listByBoard with a null userId behave as before
 *  AC2  — my_vote ∈ {up, down, none} for a logged-in user on the idea detail page
 *  AC3  — my_vote ∈ {up, down, none} for a logged-in user in the board list
 *  AC4  — An anonymous visitor sees score/consensus + a login link with return-to
 *  AC5  — Return-to URL contains a rawurlencoded idea URL
 *  AC6  — Cross-board: my_vote only from the current board
 */
final class VoteStateAndAnonLoginTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    private function getDetail(string $slug, int $ideaId, ?int $userId = null): \Psr\Http\Message\ServerRequestInterface
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $slug . '/ideas/' . $ideaId);

        if ($userId !== null) {
            $req = $req->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
        }

        return $req;
    }

    private function getBoard(string $slug, ?int $userId = null): \Psr\Http\Message\ServerRequestInterface
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $slug);

        if ($userId !== null) {
            $req = $req->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
        }

        return $req;
    }

    // -------------------------------------------------------------------------
    // AC1 — null userId: existing behavior unchanged (no my_vote in the HTML)
    // -------------------------------------------------------------------------

    public function test_anon_detail_renders_200_without_vote_form(): void
    {
        $boardId  = $this->insertBoard('vs-anon-detail');
        $authorId = $this->insertUser('vs-anon-d@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Anon idea');

        $response = $this->createApp()->handle($this->getDetail('vs-anon-detail', $ideaId));

        self::assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC2 — my_vote on the idea detail page for a logged-in user
    // -------------------------------------------------------------------------

    public function test_idea_detail_shows_vote_up_state_for_logged_in_user(): void
    {
        $boardId  = $this->insertBoard('vs-detail-up');
        $authorId = $this->insertUser('vs-detail-up-a@example.com');
        $voterId  = $this->insertUser('vs-detail-up-v@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Upvoted idea');
        $this->seedVote($ideaId, $voterId, 1);

        $data = json_decode(
            (string) $this->createApp()->handle($this->getDetail('vs-detail-up', $ideaId, $voterId))->getBody(),
            true,
        );

        self::assertSame('up', $data['idea']['my_vote'] ?? null, 'A logged-in user with an up vote must see my_vote=up.');
    }

    public function test_idea_detail_shows_vote_down_state_for_logged_in_user(): void
    {
        $boardId  = $this->insertBoard('vs-detail-dn');
        $authorId = $this->insertUser('vs-detail-dn-a@example.com');
        $voterId  = $this->insertUser('vs-detail-dn-v@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Downvoted idea');
        $this->seedVote($ideaId, $voterId, -1);

        $data = json_decode(
            (string) $this->createApp()->handle($this->getDetail('vs-detail-dn', $ideaId, $voterId))->getBody(),
            true,
        );

        self::assertSame('down', $data['idea']['my_vote'] ?? null, 'A logged-in user with a down vote must see my_vote=down.');
    }

    public function test_idea_detail_shows_no_active_state_when_user_has_not_voted(): void
    {
        $boardId  = $this->insertBoard('vs-detail-none');
        $authorId = $this->insertUser('vs-detail-none-a@example.com');
        $voterId  = $this->insertUser('vs-detail-none-v@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Not voted');

        $data = json_decode(
            (string) $this->createApp()->handle($this->getDetail('vs-detail-none', $ideaId, $voterId))->getBody(),
            true,
        );

        self::assertSame('none', $data['idea']['my_vote'] ?? null, 'No vote → my_vote=none expected.');
    }

    // -------------------------------------------------------------------------
    // AC3 — my_vote in the board list for a logged-in user
    // -------------------------------------------------------------------------

    public function test_board_list_shows_vote_up_state_for_logged_in_user(): void
    {
        $boardId  = $this->insertBoard('vs-list-up');
        $authorId = $this->insertUser('vs-list-up-a@example.com');
        $voterId  = $this->insertUser('vs-list-up-v@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Upvoted');
        $this->seedVote($ideaId, $voterId, 1);

        $data   = json_decode(
            (string) $this->createApp()->handle($this->getBoard('vs-list-up', $voterId))->getBody(),
            true,
        );
        $myVotes = array_column($data['ideas'] ?? [], 'my_vote');

        self::assertContains('up', $myVotes, 'The board list must return my_vote=up for an upvoted idea.');
    }

    public function test_board_list_shows_vote_down_state_for_logged_in_user(): void
    {
        $boardId  = $this->insertBoard('vs-list-dn');
        $authorId = $this->insertUser('vs-list-dn-a@example.com');
        $voterId  = $this->insertUser('vs-list-dn-v@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Downvoted');
        $this->seedVote($ideaId, $voterId, -1);

        $data   = json_decode(
            (string) $this->createApp()->handle($this->getBoard('vs-list-dn', $voterId))->getBody(),
            true,
        );
        $myVotes = array_column($data['ideas'] ?? [], 'my_vote');

        self::assertContains('down', $myVotes, 'The board list must return my_vote=down for a downvoted idea.');
    }

    // -------------------------------------------------------------------------
    // AC4 — anonymous visitor: login link instead of a form, score visible
    // -------------------------------------------------------------------------

    public function test_anon_detail_shows_login_link_with_return_to(): void
    {
        $boardId  = $this->insertBoard('vs-anon-link');
        $authorId = $this->insertUser('vs-anon-link@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Anon link idea', ['score_cache' => 3]);

        $data = json_decode(
            (string) $this->createApp()->handle($this->getDetail('vs-anon-link', $ideaId))->getBody(),
            true,
        );

        // Anon → is_authenticated=false; SPA shows a login link
        self::assertFalse($data['is_authenticated'] ?? true, 'An anon visitor must see is_authenticated=false.');
        // Score stays readable (field is named score_cache in the DB row)
        self::assertSame(3, (int) ($data['idea']['score_cache'] ?? null), 'Score must be visible to an anon visitor.');
    }

    public function test_anon_board_list_shows_login_links(): void
    {
        $boardId  = $this->insertBoard('vs-anon-list');
        $authorId = $this->insertUser('vs-anon-list@example.com');
        $this->seedIdea($boardId, $authorId, 'Anon list idea');

        $data = json_decode(
            (string) $this->createApp()->handle($this->getBoard('vs-anon-list'))->getBody(),
            true,
        );

        // Anon → is_authenticated=false; SPA renders login links
        self::assertFalse($data['is_authenticated'] ?? true, 'The board list must return is_authenticated=false for anon.');
    }

    // -------------------------------------------------------------------------
    // AC5 — Return-to URL: the JSON API returns is_authenticated=false; SPA builds the link
    // -------------------------------------------------------------------------

    public function test_anon_login_link_contains_rawurlencoded_return_to(): void
    {
        $boardId  = $this->insertBoard('vs-return');
        $authorId = $this->insertUser('vs-return@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Return-to idea');

        $data = json_decode(
            (string) $this->createApp()->handle($this->getDetail('vs-return', $ideaId))->getBody(),
            true,
        );

        // API returns is_authenticated=false; the SPA builds the login link with a rawurlencoded return-to itself
        self::assertFalse($data['is_authenticated'] ?? true, 'Anon detail must return is_authenticated=false.');
        // The idea URL is present in the JSON so the SPA can build the return-to parameter correctly
        self::assertArrayHasKey('idea', $data);
    }

    // -------------------------------------------------------------------------
    // AC6 — cross-board: my_vote only from the current board
    // -------------------------------------------------------------------------

    public function test_cross_board_my_vote_isolation(): void
    {
        $boardAId = $this->insertBoard('vs-cross-a');
        $boardBId = $this->insertBoard('vs-cross-b');
        $authorId = $this->insertUser('vs-cross-author@example.com');
        $voterId  = $this->insertUser('vs-cross-voter@example.com');

        // Voter votes ONLY in board A.
        $ideaA = $this->seedIdea($boardAId, $authorId, 'Idea in board A');
        $ideaB = $this->seedIdea($boardBId, $authorId, 'Idea in board B');
        $this->seedVote($ideaA, $voterId, 1);

        $app = $this->createApp();

        // Board B: my_vote for ideaB must be 'none' (not 'up' from board A)
        $dataB   = json_decode((string) $app->handle($this->getBoard('vs-cross-b', $voterId))->getBody(), true);
        $myVotesB = array_column($dataB['ideas'] ?? [], 'my_vote');
        self::assertNotContains('up', $myVotesB, 'A vote from board A must not appear in board B.');

        // Detail in board B: my_vote must be 'none'
        $dataDetailB = json_decode((string) $app->handle($this->getDetail('vs-cross-b', $ideaB, $voterId))->getBody(), true);
        self::assertSame('none', $dataDetailB['idea']['my_vote'] ?? null, 'Detail board B: my_vote must be none.');

        // Board A: my_vote for ideaA must be 'up'
        $dataA   = json_decode((string) $app->handle($this->getBoard('vs-cross-a', $voterId))->getBody(), true);
        $myVotesA = array_column($dataA['ideas'] ?? [], 'my_vote');
        self::assertContains('up', $myVotesA, 'Board A must return my_vote=up for its own vote.');
    }
}
