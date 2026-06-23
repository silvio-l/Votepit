<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\IdeaRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Allow-List-Guard für IdeaRepository::listByBoard — Sortierachse (Sprint 3 Rework).
 *
 * Beweist: ein nicht-erlaubter $sortKey gelangt NICHT als roher String in die Query;
 * stattdessen wird 'newest' (created_at DESC) als Fallback verwendet.
 *
 * Abgedeckte Anforderung (Reviewer-Blocker):
 *   „Fix: Allow-List-Guard vor der Konkatenation — unbekannter Wert → Newest/created_at DESC."
 */
final class IdeaRepositorySortTest extends IntegrationTestCase
{
    private IdeaRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new IdeaRepository($this->conn);
    }

    /**
     * Ein ungültiger sortKey → Fallback auf Newest (created_at DESC).
     * Ergebnis: gleiche Reihenfolge wie beim Übergeben von 'newest'.
     */
    public function test_unknown_sort_key_falls_back_to_newest(): void
    {
        $boardId  = $this->insertBoard('sort-test-board');
        $authorId = $this->insertUser('sorttest@example.com');

        $this->seedIdea($boardId, $authorId, 'Ältere Idee', [
            'created_at' => '2025-01-01 10:00:00',
            'updated_at' => '2025-01-01 10:00:00',
        ]);
        $this->seedIdea($boardId, $authorId, 'Neuere Idee', [
            'created_at' => '2025-06-01 10:00:00',
            'updated_at' => '2025-06-01 10:00:00',
        ]);

        // Ungültiger sortKey — darf NICHT als roher SQL-String einfließen.
        $rowsUnknown = $this->repo->listByBoard($boardId, null, 50, 0, 'injected; DROP TABLE ideas;--');
        $rowsNewest  = $this->repo->listByBoard($boardId, null, 50, 0, 'newest');

        // Beide Aufrufe müssen dieselbe Anzahl Zeilen liefern (kein SQL-Fehler, kein Datenverlust).
        self::assertCount(2, $rowsUnknown, 'Unbekannter Sort-Key darf keinen SQL-Fehler verursachen.');
        self::assertCount(2, $rowsNewest);

        // Reihenfolge: Newest first (created_at DESC) — Neuere Idee kommt zuerst.
        self::assertSame('Neuere Idee', $rowsUnknown[0]['title'], 'Fallback muss created_at DESC (Newest) sein.');
        self::assertSame('Ältere Idee', $rowsUnknown[1]['title']);

        // Reihenfolge muss identisch mit explizit 'newest' sein.
        self::assertSame($rowsNewest[0]['title'], $rowsUnknown[0]['title']);
        self::assertSame($rowsNewest[1]['title'], $rowsUnknown[1]['title']);
    }

    /** Bekannte Sort-Keys werden korrekt verarbeitet. */
    public function test_known_sort_keys_are_accepted(): void
    {
        $boardId  = $this->insertBoard('sort-known-board');
        $authorId = $this->insertUser('sortknown@example.com');

        $this->seedIdea($boardId, $authorId, 'Idee A');
        $this->seedIdea($boardId, $authorId, 'Idee B');

        foreach (array_keys(IdeaRepository::SORT_AXES) as $key) {
            $rows = $this->repo->listByBoard($boardId, null, 50, 0, $key);
            self::assertCount(2, $rows, "Sort-Key '{$key}' soll 2 Zeilen liefern.");
        }
    }
}
