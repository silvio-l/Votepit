<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /admin/boards — the account-scoped board overview
 * (read path for the admin overview page).
 *
 * Boots through AppFactory with SQLite in-memory. Assertions check exclusively
 * observable behavior: HTTP status + JSON body (no cross-tenant leak).
 */
final class BoardListActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function sessions(): SessionService
    {
        return new SessionService(self::APP_KEY, 3600, false);
    }

    private function getRequest(?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/boards');

        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessions()->sign(['uid' => $userId, 'v' => 0]),
            ]);
        }

        return $request;
    }

    public function test_get_as_anon_returns_401(): void
    {
        $response = $this->createApp()->handle($this->getRequest(null));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_get_as_non_member_returns_403(): void
    {
        $userId   = $this->insertUser('no-membership@example.com');
        $response = $this->createApp()->handle($this->getRequest($userId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_get_as_owner_returns_200_with_own_account_boards(): void
    {
        $this->insertBoard('alpha', ['name' => 'Alpha Board']);
        $this->insertBoard('beta', ['name' => 'Beta Board']);

        $ownerId = $this->insertUser('owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->getRequest($ownerId));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data['boards'] ?? null);
        self::assertCount(2, $data['boards']);

        $slugs = array_column($data['boards'], 'slug');
        self::assertContains('alpha', $slugs);
        self::assertContains('beta', $slugs);
    }

    public function test_get_as_admin_returns_200(): void
    {
        $this->insertBoard('gamma');
        $adminId = $this->insertUser('admin@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'admin');

        $response = $this->createApp()->handle($this->getRequest($adminId));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_get_as_moderator_returns_403(): void
    {
        $this->insertBoard('gamma-mod');
        $modId = $this->insertUser('moderator@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->getRequest($modId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_response_includes_account_onboarding_status(): void
    {
        $ownerId = $this->insertUser('owner-onboarding@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->getRequest($ownerId));

        $data = json_decode((string) $response->getBody(), true);
        // Default account is seeded/backfilled as already onboarded (migration
        // 0017) — an established self-host install must never see the wizard.
        self::assertIsArray($data['account'] ?? null);
        self::assertNotNull($data['account']['onboarding_completed_at']);
    }

    public function test_response_includes_idea_and_vote_counts(): void
    {
        $boardId = $this->insertBoard('with-activity');
        $ownerId = $this->insertUser('owner-activity@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $this->conn->insert('ideas', [
            'id'         => 1,
            'board_id'   => $boardId,
            'author_id'  => $ownerId,
            'title'      => 'Dark mode',
            'body'       => 'Please add dark mode.',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->conn->insert('votes', [
            'idea_id'    => 1,
            'user_id'    => $ownerId,
            'value'      => 1,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $response = $this->createApp()->handle($this->getRequest($ownerId));
        $data     = json_decode((string) $response->getBody(), true);

        $board = current(array_filter($data['boards'], static fn (array $b): bool => $b['slug'] === 'with-activity'));
        self::assertSame(1, $board['idea_count']);
        self::assertSame(1, $board['vote_count']);
    }

    public function test_response_includes_plan_visibility_options_for_create_form(): void
    {
        $ownerId = $this->insertUser('owner-visibility@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->getRequest($ownerId));
        $data     = json_decode((string) $response->getBody(), true);

        // syntheticPlanPolicy()'s default fixture account plan allows every
        // visibility (self-host), so the safest default is 'private'.
        self::assertSame(['public', 'unlisted', 'private'], $data['account']['allowed_visibilities'] ?? null);
        self::assertSame('private', $data['account']['default_visibility'] ?? null);
    }

    public function test_foreign_account_board_with_same_slug_is_not_listed(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-foreign-list', 'name' => 'Foreign Account']);
        $this->insertBoard('shared-slug', ['account_id' => $foreignAccountId, 'name' => 'Foreign Board']);
        $this->insertBoard('own-board', ['name' => 'Own Board']);

        $ownerId = $this->insertUser('owner-cross@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->getRequest($ownerId));

        self::assertSame(200, $response->getStatusCode());

        $data  = json_decode((string) $response->getBody(), true);
        $names = array_column($data['boards'], 'name');
        self::assertNotContains('Foreign Board', $names);
        self::assertContains('Own Board', $names);
    }
}
