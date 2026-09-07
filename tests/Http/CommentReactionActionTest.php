<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /{board}/ideas/{id}/comments/{commentId}/react.
 *
 * All assertions run exclusively through the HTTP seam (AppFactory::create),
 * the same pipeline as production: session → AuthN → AuthZ user → BlockCheck →
 * CSRF → RateLimit perAction.
 *
 * AC coverage:
 *   AC1 — A logged-in user can react to a comment with one of the fixed emoji.
 *   AC2 — Reacting with the SAME emoji again retracts it (toggle off).
 *   AC3 — Reacting with a DIFFERENT emoji switches in place (no second row).
 *   AC4 — Cross-tenant/cross-board isolation: a comment reachable only via a
 *          foreign board/idea → 404, no mutation.
 *   AC5 — AuthZ/BlockCheck/CSRF/validation guardrails match VoteAction/CommentCreateAction.
 *   AC6 — GET idea detail includes the reactions summary per comment.
 */
final class CommentReactionActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postReact(
        string $slug,
        int $ideaId,
        int $commentId,
        string $reaction,
        ?int $userId,
    ): ServerRequestInterface {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', "/{$slug}/ideas/{$ideaId}/comments/{$commentId}/react")
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'reaction' => $reaction]);
    }

    private function postReactNoCsrf(string $slug, int $ideaId, int $commentId, string $reaction, int $userId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', "/{$slug}/ideas/{$ideaId}/comments/{$commentId}/react")
            ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody(['reaction' => $reaction]);
    }

    private function rowCount(int $commentId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM comment_reactions WHERE comment_id = :id', ['id' => $commentId]);
    }

    // --- Happy path -----------------------------------------------------------

    public function test_first_reaction_returns_200_and_creates_row(): void
    {
        $boardId   = $this->insertBoard('react-first');
        $author    = $this->insertUser('react-first-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);
        $userId    = $this->insertUser('react-first-reactor@example.com');

        $response = $this->createApp()->handle($this->postReact('react-first', $ideaId, $commentId, '👍', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('👍', $data['my_reaction'] ?? null);
        self::assertSame(['👍' => 1], $data['counts'] ?? null);
        self::assertSame(1, $this->rowCount($commentId));
    }

    // --- Toggle / switch / retract ---------------------------------------------

    public function test_second_same_reaction_retracts_no_duplicate_row(): void
    {
        $boardId   = $this->insertBoard('react-retract');
        $author    = $this->insertUser('react-retract-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);
        $userId    = $this->insertUser('react-retract-reactor@example.com');

        $app = $this->createApp();
        $app->handle($this->postReact('react-retract', $ideaId, $commentId, '❤️', $userId));
        $response = $app->handle($this->postReact('react-retract', $ideaId, $commentId, '❤️', $userId));

        self::assertSame(0, $this->rowCount($commentId));
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('my_reaction', $data);
        self::assertNull($data['my_reaction']);
        self::assertSame([], $data['counts'] ?? null);
    }

    public function test_different_reaction_switches_in_place(): void
    {
        $boardId   = $this->insertBoard('react-switch');
        $author    = $this->insertUser('react-switch-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);
        $userId    = $this->insertUser('react-switch-reactor@example.com');

        $app = $this->createApp();
        $app->handle($this->postReact('react-switch', $ideaId, $commentId, '👍', $userId));
        $response = $app->handle($this->postReact('react-switch', $ideaId, $commentId, '🎉', $userId));

        self::assertSame(1, $this->rowCount($commentId), 'Switching flips in place, no second row.');
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('🎉', $data['my_reaction'] ?? null);
        self::assertSame(['🎉' => 1], $data['counts'] ?? null);
    }

    // --- AuthZ / BlockCheck ------------------------------------------------------

    public function test_anon_react_returns_401_and_creates_no_row(): void
    {
        $boardId   = $this->insertBoard('react-anon');
        $author    = $this->insertUser('react-anon-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);

        $response = $this->createApp()->handle($this->postReact('react-anon', $ideaId, $commentId, '👍', null));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($commentId));
    }

    public function test_blocked_user_react_returns_403_and_creates_no_row(): void
    {
        $boardId   = $this->insertBoard('react-blocked');
        $blockedId = $this->insertUser('react-blocked@example.com', ['is_blocked' => 1]);
        $ideaId    = $this->seedIdea($boardId, $blockedId);
        $commentId = $this->seedComment($ideaId, $blockedId);

        $response = $this->createApp()->handle($this->postReact('react-blocked', $ideaId, $commentId, '👍', $blockedId));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($commentId));
    }

    // --- CSRF --------------------------------------------------------------------

    public function test_missing_csrf_returns_403_and_creates_no_row(): void
    {
        $boardId   = $this->insertBoard('react-csrf');
        $author    = $this->insertUser('react-csrf-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);
        $userId    = $this->insertUser('react-csrf-reactor@example.com');

        $response = $this->createApp()->handle($this->postReactNoCsrf('react-csrf', $ideaId, $commentId, '👍', $userId));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($commentId));
    }

    // --- Invalid input -------------------------------------------------------------

    public function test_invalid_reaction_returns_422_and_creates_no_row(): void
    {
        $boardId   = $this->insertBoard('react-422');
        $author    = $this->insertUser('react-422-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);
        $userId    = $this->insertUser('react-422-reactor@example.com');

        $response = $this->createApp()->handle($this->postReact('react-422', $ideaId, $commentId, '🍕', $userId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($commentId));
    }

    public function test_free_text_reaction_is_rejected(): void
    {
        $boardId   = $this->insertBoard('react-freetext');
        $author    = $this->insertUser('react-freetext-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);
        $userId    = $this->insertUser('react-freetext-reactor@example.com');

        $response = $this->createApp()->handle(
            $this->postReact('react-freetext', $ideaId, $commentId, '<script>alert(1)</script>', $userId),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($commentId));
    }

    // --- Cross-tenant / cross-board scoping (critical path) -----------------------

    public function test_react_via_wrong_board_slug_returns_404_and_creates_no_row(): void
    {
        $boardId1 = $this->insertBoard('react-b1');
        $this->insertBoard('react-b2');
        $author    = $this->insertUser('react-cross-author@example.com');
        $ideaId    = $this->seedIdea($boardId1, $author);
        $commentId = $this->seedComment($ideaId, $author);
        $userId    = $this->insertUser('react-cross-reactor@example.com');

        // Comment belongs to board1's idea, request goes to board2.
        $response = $this->createApp()->handle($this->postReact('react-b2', $ideaId, $commentId, '👍', $userId));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($commentId));
    }

    public function test_react_to_comment_of_foreign_idea_returns_404_and_creates_no_row(): void
    {
        $boardId = $this->insertBoard('react-foreign-idea');
        $author  = $this->insertUser('react-foreign-idea-author@example.com');
        $ideaOne = $this->seedIdea($boardId, $author, 'Idea one');
        $ideaTwo = $this->seedIdea($boardId, $author, 'Idea two');
        // Comment belongs to ideaOne; request addresses it via ideaTwo.
        $commentId = $this->seedComment($ideaOne, $author);
        $userId    = $this->insertUser('react-foreign-idea-reactor@example.com');

        $response = $this->createApp()->handle($this->postReact('react-foreign-idea', $ideaTwo, $commentId, '👍', $userId));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($commentId));
    }

    public function test_react_on_comment_in_foreign_account_board_returns_404(): void
    {
        // Cross-tenant leak (the schwerste Failure-Mode): a comment that
        // only exists in a FOREIGN account's board must never be reachable
        // through the default account's request context.
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-foreign-reactions', 'name' => 'Foreign Account']);
        $foreignBoardId   = $this->insertBoard('foreign-react-board', ['account_id' => $foreignAccountId]);
        $foreignAuthorId  = $this->insertUser('foreign-react-author@example.com');
        $foreignIdeaId    = $this->seedIdea($foreignBoardId, $foreignAuthorId);
        $foreignCommentId = $this->seedComment($foreignIdeaId, $foreignAuthorId);

        $ownerId = $this->insertUser('default-owner-reactions@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle(
            $this->postReact('foreign-react-board', $foreignIdeaId, $foreignCommentId, '👍', $ownerId),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($foreignCommentId));
    }

    // --- Read path: idea detail includes reactions summary -------------------------

    public function test_idea_detail_includes_reactions_summary_per_comment(): void
    {
        $boardId   = $this->insertBoard('react-detail');
        $author    = $this->insertUser('react-detail-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);
        $viewer    = $this->insertUser('react-detail-viewer@example.com');
        $other     = $this->insertUser('react-detail-other@example.com');

        $this->seedReaction($commentId, $viewer, '👍');
        $this->seedReaction($commentId, $other, '👍');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/react-detail/ideas/' . $ideaId)
            ->withCookieParams(['votepit_sess' => $this->sessionCookie($viewer)]);
        $response = $this->createApp()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $comment = $data['comments'][0];
        self::assertSame(['👍' => 2], $comment['reactions']['counts']);
        self::assertSame('👍', $comment['reactions']['my_reaction']);
    }

    public function test_idea_detail_for_anon_omits_my_reaction(): void
    {
        $boardId   = $this->insertBoard('react-detail-anon');
        $author    = $this->insertUser('react-detail-anon-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);
        $other     = $this->insertUser('react-detail-anon-other@example.com');

        $this->seedReaction($commentId, $other, '😄');

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/react-detail-anon/ideas/' . $ideaId);
        $response = $this->createApp()->handle($request);

        $data    = json_decode((string) $response->getBody(), true);
        $comment = $data['comments'][0];
        self::assertSame(['😄' => 1], $comment['reactions']['counts']);
        self::assertNull($comment['reactions']['my_reaction']);
    }

    // --- Audit (masked, no PII) ----------------------------------------------------

    public function test_reaction_toggle_is_audited_without_pii(): void
    {
        $boardId   = $this->insertBoard('react-audit');
        $author    = $this->insertUser('react-audit-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);
        $userId    = $this->insertUser('react-audit-secret@example.com');

        $this->createApp()->handle($this->postReact('react-audit', $ideaId, $commentId, '👍', $userId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('comment.reaction_toggled', $log);
        self::assertStringNotContainsString('react-audit-secret@example.com', $log);
    }
}
