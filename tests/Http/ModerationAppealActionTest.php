<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the appeal path (board-operator-compliance issue
 * 09): POST /appeals (submit), GET /moderation/appeals (moderator inbox),
 * POST /moderation/appeals/{id}/resolve. All assertions run exclusively
 * through the HTTP seam (AppFactory::create), same convention as
 * CommentModerationActionTest/IdeaModerationActionTest.
 */
final class ModerationAppealActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function postAppeal(array $body, ?int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/appeals')
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    private function getAppeals(?int $userId): ServerRequestInterface
    {
        $cookies = [];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/moderation/appeals')
            ->withCookieParams($cookies);
    }

    /** @param array<string, mixed> $body */
    private function postResolve(int $appealId, array $body, ?int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/moderation/appeals/' . $appealId . '/resolve')
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    /** @return array{boardId: int, authorId: int, moderationActionId: int, ideaId: int} */
    private function fixtureRemovedIdea(): array
    {
        $boardId  = $this->insertBoard('appeal-board-' . uniqid());
        $authorId = $this->insertUser('appeal-author-' . uniqid() . '@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Removed idea');
        $moderationActionId = $this->seedModerationAction(
            'idea',
            $ideaId,
            $boardId,
            $this->defaultAccountId(),
            $this->insertUser('appeal-mod-' . uniqid() . '@example.com'),
        );
        $this->conn->update(
            'ideas',
            ['deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'moderation_action_id' => $moderationActionId],
            ['id' => $ideaId],
        );

        return ['boardId' => $boardId, 'authorId' => $authorId, 'moderationActionId' => $moderationActionId, 'ideaId' => $ideaId];
    }

    // -------------------------------------------------------------------------
    // submit()
    // -------------------------------------------------------------------------

    public function test_original_author_can_submit_an_appeal(): void
    {
        $f = $this->fixtureRemovedIdea();

        $response = $this->createApp()->handle($this->postAppeal([
            'moderation_action_id' => $f['moderationActionId'],
            'message'               => 'I did not violate any rules, please reconsider.',
        ], $f['authorId']));

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        $appealId = $data['id'] ?? null;
        self::assertIsInt($appealId);

        $row = $this->conn->fetchAssociative('SELECT * FROM content_moderation_appeals WHERE id = :id', ['id' => $appealId]);
        self::assertIsArray($row);
        self::assertSame($f['moderationActionId'], (int) $row['moderation_action_id']);
        self::assertSame($f['authorId'], (int) $row['submitted_by_user_id']);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['resolved_at']);

        $log = $this->readAuditLog();
        self::assertStringContainsString('moderation.appeal_submitted', $log);
    }

    public function test_anon_cannot_submit_an_appeal(): void
    {
        $f = $this->fixtureRemovedIdea();

        $response = $this->createApp()->handle($this->postAppeal([
            'moderation_action_id' => $f['moderationActionId'],
            'message'               => 'Please reconsider this removal decision.',
        ], null));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_non_author_submitting_an_appeal_returns_403(): void
    {
        $f          = $this->fixtureRemovedIdea();
        $outsiderId = $this->insertUser('appeal-outsider-' . uniqid() . '@example.com');

        $response = $this->createApp()->handle($this->postAppeal([
            'moderation_action_id' => $f['moderationActionId'],
            'message'               => 'This was not even my content, but I am trying anyway.',
        ], $outsiderId));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM content_moderation_appeals'));
    }

    public function test_appeal_against_a_foreign_accounts_moderation_action_returns_404(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'appeal-foreign-acct-' . uniqid()]);
        $foreignBoardId   = $this->insertBoard('appeal-foreign-board-' . uniqid(), ['account_id' => $foreignAccountId]);
        $foreignAuthorId  = $this->insertUser('appeal-foreign-author-' . uniqid() . '@example.com');
        $foreignIdeaId    = $this->seedIdea($foreignBoardId, $foreignAuthorId);
        $foreignModActionId = $this->seedModerationAction(
            'idea',
            $foreignIdeaId,
            $foreignBoardId,
            $foreignAccountId,
            $this->insertUser('appeal-foreign-mod-' . uniqid() . '@example.com'),
        );
        $this->conn->update(
            'ideas',
            ['deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'moderation_action_id' => $foreignModActionId],
            ['id' => $foreignIdeaId],
        );

        // foreignAuthorId is the actual author, but reached via the default
        // account's routing context — the moderation action belongs to a
        // DIFFERENT account, so this must 404 (tenant isolation), same
        // convention as everywhere else — never 403 (would leak existence).
        $response = $this->createApp()->handle($this->postAppeal([
            'moderation_action_id' => $foreignModActionId,
            'message'               => 'Trying to appeal across account boundaries.',
        ], $foreignAuthorId));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM content_moderation_appeals'));
    }

    public function test_unknown_moderation_action_id_returns_404(): void
    {
        $authorId = $this->insertUser('appeal-unknown-' . uniqid() . '@example.com');

        $response = $this->createApp()->handle($this->postAppeal([
            'moderation_action_id' => 999999,
            'message'               => 'This moderation action does not exist at all.',
        ], $authorId));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_second_appeal_while_first_is_pending_returns_409(): void
    {
        $f = $this->fixtureRemovedIdea();

        $app   = $this->createApp();
        $first = $app->handle($this->postAppeal([
            'moderation_action_id' => $f['moderationActionId'],
            'message'               => 'First appeal attempt, please review this.',
        ], $f['authorId']));
        self::assertSame(201, $first->getStatusCode());

        $second = $app->handle($this->postAppeal([
            'moderation_action_id' => $f['moderationActionId'],
            'message'               => 'Second appeal attempt while the first is still pending.',
        ], $f['authorId']));

        self::assertSame(409, $second->getStatusCode());
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM content_moderation_appeals'));
    }

    public function test_a_new_appeal_is_allowed_once_the_previous_one_is_resolved(): void
    {
        $f = $this->fixtureRemovedIdea();
        $this->seedAppeal($f['moderationActionId'], $f['authorId'], overrides: [
            'status'      => 'upheld',
            'resolved_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $response = $this->createApp()->handle($this->postAppeal([
            'moderation_action_id' => $f['moderationActionId'],
            'message'               => 'Appealing again after the first was resolved.',
        ], $f['authorId']));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(2, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM content_moderation_appeals'));
    }

    public function test_missing_message_returns_422(): void
    {
        $f = $this->fixtureRemovedIdea();

        $response = $this->createApp()->handle($this->postAppeal([
            'moderation_action_id' => $f['moderationActionId'],
        ], $f['authorId']));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('message', $data['error']['fields'] ?? []);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM content_moderation_appeals'));
    }

    public function test_a_removed_comments_author_can_also_appeal(): void
    {
        $boardId   = $this->insertBoard('appeal-comment-board-' . uniqid());
        $authorId  = $this->insertUser('appeal-comment-author-' . uniqid() . '@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);
        $commentId = $this->seedComment($ideaId, $authorId, 'Spammy content');
        $moderationActionId = $this->seedModerationAction(
            'comment',
            $commentId,
            $boardId,
            $this->defaultAccountId(),
            $this->insertUser('appeal-comment-mod-' . uniqid() . '@example.com'),
        );
        $this->conn->update(
            'comments',
            ['deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'moderation_action_id' => $moderationActionId],
            ['id' => $commentId],
        );

        $response = $this->createApp()->handle($this->postAppeal([
            'moderation_action_id' => $moderationActionId,
            'message'               => 'This comment did not actually violate the rules.',
        ], $authorId));

        self::assertSame(201, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // list() / resolve()
    // -------------------------------------------------------------------------

    public function test_moderator_can_list_appeals_for_their_own_account(): void
    {
        $f = $this->fixtureRemovedIdea();
        $this->seedAppeal($f['moderationActionId'], $f['authorId'], 'Please review my case.');
        $modId = $this->insertUser('appeal-listmod-' . uniqid() . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->getAppeals($modId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $data['appeals'] ?? []);
        self::assertSame($f['moderationActionId'], $data['appeals'][0]['moderation_action_id']);
    }

    public function test_list_does_not_include_a_foreign_accounts_appeals(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'appeal-list-foreign-' . uniqid()]);
        $foreignBoardId   = $this->insertBoard('appeal-list-foreign-board-' . uniqid(), ['account_id' => $foreignAccountId]);
        $foreignAuthorId  = $this->insertUser('appeal-list-foreign-author-' . uniqid() . '@example.com');
        $foreignIdeaId    = $this->seedIdea($foreignBoardId, $foreignAuthorId);
        $foreignModActionId = $this->seedModerationAction('idea', $foreignIdeaId, $foreignBoardId, $foreignAccountId, $foreignAuthorId);
        $this->seedAppeal($foreignModActionId, $foreignAuthorId);

        $modId = $this->insertUser('appeal-list-mod-' . uniqid() . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->getAppeals($modId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([], $data['appeals'] ?? null);
    }

    public function test_non_moderator_cannot_list_appeals(): void
    {
        $memberId = $this->insertUser('appeal-list-member-' . uniqid() . '@example.com');

        $response = $this->createApp()->handle($this->getAppeals($memberId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_moderator_can_resolve_an_appeal_and_it_is_visible_to_the_appellant(): void
    {
        $f        = $this->fixtureRemovedIdea();
        $appealId = $this->seedAppeal($f['moderationActionId'], $f['authorId']);
        $modId    = $this->insertUser('appeal-resolve-mod-' . uniqid() . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postResolve($appealId, ['status' => 'overturned'], $modId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('overturned', $data['status'] ?? null);

        $row = $this->conn->fetchAssociative('SELECT status, resolved_by_user_id, resolved_at FROM content_moderation_appeals WHERE id = :id', ['id' => $appealId]);
        self::assertIsArray($row);
        self::assertSame('overturned', $row['status']);
        self::assertSame($modId, (int) $row['resolved_by_user_id']);
        self::assertNotNull($row['resolved_at']);

        $log = $this->readAuditLog();
        self::assertStringContainsString('moderation.appeal_resolved', $log);
    }

    public function test_resolving_with_an_invalid_status_returns_422(): void
    {
        $f        = $this->fixtureRemovedIdea();
        $appealId = $this->seedAppeal($f['moderationActionId'], $f['authorId']);
        $modId    = $this->insertUser('appeal-resolve-invalid-' . uniqid() . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postResolve($appealId, ['status' => 'not_a_real_status'], $modId));

        self::assertSame(422, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT status FROM content_moderation_appeals WHERE id = :id', ['id' => $appealId]);
        self::assertIsArray($row);
        self::assertSame('pending', $row['status']);
    }

    public function test_resolving_a_foreign_accounts_appeal_returns_404(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'appeal-resolve-foreign-' . uniqid()]);
        $foreignBoardId   = $this->insertBoard('appeal-resolve-foreign-board-' . uniqid(), ['account_id' => $foreignAccountId]);
        $foreignAuthorId  = $this->insertUser('appeal-resolve-foreign-author-' . uniqid() . '@example.com');
        $foreignIdeaId    = $this->seedIdea($foreignBoardId, $foreignAuthorId);
        $foreignModActionId = $this->seedModerationAction('idea', $foreignIdeaId, $foreignBoardId, $foreignAccountId, $foreignAuthorId);
        $foreignAppealId  = $this->seedAppeal($foreignModActionId, $foreignAuthorId);

        $modId = $this->insertUser('appeal-resolve-foreign-mod-' . uniqid() . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postResolve($foreignAppealId, ['status' => 'upheld'], $modId));

        self::assertSame(404, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT status FROM content_moderation_appeals WHERE id = :id', ['id' => $foreignAppealId]);
        self::assertIsArray($row);
        self::assertSame('pending', $row['status']);
    }

    public function test_resolving_an_unknown_appeal_returns_404(): void
    {
        $modId = $this->insertUser('appeal-resolve-unknown-' . uniqid() . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postResolve(999999, ['status' => 'upheld'], $modId));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_non_moderator_cannot_resolve_an_appeal(): void
    {
        $f        = $this->fixtureRemovedIdea();
        $appealId = $this->seedAppeal($f['moderationActionId'], $f['authorId']);
        $memberId = $this->insertUser('appeal-resolve-member-' . uniqid() . '@example.com');

        $response = $this->createApp()->handle($this->postResolve($appealId, ['status' => 'upheld'], $memberId));

        self::assertSame(403, $response->getStatusCode());
    }
}
