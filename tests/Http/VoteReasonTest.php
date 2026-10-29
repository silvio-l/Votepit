<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\AnonymousVoteCookie;
use Votepit\Security\AnonymousVoterHasher;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * P-03 "concerns with context": optional reason for a down-vote.
 *
 * Covers: reason only on a down vote, board opt-in (default off), plain text
 * limit (200 code points), fixed reason enum, switch to For and withdrawal
 * remove the reason, guest identity, frozen board, cross-tenant 404, role
 * matrix of the operator aggregate (owner/moderator only, no identities),
 * and the erasure path that drops free text.
 */
final class VoteReasonTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SLUG    = 'demo';

    private function csrf(): CsrfService
    {
        return new CsrfService(self::APP_KEY, 3600, false);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $cookies
     */
    private function post(string $path, array $fields, ?int $userId = null, array $cookies = []): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $cookies[$csrf->cookieName()] = $csrf->sign($token);
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams($cookies)
            ->withParsedBody($fields + ['_csrf' => $token]);
    }

    private function get(string $path, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);

        return $userId === null ? $request : $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
    }

    /** @return App<null> */
    private function app(): App
    {
        return $this->createApp();
    }

    /** @return array{int, int, int} [boardId, authorId, ideaId] on a board with reasons enabled. */
    private function seedEnabledBoard(string $slug = self::SLUG): array
    {
        $boardId  = $this->insertBoard($slug, ['vote_reasons_enabled' => 1]);
        $authorId = $this->insertUser('author-' . $slug . '@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Dark mode');

        return [$boardId, $authorId, $ideaId];
    }

    private function memberWithDownVote(int $ideaId): int
    {
        $userId = $this->insertUser('member-' . $ideaId . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'member');
        $this->seedVote($ideaId, $userId, -1);

        return $userId;
    }

    /** @return array{reason: string, note: string|null}|null */
    private function reasonRow(int $ideaId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT r.reason, r.note FROM vote_reasons r INNER JOIN votes v ON v.id = r.vote_id WHERE v.idea_id = ?',
            [$ideaId],
        );

        if (!is_array($row) || !is_string($row['reason'] ?? null)) {
            return null;
        }

        return ['reason' => $row['reason'], 'note' => is_string($row['note'] ?? null) ? $row['note'] : null];
    }

    private function reasonPath(int $ideaId): string
    {
        return '/' . self::SLUG . '/ideas/' . $ideaId . '/vote/reason';
    }

    public function test_down_voter_can_add_a_reason_and_note_is_stored_as_plain_text(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();
        $voterId = $this->memberWithDownVote($ideaId);

        $response = $this->app()->handle($this->post($this->reasonPath($ideaId), ['reason' => 'too_expensive', 'note' => '<b>Kosten</b> & Zeit'], $voterId));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['reason' => 'too_expensive', 'note' => '<b>Kosten</b> & Zeit'], $this->reasonRow($ideaId));
        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame(['ok' => true, 'reason' => 'too_expensive', 'note' => '<b>Kosten</b> & Zeit'], $payload);
    }

    public function test_changing_the_reason_replaces_the_row(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();
        $voterId = $this->memberWithDownVote($ideaId);
        $app     = $this->app();

        $app->handle($this->post($this->reasonPath($ideaId), ['reason' => 'not_needed'], $voterId));
        $app->handle($this->post($this->reasonPath($ideaId), ['reason' => 'other', 'note' => 'Anderes'], $voterId));

        self::assertSame(['reason' => 'other', 'note' => 'Anderes'], $this->reasonRow($ideaId));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM vote_reasons'));
    }

    public function test_note_limit_counts_code_points_not_bytes(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();
        $voterId = $this->memberWithDownVote($ideaId);
        $app     = $this->app();

        $ok = $app->handle($this->post($this->reasonPath($ideaId), ['reason' => 'other', 'note' => str_repeat('ä', 200)], $voterId));  // export-ok: comment-language
        self::assertSame(200, $ok->getStatusCode());

        $tooLong = $app->handle($this->post($this->reasonPath($ideaId), ['reason' => 'other', 'note' => str_repeat('ä', 201)], $voterId));  // export-ok: comment-language
        self::assertSame(422, $tooLong->getStatusCode());
        self::assertStringContainsString('note_too_long', (string) $tooLong->getBody());
    }

    public function test_unknown_reason_is_rejected_without_a_write(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();
        $voterId = $this->memberWithDownVote($ideaId);

        $response = $this->app()->handle($this->post($this->reasonPath($ideaId), ['reason' => 'free_text_only'], $voterId));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('invalid_reason', (string) $response->getBody());
        self::assertNull($this->reasonRow($ideaId));
    }

    public function test_reason_needs_an_existing_down_vote(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();
        $upVoterId = $this->insertUser('up-only@example.com');
        $this->seedVote($ideaId, $upVoterId, 1);

        $response = $this->app()->handle($this->post($this->reasonPath($ideaId), ['reason' => 'other'], $upVoterId));

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('no_down_vote', (string) $response->getBody());
        self::assertNull($this->reasonRow($ideaId));
    }

    public function test_feature_is_off_by_default_and_rejects_reasons(): void
    {
        $boardId  = $this->insertBoard(self::SLUG);
        $authorId = $this->insertUser('off-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Off board');
        $voterId  = $this->memberWithDownVote($ideaId);

        $response = $this->app()->handle($this->post($this->reasonPath($ideaId), ['reason' => 'other'], $voterId));

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('vote_reasons_disabled', (string) $response->getBody());
        self::assertNull($this->reasonRow($ideaId));
    }

    public function test_installation_kill_switch_refuses_reasons_even_where_the_board_flag_is_on(): void
    {
        $boardId  = $this->insertBoard(self::SLUG, ['vote_reasons_enabled' => 1]);
        $authorId = $this->insertUser('kill-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Kill switch');
        $voterId  = $this->memberWithDownVote($ideaId);
        $app      = $this->createApp(config: Config::fromArray($this->testConfigArray(['vote_reasons' => false])));

        $response = $app->handle($this->post($this->reasonPath($ideaId), ['reason' => 'other'], $voterId));

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('vote_reasons_disabled', (string) $response->getBody());
        self::assertNull($this->reasonRow($ideaId));
    }

    public function test_switching_to_for_removes_the_reason(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();
        $voterId = $this->memberWithDownVote($ideaId);
        $app     = $this->app();
        $app->handle($this->post($this->reasonPath($ideaId), ['reason' => 'worsens_workflow'], $voterId));

        $switch = $app->handle($this->post('/' . self::SLUG . '/ideas/' . $ideaId . '/vote', ['value' => 'up'], $voterId));

        self::assertSame(200, $switch->getStatusCode());
        self::assertNull($this->reasonRow($ideaId));
    }

    public function test_withdrawing_the_down_vote_removes_the_reason(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();
        $voterId = $this->memberWithDownVote($ideaId);
        $app     = $this->app();
        $app->handle($this->post($this->reasonPath($ideaId), ['reason' => 'not_needed', 'note' => 'weg damit'], $voterId));  // export-ok: comment-language

        $withdraw = $app->handle($this->post('/' . self::SLUG . '/ideas/' . $ideaId . '/vote', ['value' => 'down'], $voterId));

        self::assertSame(200, $withdraw->getStatusCode());
        self::assertNull($this->reasonRow($ideaId));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = ?', [$ideaId]));
    }

    public function test_deleting_the_idea_cascades_to_reasons(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();
        $voterId = $this->memberWithDownVote($ideaId);
        $this->app()->handle($this->post($this->reasonPath($ideaId), ['reason' => 'other', 'note' => 'x'], $voterId));

        $this->conn->executeStatement('DELETE FROM ideas WHERE id = ?', [$ideaId]);

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM vote_reasons'));
    }

    public function test_guest_with_anonymous_vote_cookie_can_add_a_reason(): void
    {
        $boardId = $this->insertBoard(self::SLUG, ['vote_reasons_enabled' => 1, 'anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('guest-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author, 'Guest idea');
        $token   = AnonymousVoterHasher::generateToken();
        $hash    = (new AnonymousVoterHasher(self::identityServerKey()))->hashToken($token);
        $this->conn->insert('votes', ['idea_id' => $ideaId, 'user_id' => null, 'voter_token_hash' => $hash, 'value' => -1, 'created_at' => '2026-10-25 10:00:00']);

        $response = $this->app()->handle($this->post(
            $this->reasonPath($ideaId),
            ['reason' => 'too_expensive'],
            null,
            [AnonymousVoteCookie::COOKIE_NAME => $token],
        ));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('too_expensive', $this->reasonRow($ideaId)['reason'] ?? null);
    }

    public function test_guest_without_a_vote_cookie_gets_401(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();

        $response = $this->app()->handle($this->post($this->reasonPath($ideaId), ['reason' => 'other']));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_frozen_board_refuses_the_reason_and_writes_nothing(): void
    {
        $boardId  = $this->insertBoard(self::SLUG, ['vote_reasons_enabled' => 1, 'frozen_at' => '2026-10-01 00:00:00']);
        $authorId = $this->insertUser('frozen-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Frozen');
        $voterId  = $this->memberWithDownVote($ideaId);

        $response = $this->app()->handle($this->post($this->reasonPath($ideaId), ['reason' => 'other'], $voterId));

        self::assertSame(423, $response->getStatusCode());
        self::assertStringContainsString('board_frozen', (string) $response->getBody());
        self::assertNull($this->reasonRow($ideaId));
    }

    public function test_foreign_account_board_is_not_found(): void
    {
        $foreignAccount = $this->insertAccount();
        $foreignBoard   = $this->insertBoard('foreign', ['account_id' => $foreignAccount, 'vote_reasons_enabled' => 1]);
        $author         = $this->insertUser('foreign-author@example.com');
        $ideaId         = $this->seedIdea($foreignBoard, $author, 'Foreign');
        $voterId        = $this->memberWithDownVote($ideaId);

        $response = $this->app()->handle($this->post('/foreign/ideas/' . $ideaId . '/vote/reason', ['reason' => 'other'], $voterId));

        self::assertSame(404, $response->getStatusCode());
        self::assertNull($this->reasonRow($ideaId));
    }

    public function test_operator_view_shows_counts_and_texts_without_identities_to_owner_and_moderator_only(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();
        $voterId = $this->memberWithDownVote($ideaId);
        $this->app()->handle($this->post($this->reasonPath($ideaId), ['reason' => 'too_expensive', 'note' => 'Zu teuer'], $voterId));

        $ownerId = $this->insertUser('owner-view@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $moderatorId = $this->insertUser('mod-view@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $moderatorId, 'moderator');
        $path = '/admin/boards/' . self::SLUG . '/ideas/' . $ideaId . '/vote-reasons';

        $owner = $this->app()->handle($this->get($path, $ownerId));
        self::assertSame(200, $owner->getStatusCode());
        $data = json_decode((string) $owner->getBody(), true);
        self::assertTrue($data['enabled']);
        self::assertSame(1, $data['down_count']);
        self::assertSame(['not_needed' => 0, 'too_expensive' => 1, 'worsens_workflow' => 0, 'other' => 0], $data['counts']);
        self::assertSame([['reason' => 'too_expensive', 'note' => 'Zu teuer']], $data['notes']);
        $raw = (string) $owner->getBody();
        self::assertStringNotContainsString('member-', $raw);
        self::assertStringNotContainsString('voter', $raw);
        self::assertStringNotContainsString('user_id', $raw);

        self::assertSame(200, $this->app()->handle($this->get($path, $moderatorId))->getStatusCode());

        $member = $this->insertUser('plain-member@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $member, 'member');
        self::assertSame(403, $this->app()->handle($this->get($path, $member))->getStatusCode());
        self::assertSame(401, $this->app()->handle($this->get($path, null))->getStatusCode());
    }

    public function test_operator_view_of_a_foreign_board_is_not_found(): void
    {
        $foreignAccount = $this->insertAccount();
        $foreignBoard   = $this->insertBoard('foreign-ops', ['account_id' => $foreignAccount, 'vote_reasons_enabled' => 1]);
        $author         = $this->insertUser('foreign-ops-author@example.com');
        $ideaId         = $this->seedIdea($foreignBoard, $author, 'Foreign ops');
        $ownerId        = $this->insertUser('own-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->app()->handle($this->get('/admin/boards/foreign-ops/ideas/' . $ideaId . '/vote-reasons', $ownerId));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_erasure_keeps_reason_but_drops_free_text_of_the_erased_voter(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();
        $voterId = $this->memberWithDownVote($ideaId);
        $this->app()->handle($this->post($this->reasonPath($ideaId), ['reason' => 'other', 'note' => 'Name: Erika'], $voterId));

        (new \Votepit\Persistence\VoteReasonRepository($this->conn))->clearNotesForVotes(
            array_map(intval(...), $this->conn->fetchFirstColumn('SELECT id FROM votes WHERE idea_id = ?', [$ideaId])),
        );

        self::assertSame(['reason' => 'other', 'note' => null], $this->reasonRow($ideaId));
    }

    private function removePath(int $ideaId, string $slug = self::SLUG): string
    {
        return '/admin/boards/' . $slug . '/ideas/' . $ideaId . '/vote-reasons/note/remove';
    }

    public function test_moderator_removes_one_free_text_and_the_reason_choice_stays(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();
        $voterId = $this->memberWithDownVote($ideaId);
        $this->app()->handle($this->post($this->reasonPath($ideaId), ['reason' => 'too_expensive', 'note' => 'Zu teuer'], $voterId));
        $moderatorId = $this->insertUser('mod-remove@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $moderatorId, 'moderator');

        $response = $this->app()->handle($this->post($this->removePath($ideaId), ['reason' => 'too_expensive', 'note' => 'Zu teuer'], $moderatorId));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['reason' => 'too_expensive', 'note' => null], $this->reasonRow($ideaId));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = ? AND value = -1', [$ideaId]));
    }

    public function test_removal_matches_the_exact_text_and_leaves_case_variants_alone(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();
        $voterId = $this->memberWithDownVote($ideaId);
        $this->app()->handle($this->post($this->reasonPath($ideaId), ['reason' => 'other', 'note' => 'Zu teuer'], $voterId));
        $ownerId = $this->insertUser('owner-exact@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $miss = $this->app()->handle($this->post($this->removePath($ideaId), ['reason' => 'other', 'note' => 'zu teuer'], $ownerId));

        self::assertSame(404, $miss->getStatusCode());
        self::assertSame(['reason' => 'other', 'note' => 'Zu teuer'], $this->reasonRow($ideaId));
    }

    public function test_removal_with_the_wrong_reason_or_an_unknown_choice_writes_nothing(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();
        $voterId = $this->memberWithDownVote($ideaId);
        $this->app()->handle($this->post($this->reasonPath($ideaId), ['reason' => 'other', 'note' => 'Text'], $voterId));
        $ownerId = $this->insertUser('owner-wrong@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $app = $this->app();

        self::assertSame(404, $app->handle($this->post($this->removePath($ideaId), ['reason' => 'not_needed', 'note' => 'Text'], $ownerId))->getStatusCode());
        $invalid = $app->handle($this->post($this->removePath($ideaId), ['reason' => 'free_text_only', 'note' => 'Text'], $ownerId));
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(422, $app->handle($this->post($this->removePath($ideaId), ['reason' => 'other', 'note' => '   '], $ownerId))->getStatusCode());
        self::assertSame(['reason' => 'other', 'note' => 'Text'], $this->reasonRow($ideaId));
    }

    public function test_plain_member_cannot_remove_a_free_text(): void
    {
        [, , $ideaId] = $this->seedEnabledBoard();
        $voterId = $this->memberWithDownVote($ideaId);
        $this->app()->handle($this->post($this->reasonPath($ideaId), ['reason' => 'other', 'note' => 'Text'], $voterId));
        $member = $this->insertUser('plain-remove@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $member, 'member');

        $response = $this->app()->handle($this->post($this->removePath($ideaId), ['reason' => 'other', 'note' => 'Text'], $member));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['reason' => 'other', 'note' => 'Text'], $this->reasonRow($ideaId));
    }

    public function test_removal_on_a_foreign_board_is_not_found_and_writes_nothing(): void
    {
        $foreignAccount = $this->insertAccount();
        $foreignBoard   = $this->insertBoard('foreign-remove', ['account_id' => $foreignAccount, 'vote_reasons_enabled' => 1]);
        $author         = $this->insertUser('foreign-remove-author@example.com');
        $ideaId         = $this->seedIdea($foreignBoard, $author, 'Foreign remove');
        $this->memberWithDownVote($ideaId);
        $this->conn->insert('vote_reasons', ['vote_id' => (int) $this->conn->fetchOne('SELECT id FROM votes WHERE idea_id = ?', [$ideaId]), 'reason' => 'other', 'note' => 'Fremd']);
        $ownerId = $this->insertUser('own-remove-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->app()->handle($this->post($this->removePath($ideaId, 'foreign-remove'), ['reason' => 'other', 'note' => 'Fremd'], $ownerId));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['reason' => 'other', 'note' => 'Fremd'], $this->reasonRow($ideaId));
    }

    public function test_removal_stays_open_on_a_frozen_board(): void
    {
        $boardId  = $this->insertBoard(self::SLUG, ['vote_reasons_enabled' => 1, 'frozen_at' => '2026-10-01 00:00:00']);
        $authorId = $this->insertUser('frozen-remove-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Frozen remove');
        $this->memberWithDownVote($ideaId);
        $this->conn->insert('vote_reasons', ['vote_id' => (int) $this->conn->fetchOne('SELECT id FROM votes WHERE idea_id = ?', [$ideaId]), 'reason' => 'other', 'note' => 'Eingefroren']);
        $ownerId = $this->insertUser('frozen-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->app()->handle($this->post($this->removePath($ideaId), ['reason' => 'other', 'note' => 'Eingefroren'], $ownerId));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['reason' => 'other', 'note' => null], $this->reasonRow($ideaId));
    }
}
