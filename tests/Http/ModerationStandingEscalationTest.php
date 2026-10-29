<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for repeat-offender escalation (board-operator-
 * compliance issue 10):
 *  - repeated CommentModerationAction/IdeaModerationAction calls against
 *    the SAME author accumulate account_member_standing.violation_count
 *    and escalate state, automatically (state_changed_by_user_id IS NULL);
 *  - a member whose standing is 'suspended' gets a 403 `member_suspended`
 *    from IdeaCreateAction/CommentCreateAction, and nothing is created.
 *
 * Default thresholds (Config::moderationEscalation()): flagged_at=1,
 * warned_at=3, suspended_at=5.
 */
final class ModerationStandingEscalationTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function postDeleteComment(string $slug, int $ideaId, int $commentId, int $modId, array $body = ['reason_code' => 'community_rule']): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/comments/' . $commentId . '/delete')
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($modId)])
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    private function validTimeTrap(): string
    {
        $ts  = (string) (time() - 5);
        $key = str_repeat('a', 64);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, $key, true)), '+/', '-_'), '=');
        return $ts . '.' . $mac;
    }

    /** @param array<string, string> $body */
    private function postIdea(string $slug, array $body, int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $defaults = ['_csrf' => $token, '_form_at' => $this->validTimeTrap()];

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas')
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody(array_merge($defaults, $body));
    }

    /** @param array<string, string> $body */
    private function postComment(string $slug, int $ideaId, array $body, int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/comments')
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    // -------------------------------------------------------------------------
    // Automatic escalation via repeated moderation actions
    // -------------------------------------------------------------------------

    public function test_repeated_comment_moderation_against_same_author_escalates_standing(): void
    {
        $boardId  = $this->insertBoard('standing-escalation-board');
        $modId    = $this->insertUser('standing-mod@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'owner');
        $authorId = $this->insertUser('standing-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        $app = $this->createApp();

        // 5 separate comments moderated one at a time — each recordAction()
        // call escalates the SAME author's standing by one violation.
        for ($i = 1; $i <= 5; $i++) {
            $commentId = $this->seedComment($ideaId, $authorId, "Comment #{$i}");
            $response  = $app->handle($this->postDeleteComment('standing-escalation-board', $ideaId, $commentId, $modId));
            self::assertSame(200, $response->getStatusCode(), "moderation #{$i} should succeed");
        }

        $row = $this->conn->fetchAssociative(
            'SELECT violation_count, state, state_changed_by_user_id FROM account_member_standing WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $authorId],
        );

        self::assertIsArray($row);
        self::assertSame(5, (int) $row['violation_count']);
        self::assertSame('suspended', $row['state']);
        self::assertNull($row['state_changed_by_user_id'], 'automatic change must leave state_changed_by_user_id NULL');
    }

    public function test_idea_moderation_escalates_standing_to_flagged_on_first_violation(): void
    {
        $boardId  = $this->insertBoard('standing-idea-board');
        $modId    = $this->insertUser('standing-idea-mod@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'owner');
        $authorId = $this->insertUser('standing-idea-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/standing-idea-board/ideas/' . $ideaId . '/moderate')
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($modId)])
            ->withParsedBody(['_csrf' => $token, 'reason_code' => 'community_rule']);

        $response = $this->createApp()->handle($request);
        self::assertSame(200, $response->getStatusCode());

        $row = $this->conn->fetchAssociative(
            'SELECT violation_count, state FROM account_member_standing WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $authorId],
        );
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['violation_count']);
        self::assertSame('flagged', $row['state']);
    }

    // -------------------------------------------------------------------------
    // Suspended member rejected from submitting new content
    // -------------------------------------------------------------------------

    public function test_suspended_member_cannot_create_idea(): void
    {
        $boardId = $this->insertBoard('standing-suspended-idea-board');
        $userId  = $this->insertUser('standing-suspended-idea@example.com');
        $this->seedStanding($this->defaultAccountId(), $userId, 'suspended', 5);

        $response = $this->createApp()->handle($this->postIdea(
            'standing-suspended-idea-board',
            ['title' => 'Should be rejected', 'body' => 'This must never be persisted.'],
            $userId,
        ));

        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('member_suspended', $data['error']['key'] ?? null);

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :b', ['b' => $boardId]));
    }

    public function test_suspended_member_cannot_create_comment(): void
    {
        $boardId  = $this->insertBoard('standing-suspended-comment-board');
        $authorId = $this->insertUser('standing-suspended-comment-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);
        $userId   = $this->insertUser('standing-suspended-comment@example.com');
        $this->seedStanding($this->defaultAccountId(), $userId, 'suspended', 5);

        $response = $this->createApp()->handle($this->postComment(
            'standing-suspended-comment-board',
            $ideaId,
            ['body' => 'Should be rejected'],
            $userId,
        ));

        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('member_suspended', $data['error']['key'] ?? null);

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM comments WHERE idea_id = :i', ['i' => $ideaId]));
    }

    public function test_flagged_member_can_still_create_idea(): void
    {
        $this->insertBoard('standing-flagged-idea-board');
        $userId  = $this->insertUser('standing-flagged-idea@example.com');
        $this->seedStanding($this->defaultAccountId(), $userId, 'flagged', 1);

        $response = $this->createApp()->handle($this->postIdea(
            'standing-flagged-idea-board',
            ['title' => 'Still allowed', 'body' => 'A flagged, non-suspended member can still submit.'],
            $userId,
        ));

        self::assertSame(201, $response->getStatusCode());
    }
}
