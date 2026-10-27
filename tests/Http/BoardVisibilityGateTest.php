<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Cross-cutting integration tests for the board visibility gate:
 * BoardRepository::findPublicBySlugForAccount() is the ONE chokepoint shared
 * by BoardHomeAction, BoardRoadmapAction, IdeaDetailAction and IdeaNewAction —
 * this test covers all four instead of duplicating the check four times
 * across the respective action test files.
 *
 * AC coverage:
 *   - 'private' board: anon AND logged-in non-member → 404 on all four
 *     routes; account member (owner OR moderator) → 200.
 *   - 'unlisted' board: remains reachable via direct link (anon → 200) —
 *     the only difference from 'public' lies in a future listing view,
 *     not in direct access.
 *   - 'public' board (default): unchanged behavior (regression protection).
 */
final class BoardVisibilityGateTest extends IntegrationTestCase
{
    /** @return list<ServerRequestInterface> */
    private function anonReadRequestsFor(string $boardSlug, int $ideaId): array
    {
        return [
            (new ServerRequestFactory())->createServerRequest('GET', '/' . $boardSlug),
            (new ServerRequestFactory())->createServerRequest('GET', '/' . $boardSlug . '/roadmap'),
            (new ServerRequestFactory())->createServerRequest('GET', '/' . $boardSlug . '/ideas/' . $ideaId),
            (new ServerRequestFactory())->createServerRequest('GET', '/' . $boardSlug . '/ideas/new'),
        ];
    }

    private function withUser(ServerRequestInterface $request, int $userId): ServerRequestInterface
    {
        return $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
    }

    // -------------------------------------------------------------------------
    // 'private' board — anon + non-member → 404 on all four routes
    // -------------------------------------------------------------------------

    public function test_private_board_rejects_anon_on_all_four_read_routes(): void
    {
        $boardId = $this->insertBoard('private-board', ['visibility' => 'private']);
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author-priv-anon@example.com'), 'Idea');

        $app = $this->createApp();
        foreach ($this->anonReadRequestsFor('private-board', $ideaId) as $request) {
            $response = $app->handle($request);
            self::assertSame(404, $response->getStatusCode(), (string) $request->getUri());
        }
    }

    public function test_private_board_rejects_logged_in_non_member_on_all_four_read_routes(): void
    {
        $boardId   = $this->insertBoard('private-board-nm', ['visibility' => 'private']);
        $authorId  = $this->insertUser('author-priv-nm@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId, 'Idea');
        $outsiderId = $this->insertUser('outsider-priv-nm@example.com'); // no account_members row

        $app = $this->createApp();
        foreach ($this->anonReadRequestsFor('private-board-nm', $ideaId) as $request) {
            $response = $app->handle($this->withUser($request, $outsiderId));
            self::assertSame(404, $response->getStatusCode(), (string) $request->getUri());
        }
    }

    public function test_private_board_allows_owner_on_all_four_read_routes(): void
    {
        $boardId  = $this->insertBoard('private-board-owner', ['visibility' => 'private']);
        $authorId = $this->insertUser('author-priv-owner@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Idea');
        $ownerId  = $this->insertUser('owner-priv-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $app = $this->createApp();
        foreach ($this->anonReadRequestsFor('private-board-owner', $ideaId) as $request) {
            $response = $app->handle($this->withUser($request, $ownerId));
            self::assertSame(200, $response->getStatusCode(), (string) $request->getUri());
        }
    }

    public function test_private_board_allows_moderator_on_all_four_read_routes(): void
    {
        $boardId  = $this->insertBoard('private-board-mod', ['visibility' => 'private']);
        $authorId = $this->insertUser('author-priv-mod@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Idea');
        $modId    = $this->insertUser('mod-priv-mod@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $app = $this->createApp();
        foreach ($this->anonReadRequestsFor('private-board-mod', $ideaId) as $request) {
            $response = $app->handle($this->withUser($request, $modId));
            self::assertSame(200, $response->getStatusCode(), (string) $request->getUri());
        }
    }

    // -------------------------------------------------------------------------
    // 'unlisted' board — remains reachable via direct link (anon → 200)
    // -------------------------------------------------------------------------

    public function test_unlisted_board_remains_reachable_by_direct_link_for_anon(): void
    {
        $boardId = $this->insertBoard('unlisted-board', ['visibility' => 'unlisted']);
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author-unlisted@example.com'), 'Idea');

        $app = $this->createApp();
        foreach ($this->anonReadRequestsFor('unlisted-board', $ideaId) as $request) {
            $response = $app->handle($request);
            self::assertSame(200, $response->getStatusCode(), (string) $request->getUri());
        }
    }

    // -------------------------------------------------------------------------
    // 'public' board (default) — regression protection: unchanged behavior
    // -------------------------------------------------------------------------

    public function test_public_board_remains_reachable_by_anon(): void
    {
        $boardId = $this->insertBoard('public-board-default'); // Default: visibility='public'
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author-public@example.com'), 'Idea');

        $app = $this->createApp();
        foreach ($this->anonReadRequestsFor('public-board-default', $ideaId) as $request) {
            $response = $app->handle($request);
            self::assertSame(200, $response->getStatusCode(), (string) $request->getUri());
        }
    }

    // -------------------------------------------------------------------------
    // Cross-tenant security — membership in account A must NOT make a 'private'
    // board in account B reachable (no leak through the viewerIsMember check).
    // -------------------------------------------------------------------------

    public function test_private_board_in_other_account_rejects_member_of_different_account(): void
    {
        $otherAccountId = $this->insertAccount(['slug' => 'other-tenant']);
        $boardId        = $this->insertBoard('private-cross-tenant', [
            'account_id' => $otherAccountId,
            'visibility' => 'private',
        ]);
        $authorId = $this->insertUser('author-cross-tenant@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Idea');

        // Member is owner in the DEFAULT account, not in the board's account.
        $memberOfDefaultId = $this->insertUser('member-default-account@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $memberOfDefaultId, 'owner');

        $app = $this->createApp();
        foreach ($this->anonReadRequestsFor('private-cross-tenant', $ideaId) as $request) {
            $response = $app->handle($this->withUser($request, $memberOfDefaultId));
            self::assertSame(404, $response->getStatusCode(), (string) $request->getUri());
        }
    }
}
