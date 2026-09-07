<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /discover — the public, cross-tenant board
 * discovery listing (BoardRepository::listPublicDiscovery()/
 * spotlightBoards()).
 *
 * ACs:
 *  AC1 — Only visibility='public' boards of confirmed, unlocked, non-
 *        deletion-scheduled accounts appear.
 *  AC2 — 'unlisted' and 'private' boards never appear.
 *  AC3 — Boards of unconfirmed/locked/deletion-scheduled accounts never
 *        appear (cross-tenant-leak protection).
 *  AC4 — Operator-locked boards never appear.
 *  AC5 — Frozen boards still appear (deliberate — see BoardRepository doc).
 *  AC6 — Pagination: total/page/limit correct, page 2 differs from page 1.
 *  AC7 — Route reachable without login (anon).
 *  AC8 — Response carries only the intended fields, no leak of IDs/content.
 *  AC9 — Ranking: by vote activity, then newest (no more manual featuring —
 *        removed 2026-09-04, see spotlightBoards() for its automatic
 *        replacement).
 *  AC10 — Spotlight: page-1-only, excludes empty/locked boards, capped at 5.
 */
final class BoardDiscoveryActionTest extends IntegrationTestCase
{
    /** @param array<string, int|string> $query */
    private function request(array $query = []): \Psr\Http\Message\ServerRequestInterface
    {
        $uri = '/discover' . ($query !== [] ? '?' . http_build_query($query) : '');

        return (new ServerRequestFactory())->createServerRequest('GET', $uri);
    }

    // =========================================================================
    // AC1/AC7 — public board of a confirmed account appears, anon-reachable
    // =========================================================================

    public function test_public_board_appears_for_anon(): void
    {
        $accountId = $this->insertAccount(['slug' => 'discover-acct-1']);
        $this->insertBoard('discover-public', ['account_id' => $accountId, 'visibility' => 'public', 'name' => 'Discoverable Board']);

        $response = $this->createApp()->handle($this->request());

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $slugs = array_column($data['boards'], 'slug');
        self::assertContains('discover-public', $slugs);
    }

    // =========================================================================
    // AC2 — 'unlisted'/'private' never appear
    // =========================================================================

    public function test_unlisted_board_does_not_appear(): void
    {
        $accountId = $this->insertAccount(['slug' => 'discover-acct-unlisted']);
        $this->insertBoard('discover-unlisted', ['account_id' => $accountId, 'visibility' => 'unlisted']);

        $response = $this->createApp()->handle($this->request());
        $data     = json_decode((string) $response->getBody(), true);

        self::assertNotContains('discover-unlisted', array_column($data['boards'], 'slug'));
    }

    public function test_private_board_does_not_appear(): void
    {
        $accountId = $this->insertAccount(['slug' => 'discover-acct-private']);
        $this->insertBoard('discover-private', ['account_id' => $accountId, 'visibility' => 'private']);

        $response = $this->createApp()->handle($this->request());
        $data     = json_decode((string) $response->getBody(), true);

        self::assertNotContains('discover-private', array_column($data['boards'], 'slug'));
    }

    // =========================================================================
    // AC3 — cross-tenant-leak protection via account trust gates
    // =========================================================================

    public function test_board_of_unconfirmed_account_does_not_appear(): void
    {
        $accountId = $this->insertAccount(['slug' => 'discover-unconfirmed', 'confirmed_at' => null]);
        $this->insertBoard('discover-unconfirmed-board', ['account_id' => $accountId, 'visibility' => 'public']);

        $response = $this->createApp()->handle($this->request());
        $data     = json_decode((string) $response->getBody(), true);

        self::assertNotContains('discover-unconfirmed-board', array_column($data['boards'], 'slug'));
    }

