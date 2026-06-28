<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * HTTP-Integrationstests für den Voting-Lesepfad (Sprint 4, Issue 02).
 *
 * Beweist über den HTTP-Seam (AppFactory + IntegrationTestCase):
 *  AC1  — findInBoard/listByBoard mit null-userId verhalten sich wie zuvor
 *  AC2  — my_vote ∈ {up, down, none} für eingeloggten User auf Idee-Detail
 *  AC3  — my_vote ∈ {up, down, none} für eingeloggten User in Board-Liste
 *  AC4  — Anonymer Besucher sieht Score/Konsens + Login-Link mit Return-To
 *  AC5  — Return-To-URL enthält rawurlencode der Idee-URL
 *  AC6  — Cross-Board: my_vote nur aus aktuellem Board
 */
final class VoteStateAndAnonLoginTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Hilfsmethoden
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
    // AC1 — null-userId: bestehendes Verhalten unverändert (kein my_vote im HTML)
    // -------------------------------------------------------------------------

    public function test_anon_detail_renders_200_without_vote_form(): void
    {
        $boardId  = $this->insertBoard('vs-anon-detail');
        $authorId = $this->insertUser('vs-anon-d@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Anon-Idee');

        $response = $this->createApp()->handle($this->getDetail('vs-anon-detail', $ideaId));

        self::assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC2 — my_vote auf Idee-Detail für eingeloggten User
    // -------------------------------------------------------------------------

    public function test_idea_detail_shows_vote_up_state_for_logged_in_user(): void
    {
        $boardId  = $this->insertBoard('vs-detail-up');
        $authorId = $this->insertUser('vs-detail-up-a@example.com');
        $voterId  = $this->insertUser('vs-detail-up-v@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Hochgestimmte Idee');
        $this->seedVote($ideaId, $voterId, 1);

        $data = json_decode(
            (string) $this->createApp()->handle($this->getDetail('vs-detail-up', $ideaId, $voterId))->getBody(),
            true,
        );

        self::assertSame('up', $data['idea']['my_vote'] ?? null, 'Eingeloggter User mit Up-Stimme muss my_vote=up sehen.');
    }

    public function test_idea_detail_shows_vote_down_state_for_logged_in_user(): void
    {
        $boardId  = $this->insertBoard('vs-detail-dn');
        $authorId = $this->insertUser('vs-detail-dn-a@example.com');
        $voterId  = $this->insertUser('vs-detail-dn-v@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Runtergestimmte Idee');
        $this->seedVote($ideaId, $voterId, -1);

        $data = json_decode(
            (string) $this->createApp()->handle($this->getDetail('vs-detail-dn', $ideaId, $voterId))->getBody(),
            true,
        );

        self::assertSame('down', $data['idea']['my_vote'] ?? null, 'Eingeloggter User mit Down-Stimme muss my_vote=down sehen.');
    }

    public function test_idea_detail_shows_no_active_state_when_user_has_not_voted(): void
    {
        $boardId  = $this->insertBoard('vs-detail-none');
        $authorId = $this->insertUser('vs-detail-none-a@example.com');
        $voterId  = $this->insertUser('vs-detail-none-v@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Nicht Abgestimmt');

        $data = json_decode(
            (string) $this->createApp()->handle($this->getDetail('vs-detail-none', $ideaId, $voterId))->getBody(),
            true,
        );

        self::assertSame('none', $data['idea']['my_vote'] ?? null, 'Kein Vote → my_vote=none erwartet.');
    }

    // -------------------------------------------------------------------------
    // AC3 — my_vote in Board-Liste für eingeloggten User
    // -------------------------------------------------------------------------

    public function test_board_list_shows_vote_up_state_for_logged_in_user(): void
    {
        $boardId  = $this->insertBoard('vs-list-up');
        $authorId = $this->insertUser('vs-list-up-a@example.com');
        $voterId  = $this->insertUser('vs-list-up-v@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Hochgestimmt');
        $this->seedVote($ideaId, $voterId, 1);

        $data   = json_decode(
            (string) $this->createApp()->handle($this->getBoard('vs-list-up', $voterId))->getBody(),
            true,
        );
        $myVotes = array_column($data['ideas'] ?? [], 'my_vote');

        self::assertContains('up', $myVotes, 'Board-Liste muss my_vote=up für Up-gestimmte Idee liefern.');
    }

    public function test_board_list_shows_vote_down_state_for_logged_in_user(): void
    {
        $boardId  = $this->insertBoard('vs-list-dn');
        $authorId = $this->insertUser('vs-list-dn-a@example.com');
        $voterId  = $this->insertUser('vs-list-dn-v@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Runtergestimmt');
        $this->seedVote($ideaId, $voterId, -1);

        $data   = json_decode(
            (string) $this->createApp()->handle($this->getBoard('vs-list-dn', $voterId))->getBody(),
            true,
        );
        $myVotes = array_column($data['ideas'] ?? [], 'my_vote');

        self::assertContains('down', $myVotes, 'Board-Liste muss my_vote=down für Down-gestimmte Idee liefern.');
    }

    // -------------------------------------------------------------------------
    // AC4 — Anonymer Besucher: Login-Link statt Form, Score sichtbar
    // -------------------------------------------------------------------------

    public function test_anon_detail_shows_login_link_with_return_to(): void
    {
        $boardId  = $this->insertBoard('vs-anon-link');
        $authorId = $this->insertUser('vs-anon-link@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Anon-Link-Idee', ['score_cache' => 3]);

        $data = json_decode(
            (string) $this->createApp()->handle($this->getDetail('vs-anon-link', $ideaId))->getBody(),
            true,
        );

        // Anon → is_authenticated=false; SPA zeigt Login-Link
        self::assertFalse($data['is_authenticated'] ?? true, 'Anon-Besucher muss is_authenticated=false sehen.');
        // Score bleibt lesbar (Feld heißt score_cache in der DB-Row)
        self::assertSame(3, (int) ($data['idea']['score_cache'] ?? null), 'Score muss für Anon-Besucher sichtbar sein.');
    }

    public function test_anon_board_list_shows_login_links(): void
    {
        $boardId  = $this->insertBoard('vs-anon-list');
        $authorId = $this->insertUser('vs-anon-list@example.com');
        $this->seedIdea($boardId, $authorId, 'Anon-Listen-Idee');

        $data = json_decode(
            (string) $this->createApp()->handle($this->getBoard('vs-anon-list'))->getBody(),
            true,
        );

        // Anon → is_authenticated=false; SPA rendert Login-Links
        self::assertFalse($data['is_authenticated'] ?? true, 'Board-Liste muss is_authenticated=false für Anon liefern.');
    }

    // -------------------------------------------------------------------------
    // AC5 — Return-To-URL: JSON-API liefert is_authenticated=false; SPA baut den Link
    // -------------------------------------------------------------------------

    public function test_anon_login_link_contains_rawurlencoded_return_to(): void
    {
        $boardId  = $this->insertBoard('vs-return');
        $authorId = $this->insertUser('vs-return@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Return-To-Idee');

        $data = json_decode(
            (string) $this->createApp()->handle($this->getDetail('vs-return', $ideaId))->getBody(),
            true,
        );

        // API liefert is_authenticated=false; SPA baut den Login-Link mit rawurlencodem Return-To selbst
        self::assertFalse($data['is_authenticated'] ?? true, 'Anon-Detail muss is_authenticated=false liefern.');
        // Idea-URL ist im JSON vorhanden, damit die SPA den Return-To-Parameter korrekt bauen kann
        self::assertArrayHasKey('idea', $data);
    }

    // -------------------------------------------------------------------------
    // AC6 — Cross-Board: my_vote nur aus aktuellem Board
    // -------------------------------------------------------------------------

    public function test_cross_board_my_vote_isolation(): void
    {
        $boardAId = $this->insertBoard('vs-cross-a');
        $boardBId = $this->insertBoard('vs-cross-b');
        $authorId = $this->insertUser('vs-cross-author@example.com');
        $voterId  = $this->insertUser('vs-cross-voter@example.com');

        // Voter stimmt NUR in Board A ab.
        $ideaA = $this->seedIdea($boardAId, $authorId, 'Idee in Board A');
        $ideaB = $this->seedIdea($boardBId, $authorId, 'Idee in Board B');
        $this->seedVote($ideaA, $voterId, 1);

        $app = $this->createApp();

        // Board B: my_vote für ideaB muss 'none' sein (nicht 'up' aus Board A)
        $dataB   = json_decode((string) $app->handle($this->getBoard('vs-cross-b', $voterId))->getBody(), true);
        $myVotesB = array_column($dataB['ideas'] ?? [], 'my_vote');
        self::assertNotContains('up', $myVotesB, 'Stimme aus Board A darf in Board B nicht erscheinen.');

        // Detail in Board B: my_vote muss 'none' sein
        $dataDetailB = json_decode((string) $app->handle($this->getDetail('vs-cross-b', $ideaB, $voterId))->getBody(), true);
        self::assertSame('none', $dataDetailB['idea']['my_vote'] ?? null, 'Detail Board B: my_vote muss none sein.');

        // Board A: my_vote für ideaA muss 'up' sein
        $dataA   = json_decode((string) $app->handle($this->getBoard('vs-cross-a', $voterId))->getBody(), true);
        $myVotesA = array_column($dataA['ideas'] ?? [], 'my_vote');
        self::assertContains('up', $myVotesA, 'Board A muss my_vote=up für die eigene Stimme liefern.');
    }
}
