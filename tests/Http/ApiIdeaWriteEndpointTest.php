<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\TokenVault;
use Votepit\Security\Webhook\WebhookSenderInterface;
use Votepit\Security\Webhook\WebhookSendResult;
use Votepit\Security\Webhook\WebhookTarget;
use Votepit\Tests\Support\FixedClock;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Agent API idea write endpoints (competitor-parity issue 15):
 * vote, comment, status, tag assign/remove, merge/unmerge, plus `q` search
 * and the get_board status/tag discovery.
 *
 * Critical path = account scoping: every endpoint is exercised with a token
 * of a FOREIGN account, a token without a grant for the board (`?board=`
 * of a board it cannot reach) and a foreign idea id through the own grant.
 */
final class ApiIdeaWriteEndpointTest extends IntegrationTestCase
{
    private ?object $sender = null;

    /** @param array<string, mixed>|null $body */
    private function bearer(string $method, string $path, string $token, ?array $body = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withHeader('Authorization', 'Bearer ' . $token);

        return $body !== null ? $request->withHeader('Content-Type', 'application/json')->withParsedBody($body) : $request;
    }

    private function token(int $accountId, int $boardId, int $issuerId, string $scope): string
    {
        $pair = (new TokenVault())->generate();
        $this->insertApiToken($accountId, $boardId, $issuerId, $pair['hash'], 'Agent ' . $scope, scope: $scope);

        return $pair['token'];
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data, (string) $response->getBody());

