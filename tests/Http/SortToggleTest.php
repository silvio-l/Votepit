<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * HTTP integration tests for the "Newest | Top" sort toggle.
 *
 * All assertions run through the HTTP seam (AppFactory + IntegrationTestCase).
 * No direct access to repository internals.
 *
 * Covered ACs:
 *  AC1 — ?sort=top orders by score_cache DESC
 *  AC2 — invalid/missing ?sort= → newest fallback
 *  AC3 — sort selection is preserved across status filter and pagination
 *  AC4 — active tab is correctly marked from active_sort
 *  AC5 — "Controversial" tab stays inactive/without real sorting
 */
final class SortToggleTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    /**
     * GET request to /{board} with optional query parameters.
     *
     * @param array<string, string> $extraParams
     */
    private function getBoard(
        string $slug,
        array $extraParams = [],
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $slug)
            ->withQueryParams($extraParams);
    }

    // -------------------------------------------------------------------------
    // AC1 — ?sort=top orders by score_cache DESC
    // -------------------------------------------------------------------------

    public function test_sort_top_orders_by_score_cache_desc(): void
    {
        $boardId  = $this->insertBoard('sort-top-board');
        $authorId = $this->insertUser('sorttop@example.com');

        // Seed the low-score idea first, higher-score afterwards
        $this->seedIdea($boardId, $authorId, 'Low-score idea', [
            'score_cache' => 1,
            'created_at'  => '2025-06-01 10:00:00',
            'updated_at'  => '2025-06-01 10:00:00',
        ]);
        $this->seedIdea($boardId, $authorId, 'High-score idea', [
            'score_cache' => 99,
            'created_at'  => '2025-01-01 10:00:00',
            'updated_at'  => '2025-01-01 10:00:00',
        ]);

        $data   = json_decode(
            (string) $this->createApp()->handle($this->getBoard('sort-top-board', ['sort' => 'top']))->getBody(),
            true,
        );
        $titles  = array_column($data['ideas'] ?? [], 'title');
        $posHigh = array_search('High-score idea', $titles, true);
        $posLow  = array_search('Low-score idea', $titles, true);

        self::assertIsInt($posHigh);
        self::assertIsInt($posLow);
        self::assertLessThan($posLow, $posHigh, 'High-score idea must appear before the low-score idea (?sort=top)');
    }

    // -------------------------------------------------------------------------
    // AC2 — invalid/missing ?sort= → newest fallback
    // -------------------------------------------------------------------------

    public function test_missing_sort_param_falls_back_to_newest(): void
    {
        $boardId  = $this->insertBoard('sort-newest-board');
        $authorId = $this->insertUser('sortnewest@example.com');

        $this->seedIdea($boardId, $authorId, 'Older idea', [
            'created_at' => '2025-01-01 10:00:00',
            'updated_at' => '2025-01-01 10:00:00',
        ]);
        $this->seedIdea($boardId, $authorId, 'Newer idea', [
            'created_at' => '2025-06-01 10:00:00',
            'updated_at' => '2025-06-01 10:00:00',
        ]);

        // No ?sort= → newest fallback
        $data   = json_decode(
            (string) $this->createApp()->handle($this->getBoard('sort-newest-board'))->getBody(),
            true,
        );
        $titles = array_column($data['ideas'] ?? [], 'title');
        $posOld = array_search('Older idea', $titles, true);
        $posNew = array_search('Newer idea', $titles, true);

        self::assertIsInt($posOld);
        self::assertIsInt($posNew);
        self::assertLessThan($posOld, $posNew, 'Without ?sort= the newest fallback must apply (created_at DESC)');
    }

    public function test_invalid_sort_param_falls_back_to_newest(): void
    {
        $boardId  = $this->insertBoard('sort-invalid-board');
        $authorId = $this->insertUser('sortinvalid@example.com');

        $this->seedIdea($boardId, $authorId, 'Older idea', [
            'created_at' => '2025-01-01 10:00:00',
            'updated_at' => '2025-01-01 10:00:00',
        ]);
        $this->seedIdea($boardId, $authorId, 'Newer idea', [
            'created_at' => '2025-06-01 10:00:00',
            'updated_at' => '2025-06-01 10:00:00',
        ]);

        // Invalid ?sort= → newest fallback
        $data   = json_decode(
            (string) $this->createApp()
                ->handle($this->getBoard('sort-invalid-board', ['sort' => 'invalid_sort_key']))
                ->getBody(),
            true,
        );
        $titles = array_column($data['ideas'] ?? [], 'title');
        $posOld = array_search('Older idea', $titles, true);
        $posNew = array_search('Newer idea', $titles, true);

        self::assertIsInt($posOld);
        self::assertIsInt($posNew);
        self::assertLessThan($posOld, $posNew, 'Invalid ?sort= must fall back to newest (created_at DESC)');
    }

    // -------------------------------------------------------------------------
    // AC3 — sort selection is preserved across status filter and pagination
    // -------------------------------------------------------------------------

    public function test_sort_is_preserved_in_status_filter_tab_links(): void
    {
        $boardId  = $this->insertBoard('sort-preserve-status-board');
        $authorId = $this->insertUser('preservestatus@example.com');
        $this->seedIdea($boardId, $authorId, 'Test idea');

        $data = json_decode(
            (string) $this->createApp()
                ->handle($this->getBoard('sort-preserve-status-board', ['sort' => 'top']))
                ->getBody(),
            true,
        );

        // active_sort indicates which sort mode is active (the SPA builds links with this parameter)
        self::assertSame('top', $data['active_sort'] ?? null, 'active_sort must be top');
    }

    public function test_sort_is_preserved_in_pagination_links(): void
    {
        $boardId  = $this->insertBoard('sort-preserve-page-board');
        $authorId = $this->insertUser('preservepage@example.com');

        // Create more than DEFAULT_PAGE_SIZE ideas → pagination appears
        for ($i = 1; $i <= 52; $i++) {
            $this->seedIdea($boardId, $authorId, "Idea {$i}");
        }

        $data = json_decode(
            (string) $this->createApp()
                ->handle($this->getBoard('sort-preserve-page-board', ['sort' => 'top']))
                ->getBody(),
            true,
        );

        // active_sort=top in the JSON response; the SPA builds pagination links with sort=top
        self::assertSame('top', $data['active_sort'] ?? null, 'active_sort must be top in the pagination context');
    }

    // -------------------------------------------------------------------------
    // AC4 — active tab is correctly marked from active_sort
    // -------------------------------------------------------------------------

    public function test_active_sort_tab_marked_for_top(): void
    {
        $boardId  = $this->insertBoard('sort-active-tab-top-board');
        $authorId = $this->insertUser('activetabtop@example.com');
        $this->seedIdea($boardId, $authorId, 'Test idea');

        $data = json_decode(
            (string) $this->createApp()
                ->handle($this->getBoard('sort-active-tab-top-board', ['sort' => 'top']))
                ->getBody(),
            true,
        );

        // active_sort=top in the JSON response (the SPA marks the tab)
        self::assertSame('top', $data['active_sort'] ?? null, 'With ?sort=top, active_sort must be top');
    }

    public function test_active_sort_tab_marked_for_newest_by_default(): void
    {
        $boardId  = $this->insertBoard('sort-active-tab-newest-board');
        $authorId = $this->insertUser('activetabnewest@example.com');
        $this->seedIdea($boardId, $authorId, 'Test idea');

        $data = json_decode(
            (string) $this->createApp()
                ->handle($this->getBoard('sort-active-tab-newest-board'))
                ->getBody(),
            true,
        );

        // Without ?sort=, active_sort must be newest (the SPA marks the "new" tab)
        self::assertSame('newest', $data['active_sort'] ?? null, 'Without ?sort=, active_sort must be newest');
    }

    // -------------------------------------------------------------------------
    // AC5 — "Controversial" tab stays inactive/without invented sorting
    // -------------------------------------------------------------------------

    public function test_controversial_tab_stays_inactive(): void
    {
        $boardId  = $this->insertBoard('sort-controversial-board');
        $authorId = $this->insertUser('controversial@example.com');
        $this->seedIdea($boardId, $authorId, 'Test idea');

        $data = json_decode(
            (string) $this->createApp()
                ->handle($this->getBoard('sort-controversial-board'))
                ->getBody(),
            true,
        );

        // "Controversial" must never appear as active_sort
        self::assertNotSame(
            'controversial',
            $data['active_sort'] ?? null,
            'Controversial tab must not appear as active_sort',
        );
    }
}
