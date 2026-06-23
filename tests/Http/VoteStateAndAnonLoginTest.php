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

        $body = (string) $this->createApp()->handle($this->getDetail('vs-detail-up', $ideaId, $voterId))->getBody();

        self::assertStringContainsString('vp-vote--up"', $body, 'Eingeloggter User mit Up-Stimme muss vp-vote--up-Klasse sehen.');
        self::assertStringNotContainsString('vp-vote--down"', $body);
    }

    public function test_idea_detail_shows_vote_down_state_for_logged_in_user(): void
    {
        $boardId  = $this->insertBoard('vs-detail-dn');
        $authorId = $this->insertUser('vs-detail-dn-a@example.com');
        $voterId  = $this->insertUser('vs-detail-dn-v@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Runtergestimmte Idee');
        $this->seedVote($ideaId, $voterId, -1);

        $body = (string) $this->createApp()->handle($this->getDetail('vs-detail-dn', $ideaId, $voterId))->getBody();

        self::assertStringContainsString('vp-vote--down"', $body, 'Eingeloggter User mit Down-Stimme muss vp-vote--down-Klasse sehen.');
        self::assertStringNotContainsString('vp-vote--up"', $body);
    }

    public function test_idea_detail_shows_no_active_state_when_user_has_not_voted(): void
    {
        $boardId  = $this->insertBoard('vs-detail-none');
        $authorId = $this->insertUser('vs-detail-none-a@example.com');
        $voterId  = $this->insertUser('vs-detail-none-v@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Nicht Abgestimmt');

        $body = (string) $this->createApp()->handle($this->getDetail('vs-detail-none', $ideaId, $voterId))->getBody();

        self::assertStringNotContainsString('vp-vote--up"', $body);
        self::assertStringNotContainsString('vp-vote--down"', $body);
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

        $body = (string) $this->createApp()->handle($this->getBoard('vs-list-up', $voterId))->getBody();

        self::assertStringContainsString('vp-vote--up"', $body, 'Board-Liste muss vp-vote--up für Up-gestimmte Idee zeigen.');
    }

    public function test_board_list_shows_vote_down_state_for_logged_in_user(): void
    {
        $boardId  = $this->insertBoard('vs-list-dn');
        $authorId = $this->insertUser('vs-list-dn-a@example.com');
        $voterId  = $this->insertUser('vs-list-dn-v@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Runtergestimmt');
        $this->seedVote($ideaId, $voterId, -1);

        $body = (string) $this->createApp()->handle($this->getBoard('vs-list-dn', $voterId))->getBody();

        self::assertStringContainsString('vp-vote--down"', $body, 'Board-Liste muss vp-vote--down für Down-gestimmte Idee zeigen.');
    }

    // -------------------------------------------------------------------------
    // AC4 — Anonymer Besucher: Login-Link statt Form, Score sichtbar
    // -------------------------------------------------------------------------

    public function test_anon_detail_shows_login_link_with_return_to(): void
    {
        $boardId  = $this->insertBoard('vs-anon-link');
        $authorId = $this->insertUser('vs-anon-link@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Anon-Link-Idee', ['score_cache' => 3]);

        $body = (string) $this->createApp()->handle($this->getDetail('vs-anon-link', $ideaId))->getBody();

        // Login-Link mit r= vorhanden
        self::assertStringContainsString('/login?r=', $body, 'Anon-Besucher muss Login-Link sehen.');
        // Kein POST-Form für Vote
        self::assertStringNotContainsString('action="/vs-anon-link/ideas/' . $ideaId . '/vote"', $body);
        // Score bleibt sichtbar (lesbar)
        self::assertStringContainsString('3', $body, 'Score muss für Anon-Besucher sichtbar sein.');
    }

    public function test_anon_board_list_shows_login_links(): void
    {
        $boardId  = $this->insertBoard('vs-anon-list');
        $authorId = $this->insertUser('vs-anon-list@example.com');
        $this->seedIdea($boardId, $authorId, 'Anon-Listen-Idee');

        $body = (string) $this->createApp()->handle($this->getBoard('vs-anon-list'))->getBody();

        self::assertStringContainsString('/login?r=', $body, 'Board-Liste muss Login-Links für Anon zeigen.');
        // Kein POST-Form für Vote
        self::assertStringNotContainsString('name="value" value="up"', $body);
    }

    // -------------------------------------------------------------------------
    // AC5 — Return-To-URL korrekt rawurlencode(d)
    // -------------------------------------------------------------------------

    public function test_anon_login_link_contains_rawurlencoded_return_to(): void
    {
        $boardId  = $this->insertBoard('vs-return');
        $authorId = $this->insertUser('vs-return@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Return-To-Idee');

        $body = (string) $this->createApp()->handle($this->getDetail('vs-return', $ideaId))->getBody();

        // rawurlencode('/vs-return/ideas/{id}') = '%2Fvs-return%2Fideas%2F{id}'
        $expectedEncoded = rawurlencode('/vs-return/ideas/' . $ideaId);
        self::assertStringContainsString('r=' . $expectedEncoded, $body, 'Return-To muss rawurlencode(d) sein.');
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

        // Board B muss kein vp-vote--up zeigen für den Voter.
        $bodyB = (string) $this->createApp()->handle($this->getBoard('vs-cross-b', $voterId))->getBody();
        self::assertStringNotContainsString('vp-vote--up"', $bodyB, 'Stimme aus Board A darf in Board B nicht angezeigt werden.');

        // Detail-Seite in Board B: auch kein vp-vote--up.
        $bodyDetailB = (string) $this->createApp()->handle($this->getDetail('vs-cross-b', $ideaB, $voterId))->getBody();
        self::assertStringNotContainsString('vp-vote--up"', $bodyDetailB);

        // Board A hingegen zeigt vp-vote--up.
        $bodyA = (string) $this->createApp()->handle($this->getBoard('vs-cross-a', $voterId))->getBody();
        self::assertStringContainsString('vp-vote--up"', $bodyA, 'Board A muss vp-vote--up für die eigene Stimme zeigen.');
    }
}