    public function test_board_of_locked_account_does_not_appear(): void
    {
        $accountId = $this->insertAccount(['slug' => 'discover-locked-acct', 'locked_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        $this->insertBoard('discover-locked-acct-board', ['account_id' => $accountId, 'visibility' => 'public']);

        $response = $this->createApp()->handle($this->request());
        $data     = json_decode((string) $response->getBody(), true);

        self::assertNotContains('discover-locked-acct-board', array_column($data['boards'], 'slug'));
    }

    public function test_board_of_deletion_scheduled_account_does_not_appear(): void
    {
        $accountId = $this->insertAccount([
            'slug' => 'discover-deletion-scheduled',
            'deletion_scheduled_at' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d H:i:s'),
        ]);
        $this->insertBoard('discover-deletion-scheduled-board', ['account_id' => $accountId, 'visibility' => 'public']);

        $response = $this->createApp()->handle($this->request());
        $data     = json_decode((string) $response->getBody(), true);

        self::assertNotContains('discover-deletion-scheduled-board', array_column($data['boards'], 'slug'));
    }

    // =========================================================================
    // AC4 — operator-locked board never appears
    // =========================================================================

    public function test_locked_board_does_not_appear(): void
    {
        $accountId = $this->insertAccount(['slug' => 'discover-locked-board-acct']);
        $this->insertBoard('discover-locked-board', [
            'account_id' => $accountId,
            'visibility' => 'public',
            'locked_at'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $response = $this->createApp()->handle($this->request());
        $data     = json_decode((string) $response->getBody(), true);

        self::assertNotContains('discover-locked-board', array_column($data['boards'], 'slug'));
    }

    // =========================================================================
    // AC5 — frozen board still appears (deliberate)
    // =========================================================================

    public function test_frozen_board_still_appears(): void
    {
        $accountId = $this->insertAccount(['slug' => 'discover-frozen-acct']);
        $this->insertBoard('discover-frozen-board', [
            'account_id' => $accountId,
            'visibility' => 'public',
            'frozen_at'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $response = $this->createApp()->handle($this->request());
        $data     = json_decode((string) $response->getBody(), true);

        self::assertContains('discover-frozen-board', array_column($data['boards'], 'slug'));
    }

    // =========================================================================
    // AC6 — pagination
    // =========================================================================

    public function test_pagination_returns_correct_total_and_different_pages(): void
    {
        $accountId = $this->insertAccount(['slug' => 'discover-pagination']);
        for ($i = 1; $i <= 5; $i++) {
            $this->insertBoard("discover-page-board-{$i}", [
                'account_id' => $accountId,
                'visibility' => 'public',
                'created_at' => (new \DateTimeImmutable("+{$i} seconds"))->format('Y-m-d H:i:s'),
            ]);
        }

        $page1 = json_decode((string) $this->createApp()->handle($this->request(['limit' => 2, 'page' => 1]))->getBody(), true);
        $page2 = json_decode((string) $this->createApp()->handle($this->request(['limit' => 2, 'page' => 2]))->getBody(), true);

        self::assertSame(5, $page1['total']);
        self::assertSame(1, $page1['page']);
        self::assertSame(2, $page1['limit']);
        self::assertCount(2, $page1['boards']);
        self::assertCount(2, $page2['boards']);
        self::assertNotSame(
            array_column($page1['boards'], 'slug'),
            array_column($page2['boards'], 'slug'),
        );
    }

    // =========================================================================
    // AC8 — response field allowlist (no data leak)
    // =========================================================================

    public function test_response_exposes_only_intended_fields(): void
    {
        $accountId = $this->insertAccount(['slug' => 'discover-fields-acct']);
        $boardId   = $this->insertBoard('discover-fields-board', [
            'account_id' => $accountId,
            'visibility' => 'public',
            'name'       => 'Fields Board',
            'intro'      => 'Secret intro text',
        ]);
        $this->seedIdea($boardId, $this->insertUser('discover-fields-author@example.com'), 'Some idea');

        $response = $this->createApp()->handle($this->request());
        $data     = json_decode((string) $response->getBody(), true);

        $board = null;
        foreach ($data['boards'] as $candidate) {
            if ($candidate['slug'] === 'discover-fields-board') {
                $board = $candidate;
            }
        }
        self::assertNotNull($board);
        self::assertSame(
            ['slug', 'name', 'account_slug', 'intro', 'idea_count', 'vote_count'],
            array_keys($board),
        );
        self::assertSame('discover-fields-acct', $board['account_slug']);
        self::assertSame('Fields Board', $board['name']);
        self::assertSame('Secret intro text', $board['intro']);
        self::assertSame(1, $board['idea_count']);
        self::assertSame(0, $board['vote_count']);
    }

    // =========================================================================
    // AC9 — ranking: by vote activity, then newest
    // =========================================================================

    public function test_more_active_board_is_ranked_before_quieter_board(): void
    {
        $accountId = $this->insertAccount(['slug' => 'discover-rank-acct']);
        $this->insertBoard('discover-rank-quiet', ['account_id' => $accountId, 'visibility' => 'public']);
        $activeId  = $this->insertBoard('discover-rank-active', ['account_id' => $accountId, 'visibility' => 'public']);

        $author = $this->insertUser('discover-rank-author@example.com');
        $ideaId = $this->seedIdea($activeId, $author);
        $this->seedVote($ideaId, $this->insertUser('discover-rank-voter-1@example.com'), 1);
        $this->seedVote($ideaId, $this->insertUser('discover-rank-voter-2@example.com'), 1);

        $response = $this->createApp()->handle($this->request());
        $data     = json_decode((string) $response->getBody(), true);
        $slugs    = array_column($data['boards'], 'slug');

        self::assertLessThan(
            array_search('discover-rank-quiet', $slugs, true),
            array_search('discover-rank-active', $slugs, true),
        );
    }

    // =========================================================================
    // AC10 — spotlight: only on page 1, only eligible/active boards, capped at 5
    // =========================================================================

    public function test_spotlight_is_present_on_page_1_and_absent_on_page_2(): void
    {
        $accountId = $this->insertAccount(['slug' => 'discover-spotlight-paging']);
        $boardId   = $this->insertBoard('discover-spotlight-p1', ['account_id' => $accountId, 'visibility' => 'public']);
        $ideaId    = $this->seedIdea($boardId, $this->insertUser('discover-spotlight-author@example.com'));
        $this->seedVote($ideaId, $this->insertUser('discover-spotlight-voter@example.com'), 1);

        $page1 = json_decode((string) $this->createApp()->handle($this->request(['page' => 1]))->getBody(), true);
        $page2 = json_decode((string) $this->createApp()->handle($this->request(['page' => 2]))->getBody(), true);

        self::assertContains('discover-spotlight-p1', array_column($page1['spotlight'], 'slug'));
        self::assertSame([], $page2['spotlight']);
    }

    public function test_spotlight_excludes_boards_with_no_ideas(): void
    {
        $accountId = $this->insertAccount(['slug' => 'discover-spotlight-empty']);
        $this->insertBoard('discover-spotlight-empty-board', ['account_id' => $accountId, 'visibility' => 'public']);

        $response = $this->createApp()->handle($this->request());
        $data     = json_decode((string) $response->getBody(), true);

        self::assertNotContains('discover-spotlight-empty-board', array_column($data['spotlight'], 'slug'));
    }

    public function test_spotlight_never_exceeds_five_boards(): void
    {
        $accountId = $this->insertAccount(['slug' => 'discover-spotlight-many']);
        for ($i = 1; $i <= 8; $i++) {
            $boardId = $this->insertBoard("discover-spotlight-many-{$i}", ['account_id' => $accountId, 'visibility' => 'public']);
            $this->seedIdea($boardId, $this->insertUser("discover-spotlight-many-author-{$i}@example.com"));
        }

        $response = $this->createApp()->handle($this->request());
        $data     = json_decode((string) $response->getBody(), true);

        self::assertLessThanOrEqual(5, count($data['spotlight']));
    }

    public function test_spotlight_excludes_locked_board(): void
    {
        $accountId = $this->insertAccount(['slug' => 'discover-spotlight-locked-acct']);
        $boardId   = $this->insertBoard('discover-spotlight-locked-board', [
            'account_id' => $accountId,
            'visibility' => 'public',
            'locked_at'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->seedIdea($boardId, $this->insertUser('discover-spotlight-locked-author@example.com'));

        $response = $this->createApp()->handle($this->request());
        $data     = json_decode((string) $response->getBody(), true);

        self::assertNotContains('discover-spotlight-locked-board', array_column($data['spotlight'], 'slug'));
    }
}
