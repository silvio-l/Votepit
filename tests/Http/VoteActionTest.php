<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für POST /{board}/ideas/{id}/vote (Sprint 4, Issue 01).
 *
 * Alle Assertions laufen ausschließlich durch den HTTP-Seam (AppFactory::create),
 * identische Pipeline wie Produktion: Session → AuthN → AuthZ user → BlockCheck →
 * CSRF → RateLimit perAction.
 */
final class VoteActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postVote(string $slug, int $ideaId, string $value, ?int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/vote')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'value' => $value]);
    }

    private function postVoteNoCsrf(string $slug, int $ideaId, string $value, int $userId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/vote')
            ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody(['value' => $value]);
    }

    private function rowCount(int $ideaId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id', ['id' => $ideaId]);
    }

    private function scoreCache(int $ideaId): int
    {
        return (int) $this->conn->fetchOne('SELECT score_cache FROM ideas WHERE id = :id', ['id' => $ideaId]);
    }

    // --- Happy path / PRG ----------------------------------------------------

    public function test_first_vote_returns_302_prg_to_idea_detail_and_creates_row(): void
    {
        $boardId = $this->insertBoard('vote-prg');
        $userId  = $this->insertUser('vote-prg@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postVote('vote-prg', $ideaId, 'up', $userId));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/vote-prg/ideas/' . $ideaId, $response->getHeaderLine('Location'));
        self::assertSame(1, $this->rowCount($ideaId));
        self::assertSame(1, $this->scoreCache($ideaId));
    }

    public function test_down_vote_persists_negative_value(): void
    {
        $boardId = $this->insertBoard('vote-down');
        $userId  = $this->insertUser('vote-down@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $this->createApp()->handle($this->postVote('vote-down', $ideaId, 'down', $userId));

        self::assertSame(-1, $this->scoreCache($ideaId));
        $value = (int) $this->conn->fetchOne('SELECT value FROM votes WHERE idea_id = :id', ['id' => $ideaId]);
        self::assertSame(-1, $value);
    }

    // --- Integrity: no second row, switch, retract ---------------------------

    public function test_second_same_vote_retracts_no_duplicate_row(): void
    {
        $boardId = $this->insertBoard('vote-retract');
        $userId  = $this->insertUser('vote-retract@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $app = $this->createApp();
        $app->handle($this->postVote('vote-retract', $ideaId, 'up', $userId));
        $app->handle($this->postVote('vote-retract', $ideaId, 'up', $userId));

        self::assertSame(0, $this->rowCount($ideaId), 'Erneuter gleicher Pfeil nimmt zurück (Zeile gelöscht).');
        self::assertSame(0, $this->scoreCache($ideaId));
    }

    public function test_up_then_down_switches_in_place(): void
    {
        $boardId = $this->insertBoard('vote-flip');
        $userId  = $this->insertUser('vote-flip@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $app = $this->createApp();
        $app->handle($this->postVote('vote-flip', $ideaId, 'up', $userId));
        $app->handle($this->postVote('vote-flip', $ideaId, 'down', $userId));

        self::assertSame(1, $this->rowCount($ideaId), 'Switch dreht in-place, keine zweite Zeile.');
        self::assertSame(-1, $this->scoreCache($ideaId));
    }

    // --- AuthZ / BlockCheck --------------------------------------------------

    public function test_anon_vote_returns_401_and_creates_no_row(): void
    {
        $boardId = $this->insertBoard('vote-anon');
        $userId  = $this->insertUser('vote-anon@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postVote('vote-anon', $ideaId, 'up', null));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($ideaId));
    }

    public function test_blocked_user_vote_returns_403_and_creates_no_row(): void
    {
        $boardId   = $this->insertBoard('vote-blocked');
        $blockedId = $this->insertUser('vote-blocked@example.com', ['is_blocked' => 1]);
        $ideaId    = $this->seedIdea($boardId, $blockedId);

        $response = $this->createApp()->handle($this->postVote('vote-blocked', $ideaId, 'up', $blockedId));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($ideaId));
    }

    // --- CSRF ----------------------------------------------------------------

    public function test_missing_csrf_returns_403_and_creates_no_row(): void
    {
        $boardId = $this->insertBoard('vote-csrf');
        $userId  = $this->insertUser('vote-csrf@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postVoteNoCsrf('vote-csrf', $ideaId, 'up', $userId));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($ideaId));
    }

    // --- Invalid input / board scoping ---------------------------------------

    public function test_invalid_value_returns_422_and_creates_no_row(): void
    {
        $boardId = $this->insertBoard('vote-422');
        $userId  = $this->insertUser('vote-422@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postVote('vote-422', $ideaId, 'sideways', $userId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($ideaId));
    }

    public function test_vote_via_wrong_board_slug_returns_404_and_creates_no_row(): void
    {
        $boardId1 = $this->insertBoard('vote-b1');
        $this->insertBoard('vote-b2');
        $userId   = $this->insertUser('vote-cross@example.com');
        $ideaId   = $this->seedIdea($boardId1, $userId);

        // Idee gehört zu Board1, Request geht an Board2.
        $response = $this->createApp()->handle($this->postVote('vote-b2', $ideaId, 'up', $userId));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($ideaId));
    }

    // --- Audit (masked, no PII) ----------------------------------------------

    public function test_vote_cast_is_audited_without_pii(): void
    {
        $boardId = $this->insertBoard('vote-audit');
        $userId  = $this->insertUser('vote-audit-secret@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $this->createApp()->handle($this->postVote('vote-audit', $ideaId, 'up', $userId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('vote.cast', $log);
        self::assertStringNotContainsString('vote-audit-secret@example.com', $log);
    }

    // --- Detail-page vote controls (Issue 02: anon → login-links; auth → forms) --

    /** Anon sieht Login-Links statt Forms (Issue 02). */
    public function test_idea_detail_renders_login_links_for_anon(): void
    {
        $boardId = $this->insertBoard('vote-detail-anon');
        $userId  = $this->insertUser('vote-detail-anon@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/vote-detail-anon/ideas/' . $ideaId);
        $response = $this->createApp()->handle($request);
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('/login?r=', $body);
        // Kein POST-Form mehr für Anon — kein action-Attribut auf die Vote-Route.
        self::assertStringNotContainsString('action="/vote-detail-anon/ideas/' . $ideaId . '/vote"', $body);
    }

    /** Eingeloggter User sieht interaktive POST-Forms (wie bisher). */
    public function test_idea_detail_renders_vote_forms_for_authenticated_user(): void
    {
        $boardId = $this->insertBoard('vote-detail-auth');
        $userId  = $this->insertUser('vote-detail-auth@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $request  = (new ServerRequestFactory())
            ->createServerRequest('GET', '/vote-detail-auth/ideas/' . $ideaId)
            ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
        $response = $this->createApp()->handle($request);
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('action="/vote-detail-auth/ideas/' . $ideaId . '/vote"', $body);
        self::assertStringContainsString('name="value" value="up"', $body);
        self::assertStringContainsString('name="value" value="down"', $body);
    }
}
