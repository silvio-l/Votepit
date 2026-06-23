<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * HTTP-Integrationstests für den Sortier-Umschalter „Neueste | Top" (Sprint 4, Issue 03).
 *
 * Alle Assertions laufen über den HTTP-Seam (AppFactory + IntegrationTestCase).
 * Kein direkter Zugriff auf Repository-Interna.
 *
 * Abgedeckte ACs:
 *  AC1 — ?sort=top ordnet nach score_cache DESC
 *  AC2 — ungültiger/fehlender ?sort= → Newest-Fallback
 *  AC3 — Sort-Auswahl bleibt über Status-Filter und Pagination erhalten
 *  AC4 — aktiver Tab wird korrekt aus active_sort markiert
 *  AC5 — „Umstritten"-Tab bleibt inaktiv/ohne echte Sortierung
 */
final class SortToggleTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Hilfsmethoden
    // -------------------------------------------------------------------------

    /**
     * GET-Request auf /{board} mit optionalen Query-Parametern.
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
    // AC1 — ?sort=top ordnet nach score_cache DESC
    // -------------------------------------------------------------------------

    public function test_sort_top_orders_by_score_cache_desc(): void
    {
        $boardId  = $this->insertBoard('sort-top-board');
        $authorId = $this->insertUser('sorttop@example.com');

        // Idee mit niedrigem Score zuerst seeden, höherem danach
        $this->seedIdea($boardId, $authorId, 'Niedrig-Score-Idee', [
            'score_cache' => 1,
            'created_at'  => '2025-06-01 10:00:00',
            'updated_at'  => '2025-06-01 10:00:00',
        ]);
        $this->seedIdea($boardId, $authorId, 'Hoch-Score-Idee', [
            'score_cache' => 99,
            'created_at'  => '2025-01-01 10:00:00',
            'updated_at'  => '2025-01-01 10:00:00',
        ]);

        $body = (string) $this->createApp()
            ->handle($this->getBoard('sort-top-board', ['sort' => 'top']))
            ->getBody();

        $posHigh = strpos($body, 'Hoch-Score-Idee');
        $posLow  = strpos($body, 'Niedrig-Score-Idee');

        self::assertIsInt($posHigh);
        self::assertIsInt($posLow);
        self::assertLessThan($posLow, $posHigh, 'Hoch-Score-Idee muss vor Niedrig-Score-Idee erscheinen (?sort=top)');
    }

    // -------------------------------------------------------------------------
    // AC2 — ungültiger/fehlender ?sort= → Newest-Fallback
    // -------------------------------------------------------------------------

    public function test_missing_sort_param_falls_back_to_newest(): void
    {
        $boardId  = $this->insertBoard('sort-newest-board');
        $authorId = $this->insertUser('sortnewest@example.com');

        $this->seedIdea($boardId, $authorId, 'Ältere Idee', [
            'created_at' => '2025-01-01 10:00:00',
            'updated_at' => '2025-01-01 10:00:00',
        ]);
        $this->seedIdea($boardId, $authorId, 'Neuere Idee', [
            'created_at' => '2025-06-01 10:00:00',
            'updated_at' => '2025-06-01 10:00:00',
        ]);

        // Kein ?sort= → Newest-Fallback
        $body = (string) $this->createApp()
            ->handle($this->getBoard('sort-newest-board'))
            ->getBody();

        $posOld = strpos($body, 'Ältere Idee');
        $posNew = strpos($body, 'Neuere Idee');

        self::assertIsInt($posOld);
        self::assertIsInt($posNew);
        self::assertLessThan($posOld, $posNew, 'Ohne ?sort= muss Newest-Fallback greifen (created_at DESC)');
    }

    public function test_invalid_sort_param_falls_back_to_newest(): void
    {
        $boardId  = $this->insertBoard('sort-invalid-board');
        $authorId = $this->insertUser('sortinvalid@example.com');

        $this->seedIdea($boardId, $authorId, 'Ältere Idee', [
            'created_at' => '2025-01-01 10:00:00',
            'updated_at' => '2025-01-01 10:00:00',
        ]);
        $this->seedIdea($boardId, $authorId, 'Neuere Idee', [
            'created_at' => '2025-06-01 10:00:00',
            'updated_at' => '2025-06-01 10:00:00',
        ]);

        // Ungültiger ?sort= → Newest-Fallback
        $body = (string) $this->createApp()
            ->handle($this->getBoard('sort-invalid-board', ['sort' => 'invalid_sort_key']))
            ->getBody();

        $posOld = strpos($body, 'Ältere Idee');
        $posNew = strpos($body, 'Neuere Idee');

        self::assertIsInt($posOld);
        self::assertIsInt($posNew);
        self::assertLessThan($posOld, $posNew, 'Ungültiger ?sort= muss Newest-Fallback greifen (created_at DESC)');
    }

    // -------------------------------------------------------------------------
    // AC3 — Sort-Auswahl bleibt über Status-Filter und Pagination erhalten
    // -------------------------------------------------------------------------

    public function test_sort_is_preserved_in_status_filter_tab_links(): void
    {
        $boardId  = $this->insertBoard('sort-preserve-status-board');
        $authorId = $this->insertUser('preservestatus@example.com');
        $this->seedIdea($boardId, $authorId, 'Test-Idee');

        $body = (string) $this->createApp()
            ->handle($this->getBoard('sort-preserve-status-board', ['sort' => 'top']))
            ->getBody();

        // Status-Filter-Links müssen sort=top durchreichen
        self::assertStringContainsString('sort=top', $body, 'Status-Filter-Links sollen sort=top durchreichen');
    }

    public function test_sort_is_preserved_in_pagination_links(): void
    {
        $boardId  = $this->insertBoard('sort-preserve-page-board');
        $authorId = $this->insertUser('preservepage@example.com');

        // Mehr als DEFAULT_PAGE_SIZE Ideen erzeugen → Pagination erscheint
        for ($i = 1; $i <= 52; $i++) {
            $this->seedIdea($boardId, $authorId, "Idee {$i}");
        }

        $body = (string) $this->createApp()
            ->handle($this->getBoard('sort-preserve-page-board', ['sort' => 'top']))
            ->getBody();

        // Pagination-Links müssen sort=top durchreichen
        self::assertStringContainsString('sort=top', $body, 'Pagination-Links sollen sort=top durchreichen');
    }

    // -------------------------------------------------------------------------
    // AC4 — aktiver Tab wird korrekt aus active_sort markiert
    // -------------------------------------------------------------------------

    public function test_active_sort_tab_marked_for_top(): void
    {
        $boardId  = $this->insertBoard('sort-active-tab-top-board');
        $authorId = $this->insertUser('activetabtop@example.com');
        $this->seedIdea($boardId, $authorId, 'Test-Idee');

        $body = (string) $this->createApp()
            ->handle($this->getBoard('sort-active-tab-top-board', ['sort' => 'top']))
            ->getBody();

        // Der aktive Tab muss die CSS-Klasse vp-sorttab--active tragen
        // und der „Top"-Tab muss aktiv sein — wir prüfen dass der aktive Tab „Top" enthält
        self::assertMatchesRegularExpression(
            '/vp-sorttab--active[^>]*>[^<]*Top|Top[^<]*<[^>]*vp-sorttab--active/',
            $body,
            'Bei ?sort=top muss der Top-Tab aktiv markiert sein',
        );
    }

    public function test_active_sort_tab_marked_for_newest_by_default(): void
    {
        $boardId  = $this->insertBoard('sort-active-tab-newest-board');
        $authorId = $this->insertUser('activetabnewest@example.com');
        $this->seedIdea($boardId, $authorId, 'Test-Idee');

        $body = (string) $this->createApp()
            ->handle($this->getBoard('sort-active-tab-newest-board'))
            ->getBody();

        // Ohne ?sort= muss der „Neu"-Tab aktiv sein
        self::assertMatchesRegularExpression(
            '/vp-sorttab--active[^>]*>[^<]*Neu|Neu[^<]*<[^>]*vp-sorttab--active/',
            $body,
            'Ohne ?sort= muss der Neu-Tab aktiv markiert sein',
        );
    }

    // -------------------------------------------------------------------------
    // AC5 — „Umstritten"-Tab bleibt inaktiv/ohne erfundene Sortierung
    // -------------------------------------------------------------------------

    public function test_controversial_tab_stays_inactive(): void
    {
        $boardId  = $this->insertBoard('sort-controversial-board');
        $authorId = $this->insertUser('controversial@example.com');
        $this->seedIdea($boardId, $authorId, 'Test-Idee');

        $body = (string) $this->createApp()
            ->handle($this->getBoard('sort-controversial-board'))
            ->getBody();

        // „Umstritten" darf nie als aktiver Tab erscheinen
        self::assertStringNotContainsString(
            'sort=controversial',
            $body,
            'Umstritten-Tab darf keinen ?sort=controversial-Link erzeugen',
        );
    }
}