        return $data;
    }

    /** @return App<null> */
    private function app(): App
    {
        $this->sender = new class () implements WebhookSenderInterface {
            /** @var list<array<string, mixed>> */
            public array $events = [];

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                $decoded        = json_decode($body, true);
                $this->events[] = is_array($decoded) ? $decoded : [];

                return WebhookSendResult::response(200, null);
            }
        };

        return $this->createApp(
            webhookSender: $this->sender,
            webhookDeferRunner: static fn (\Closure $work): mixed => $work(),
        );
    }

    /** @return list<string> */
    private function webhookEvents(): array
    {
        /** @var list<array<string, mixed>> $events */
        $events = $this->sender->events ?? [];

        return array_map(static fn (array $e): string => (string) ($e['event'] ?? ''), $events);
    }

    /** @param App<null> $app */
    private function configureWebhook(App $app, string $slug, int $ownerId): void
    {
        $csrf     = new CsrfService(str_repeat('a', 64), 3600, false);
        $csrfTok  = $csrf->generate();
        $request  = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/admin/boards/' . $slug . '/webhook')
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($csrfTok), 'votepit_sess' => $this->sessionCookie($ownerId)])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-CSRF-Token', $csrfTok)
            ->withParsedBody(['url' => 'https://93.184.216.34/hook']);

        self::assertSame(200, $app->handle($request)->getStatusCode());
    }

    /** @return array{0: int, 1: int} [boardId, ownerId] of the default account */
    private function ownBoard(string $slug = 'demo'): array
    {
        $boardId = $this->insertBoard($slug);
        $ownerId = $this->insertUser($slug . '-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        return [$boardId, $ownerId];
    }

    /**
     * Every new write endpoint with a valid body for idea $id.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>|null}>
     */
    private function writeCalls(int $id, int $otherId, int $tagId): array
    {
        return [
            ['POST', '/api/v1/ideas/' . $id . '/vote', ['value' => 'up']],
            ['POST', '/api/v1/ideas/' . $id . '/comments', ['body' => 'From the agent.']],
            ['POST', '/api/v1/ideas/' . $id . '/status', ['status' => 'planned']],
            ['POST', '/api/v1/ideas/' . $id . '/tags', ['tag_id' => $tagId]],
            ['DELETE', '/api/v1/ideas/' . $id . '/tags/' . $tagId, null],
            ['POST', '/api/v1/ideas/' . $id . '/merge', ['target_id' => $otherId]],
            ['POST', '/api/v1/ideas/' . $id . '/unmerge', null],
        ];
    }

    // ---------------------------------------------------------------------
    // Account scoping (critical path)
    // ---------------------------------------------------------------------

    public function test_a_foreign_account_token_can_never_touch_another_accounts_idea(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $ideaId   = $this->seedIdea($boardId, $ownerId, 'Own idea');
        $otherId  = $this->seedIdea($boardId, $ownerId, 'Own target');
        $tagId    = $this->insertTag($boardId, 'Bug');
        $this->assignTag($ideaId, $tagId);

        $foreignAccount = $this->insertAccount();
        $foreignBoard   = $this->insertBoard('foreign', ['account_id' => $foreignAccount, 'is_default' => 0]);
        $foreignOwner   = $this->insertUser('foreign-owner@example.com');
        $this->insertAccountMember($foreignAccount, $foreignOwner, 'owner');
        $foreignToken = $this->token($foreignAccount, $foreignBoard, $foreignOwner, 'moderate');
        $app          = $this->app();

        foreach ($this->writeCalls($ideaId, $otherId, $tagId) as [$method, $path, $body]) {
            // Same numeric ids, but resolved through the foreign token's own board.
            self::assertSame(404, $app->handle($this->bearer($method, $path, $foreignToken, $body))->getStatusCode(), $method . ' ' . $path);
            // Naming the victim's board via ?board= is not a grant.
            self::assertSame(403, $app->handle($this->bearer($method, $path . '?board=demo', $foreignToken, $body))->getStatusCode(), $method . ' ' . $path . '?board=demo');
        }

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM comments'));
        self::assertSame('open', $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$ideaId]));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tag_assignments WHERE idea_id = ?', [$ideaId]));
        self::assertNull($this->conn->fetchOne('SELECT merged_into_id FROM ideas WHERE id = ?', [$ideaId]));
        self::assertSame([], $this->webhookEvents());
    }

    public function test_a_token_cannot_write_to_a_board_of_its_own_account_without_a_grant(): void
    {
        [$boardA, $ownerId] = $this->ownBoard('board-a');
        $boardB             = $this->insertBoard('board-b', ['is_default' => 0]);
        $ideaB              = $this->seedIdea($boardB, $ownerId, 'Idea on B');
        $targetB            = $this->seedIdea($boardB, $ownerId, 'Target on B');
        $tagB               = $this->insertTag($boardB, 'B tag');
        $token              = $this->token($this->defaultAccountId(), $boardA, $ownerId, 'moderate');
        $app                = $this->app();

        foreach ($this->writeCalls($ideaB, $targetB, $tagB) as [$method, $path, $body]) {
            self::assertSame(404, $app->handle($this->bearer($method, $path, $token, $body))->getStatusCode(), $method . ' ' . $path);
            self::assertSame(403, $app->handle($this->bearer($method, $path . '?board=board-b', $token, $body))->getStatusCode(), $method . ' ' . $path . '?board=board-b');
        }

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM comments'));
        self::assertSame('open', $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$ideaB]));
    }

    public function test_a_tag_of_another_board_cannot_be_assigned(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $ideaId    = $this->seedIdea($boardId, $ownerId, 'Own idea');
        $otherTag  = $this->insertTag($this->insertBoard('other', ['is_default' => 0]), 'Other');
        $token     = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'moderate');

        $response = $this->app()->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/tags', $token, ['tag_id' => $otherTag]));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tag_assignments'));
    }

    public function test_requests_without_a_valid_token_are_401(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $ideaId = $this->seedIdea($boardId, $ownerId);
        $app    = $this->app();

        foreach ($this->writeCalls($ideaId, $ideaId, 1) as [$method, $path, $body]) {
            self::assertSame(401, $app->handle($this->bearer($method, $path, 'not-a-token', $body))->getStatusCode(), $path);
        }
    }

    // ---------------------------------------------------------------------
    // Grant levels
    // ---------------------------------------------------------------------

    public function test_read_grant_cannot_write_and_write_grant_cannot_moderate(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $ideaId  = $this->seedIdea($boardId, $ownerId, 'Idea');
        $otherId = $this->seedIdea($boardId, $ownerId, 'Other');
        $tagId   = $this->insertTag($boardId);
        $read    = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'read');
        $write   = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'write');
        $app     = $this->app();

        foreach ($this->writeCalls($ideaId, $otherId, $tagId) as [$method, $path, $body]) {
            $response = $app->handle($this->bearer($method, $path, $read, $body));
            self::assertSame(403, $response->getStatusCode(), $path);
            self::assertSame('insufficient_scope', $this->json($response)['error']['key']);
        }

        foreach (array_slice($this->writeCalls($ideaId, $otherId, $tagId), 2) as [$method, $path, $body]) {
            $response = $app->handle($this->bearer($method, $path, $write, $body));
            self::assertSame(403, $response->getStatusCode(), $path);
            self::assertSame('insufficient_scope', $this->json($response)['error']['key']);
        }

        self::assertSame('open', $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$ideaId]));
    }

    public function test_moderate_grant_is_void_once_the_issuer_no_longer_moderates(): void
    {
        [$boardId] = $this->ownBoard();
        $adminId   = $this->insertUser('former-admin@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'admin');
        $ideaId = $this->seedIdea($boardId, $adminId, 'Idea');
        $token  = $this->token($this->defaultAccountId(), $boardId, $adminId, 'moderate');
        $this->conn->delete('account_members', ['user_id' => $adminId]);

        $response = $this->app()->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/status', $token, ['status' => 'planned']));

        self::assertSame(403, $response->getStatusCode());
        // The middleware downgrades the grant to write for a non-moderating issuer, so the refusal is the scope check.
        self::assertSame('insufficient_scope', $this->json($response)['error']['key']);
        self::assertSame('open', $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$ideaId]));
    }

    // ---------------------------------------------------------------------
    // Vote — one vote per token identity per idea, idempotent SET
    // ---------------------------------------------------------------------

    public function test_vote_sets_the_tokens_own_vote_idempotently(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $ideaId = $this->seedIdea($boardId, $ownerId, 'Idea');
        // The issuing admin's own UI vote stays separate from the token's.
        $this->seedVote($ideaId, $ownerId, 1);
        $this->conn->update('ideas', ['score_cache' => 1], ['id' => $ideaId]);
        $token = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'write');
        $app   = $this->app();
        $vote  = fn (string $value): array => $this->json($app->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/vote', $token, ['value' => $value])));

        $up     = ['score' => 2, 'my_vote' => 'up', 'up_count' => 2, 'down_count' => 0];
        $none   = ['score' => 1, 'my_vote' => 'none', 'up_count' => 1, 'down_count' => 0];
        $values = ['up', 'up', 'down', 'none', 'none'];
        $expect = [$up, $up, ['score' => 0, 'my_vote' => 'down', 'up_count' => 1, 'down_count' => 1], $none, $none];

        // Repeating a value is a no-op (SET semantics, not a toggle).
        foreach ($values as $i => $value) {
            self::assertSame($expect[$i], $vote($value), 'step ' . $i . ': ' . $value);
        }
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = ?', [$ideaId]));
    }

    public function test_two_tokens_are_two_voters_and_the_identity_is_no_user(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $ideaId = $this->seedIdea($boardId, $ownerId, 'Idea');
        $first  = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'write');
        $second = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'write');
        $app    = $this->app();

        $app->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/vote', $first, ['value' => 'up']));
        $result = $this->json($app->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/vote', $second, ['value' => 'up'])));

        self::assertSame(2, $result['score']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE user_id IS NOT NULL'));
        self::assertSame(2, (int) $this->conn->fetchOne('SELECT COUNT(DISTINCT voter_token_hash) FROM votes'));
    }

    public function test_vote_rejects_invalid_values_pending_and_merged_ideas_frozen_boards_and_closed_statuses(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $ideaId   = $this->seedIdea($boardId, $ownerId, 'Idea');
        $pending  = $this->seedIdea($boardId, $ownerId, 'Pending', ['moderation_state' => 'pending']);
        $merged   = $this->seedIdea($boardId, $ownerId, 'Merged', ['merged_into_id' => $ideaId]);
        $token    = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'moderate');
        $app      = $this->app();
        $vote     = fn (int $id, mixed $value): ResponseInterface => $app->handle($this->bearer('POST', '/api/v1/ideas/' . $id . '/vote', $token, ['value' => $value]));

        self::assertSame(422, $vote($ideaId, 'sideways')->getStatusCode());
        self::assertSame(422, $vote($ideaId, 1)->getStatusCode());
        self::assertSame(404, $vote($pending, 'up')->getStatusCode());
        self::assertSame(404, $vote($merged, 'up')->getStatusCode());

        $this->conn->update('boards', ['statuses_customized' => 1], ['id' => $boardId]);
        foreach (['open', 'planned', 'in_progress', 'done', 'declined', 'archived'] as $i => $key) {
            $this->conn->insert('board_statuses', ['board_id' => $boardId, 'status_key' => $key, 'category' => $key, 'position' => $i, 'is_system' => 1, 'allow_votes' => $key === 'open' ? 0 : 1]);
        }
        self::assertSame(423, $vote($ideaId, 'up')->getStatusCode());

        $this->conn->update('boards', ['frozen_at' => '2026-01-01 00:00:00'], ['id' => $boardId]);
        self::assertSame(423, $vote($ideaId, 'up')->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));
    }

    public function test_vote_respects_the_boards_voting_window(): void
    {
        $boardId = $this->insertBoard('demo', ['voting_opens_at' => '2026-04-10 00:00:00', 'voting_closes_at' => '2026-04-20 00:00:00']);
        $ownerId = $this->insertUser('demo-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $ideaId = $this->seedIdea($boardId, $ownerId, 'Idea');
        // Even the moderate grant of an owner-issued token is bound by it.
        $token = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'moderate');
        $vote  = fn (string $now): ResponseInterface => $this->createApp(clock: new FixedClock($now))
            ->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/vote', $token, ['value' => 'up']));

        $before = $vote('2026-04-09 23:59:59');
        self::assertSame(423, $before->getStatusCode());
        self::assertSame('voting_not_open_yet', $this->json($before)['error']['key']);

        $after = $vote('2026-04-20 00:00:01');
        self::assertSame(423, $after->getStatusCode());
        self::assertSame('voting_closed', $this->json($after)['error']['key']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));

        self::assertSame(200, $vote('2026-04-15 12:00:00')->getStatusCode());
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));
    }

    // ---------------------------------------------------------------------
    // Comment — attributed to the issuing user, reuses CommentCreateAction
    // ---------------------------------------------------------------------

    public function test_comment_is_created_as_the_issuing_user_and_validated_like_the_ui(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $authorId = $this->insertUser('author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Idea');
        $token    = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'write');
        $app      = $this->app();

        self::assertSame(422, $app->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/comments', $token, ['body' => '']))->getStatusCode());
        self::assertSame(422, $app->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/comments', $token, ['body' => str_repeat('x', 501)]))->getStatusCode());

        $response = $app->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/comments', $token, ['body' => 'Shipped in 2.3.', 'author_id' => $authorId]));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            ['author_id' => $ownerId, 'body' => 'Shipped in 2.3.'],
            array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, (array) $this->conn->fetchAssociative('SELECT author_id, body FROM comments')),
        );
        // The idea author is notified exactly as for a UI comment.
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$authorId]));
        self::assertStringContainsString('"action":"api_token.write"', $this->readAuditLog());
    }

    // ---------------------------------------------------------------------
    // Status — board registry keys, webhooks as in the UI
    // ---------------------------------------------------------------------

    public function test_status_change_accepts_custom_registry_keys_and_fires_the_ui_webhook(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $this->conn->update('boards', ['statuses_customized' => 1], ['id' => $boardId]);
        foreach (['open', 'planned', 'in_progress', 'done', 'declined', 'archived'] as $i => $key) {
            $this->conn->insert('board_statuses', ['board_id' => $boardId, 'status_key' => $key, 'category' => $key, 'position' => $i, 'is_system' => 1]);
        }
        $this->conn->insert('board_statuses', ['board_id' => $boardId, 'status_key' => 's1', 'category' => 'in_progress', 'label_en' => 'Beta', 'position' => 10, 'is_system' => 0]);
        $ideaId = $this->seedIdea($boardId, $ownerId, 'Idea');
        $token  = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'moderate');
        $app    = $this->app();
        $this->configureWebhook($app, 'demo', $ownerId);

        $board = $this->json($app->handle($this->bearer('GET', '/api/v1/board', $token)));
        self::assertContains('s1', array_column($board['board']['statuses'], 'key'));

        $unknown = $app->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/status', $token, ['status' => 's9']));
        self::assertSame(422, $unknown->getStatusCode());

        $response = $app->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/status', $token, ['status' => 's1']));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('s1', $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$ideaId]));
        self::assertSame(['idea.status_changed'], $this->webhookEvents());
    }

    // ---------------------------------------------------------------------
    // Tags
    // ---------------------------------------------------------------------

    public function test_tags_are_assigned_and_removed_idempotently(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $ideaId = $this->seedIdea($boardId, $ownerId, 'Idea');
        $tagId  = $this->insertTag($boardId, 'Bug');
        $token  = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'moderate');
        $app    = $this->app();
        $count  = fn (): int => (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tag_assignments WHERE idea_id = ?', [$ideaId]);

        self::assertLessThan(300, $app->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/tags', $token, ['tag_id' => $tagId]))->getStatusCode());
        self::assertLessThan(300, $app->handle($this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/tags', $token, ['tag_id' => $tagId]))->getStatusCode());
        self::assertSame(1, $count());

        self::assertLessThan(300, $app->handle($this->bearer('DELETE', '/api/v1/ideas/' . $ideaId . '/tags/' . $tagId, $token))->getStatusCode());
        self::assertLessThan(300, $app->handle($this->bearer('DELETE', '/api/v1/ideas/' . $ideaId . '/tags/' . $tagId, $token))->getStatusCode());
        self::assertSame(0, $count());
    }

    // ---------------------------------------------------------------------
    // Merge / unmerge — issue 10's rules, webhook as in the UI
    // ---------------------------------------------------------------------

    public function test_merge_and_unmerge_reuse_the_ui_rules_and_webhook(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $source  = $this->seedIdea($boardId, $ownerId, 'Duplicate');
        $target  = $this->seedIdea($boardId, $ownerId, 'Original');
        $pending = $this->seedIdea($boardId, $ownerId, 'Pending', ['moderation_state' => 'pending']);
        $token   = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'moderate');
        $app     = $this->app();
        $this->configureWebhook($app, 'demo', $ownerId);

        self::assertSame(422, $app->handle($this->bearer('POST', '/api/v1/ideas/' . $source . '/merge', $token, ['target_id' => $source]))->getStatusCode());
        self::assertGreaterThanOrEqual(400, $app->handle($this->bearer('POST', '/api/v1/ideas/' . $source . '/merge', $token, ['target_id' => $pending]))->getStatusCode());
        self::assertGreaterThanOrEqual(400, $app->handle($this->bearer('POST', '/api/v1/ideas/' . $pending . '/merge', $token, ['target_id' => $target]))->getStatusCode());

        $merged = $app->handle($this->bearer('POST', '/api/v1/ideas/' . $source . '/merge', $token, ['target_id' => $target]));
        self::assertSame(200, $merged->getStatusCode(), (string) $merged->getBody());
        self::assertSame($target, (int) $this->conn->fetchOne('SELECT merged_into_id FROM ideas WHERE id = ?', [$source]));
        self::assertSame(['idea.merged'], $this->webhookEvents());

        $unmerged = $app->handle($this->bearer('POST', '/api/v1/ideas/' . $source . '/unmerge', $token));
        self::assertSame(200, $unmerged->getStatusCode(), (string) $unmerged->getBody());
        self::assertNull($this->conn->fetchOne('SELECT merged_into_id FROM ideas WHERE id = ?', [$source]));
    }

    // ---------------------------------------------------------------------
    // Search + create fan-out
    // ---------------------------------------------------------------------

    public function test_q_searches_published_ideas_only(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $this->seedIdea($boardId, $ownerId, 'Dark mode for the dashboard');
        $this->seedIdea($boardId, $ownerId, 'Export to CSV');
        $this->seedIdea($boardId, $ownerId, 'Dark mode pending', ['moderation_state' => 'pending']);
        $foreignAccount = $this->insertAccount();
        $foreignBoard   = $this->insertBoard('foreign', ['account_id' => $foreignAccount, 'is_default' => 0]);
        $this->seedIdea($foreignBoard, $ownerId, 'Dark mode elsewhere');
        $token = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'read');

        $data = $this->json($this->app()->handle($this->bearer('GET', '/api/v1/ideas?q=dark', $token)));

        self::assertSame(['Dark mode for the dashboard'], array_column($data['ideas'], 'title'));
        self::assertSame('dark', $data['active_query']);
    }

    public function test_api_created_idea_fires_the_idea_created_webhook(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $token = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'write');
        $app   = $this->app();
        $this->configureWebhook($app, 'demo', $ownerId);

        $response = $app->handle($this->bearer('POST', '/api/v1/ideas', $token, ['title' => 'Agent idea', 'body' => 'Long enough description.']));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['idea.created'], $this->webhookEvents());
    }

    public function test_api_create_is_rejected_on_a_frozen_board(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $this->conn->update('boards', ['frozen_at' => '2026-01-01 00:00:00'], ['id' => $boardId]);
        $token = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'write');

        $response = $this->app()->handle($this->bearer('POST', '/api/v1/ideas', $token, ['title' => 'Agent idea', 'body' => 'Long enough description.']));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas'));
    }
}
