<?php

declare(strict_types=1);

namespace Votepit\Domain;

use Doctrine\DBAL\Exception as DbalException;
use Votepit\Extension\DuplicateConfirmationAvailability;
use Votepit\Extension\DuplicateConfirmer;
use Votepit\Persistence\IdeaRepository;

/**
 * Deterministic duplicate candidates for a title on one board: the
 * board-scoped recall pool (IdeaRepository::findDuplicateCandidates(), which
 * never returns moderated or merged ideas) reranked by Jaro–Winkler and
 * word-overlap similarity (DuplicateDetectionService).
 *
 * The single candidate seam shared by the submit-time duplicate hint
 * (IdeaSearchDuplicatesAction) and the moderator's merge suggestions
 * (IdeaMergeAction, issue 10). Deterministic matching always runs first; only
 * when it finds nothing may an optional DuplicateConfirmer contributed by an
 * extension (issue 11) judge the few related near misses — it only ever sees
 * their id and title, and only the near misses it confirms are returned.
 */
final readonly class DuplicateCandidateFinder
{
    /** Recall pool size fetched before reranking. */
    private const RECALL_LIMIT = 30;

    public function __construct(
        private IdeaRepository $ideas,
        private DuplicateDetectionService $duplicateDetection,
        private ?DuplicateConfirmer $confirmer = null,
    ) {}

    /**
     * @param int|null $excludeIdeaId       An idea never to suggest (the merge source itself).
     * @param int|null $confirmForAccountId The board's account — set to ask the confirmer (if any).
     * @return list<array<string, mixed>> Recall rows plus 'similarity' and 'confirmed'
     *         (null for deterministic matches, true for near misses the confirmer
     *         judged duplicates), best first.
     * @throws DbalException
     */
    public function find(int $boardId, string $title, int $limit = 5, ?int $excludeIdeaId = null, ?int $confirmForAccountId = null): array
    {
        $recall = $this->ideas->findDuplicateCandidates($boardId, $title, self::RECALL_LIMIT);
        if ($excludeIdeaId !== null) {
            $recall = array_values(array_filter(
                $recall,
                static fn (array $row): bool => (int) ($row['id'] ?? 0) !== $excludeIdeaId,
            ));
        }

        $ranked = $this->duplicateDetection->rank($title, $recall, $limit);
        if ($ranked !== [] || !$this->confirmer instanceof DuplicateConfirmer || $confirmForAccountId === null) {
            return array_map(static fn (array $row): array => $row + ['confirmed' => null], $ranked);
        }

        // Deterministic first: only when nothing matched, the confirmer may judge
        // the few related near misses — so a clear match never costs a call.
        $nearMisses = $this->duplicateDetection->nearMisses($title, $recall, $limit);
        if ($nearMisses === []) {
            return [];
        }
        $verdict = $this->confirmer->confirm($confirmForAccountId, $title, array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title']],
            $nearMisses,
        ));

        return $verdict === null ? [] : $this->confirmedOnly($nearMisses, $verdict);
    }

    /**
     * The confirmer's availability for $accountId, or null when no confirmer
     * is installed or it is switched off — the SPA then shows nothing.
     */
    public function confirmationAvailability(int $accountId): ?DuplicateConfirmationAvailability
    {
        $availability = $this->confirmer?->availability($accountId);

        return $availability === DuplicateConfirmationAvailability::Off ? null : $availability;
    }

    /**
     * The near misses the confirmer judged duplicates, in its order. Ids
     * outside the candidate set are ignored.
     *
     * @param list<array<string, mixed>> $nearMisses
     * @param list<int> $confirmedIds
     * @return list<array<string, mixed>>
     */
    private function confirmedOnly(array $nearMisses, array $confirmedIds): array
    {
        $byId = [];
        foreach ($nearMisses as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $confirmed = [];
        foreach ($confirmedIds as $id) {
            if (isset($byId[$id])) {
                $confirmed[] = $byId[$id] + ['confirmed' => true];
                unset($byId[$id]);
            }
        }

        return $confirmed;
    }
}
