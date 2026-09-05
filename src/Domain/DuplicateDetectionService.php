<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Reranks FULLTEXT recall candidates by Jaro–Winkler similarity against the
 * normalized query title.
 *
 * Pure domain collaborator (no DB/HTTP), mirroring the ContentModerationService
 * shape: constructor-injected pure collaborators, unit-testable in isolation.
 *
 * IdeaRepository::findDuplicateCandidates() supplies the (unranked) recall pool;
 * this service is the "rerank" half of "FULLTEXT recall + Jaro–Winkler rerank".
 * Surfacing only — no auto-merge (roadmap's explicit "Not included").
 */
final readonly class DuplicateDetectionService
{
    /**
     * Minimum Jaro–Winkler similarity (0..1) for a candidate to be surfaced.
     * Chosen conservatively above the well-known "MARTHA"/"MARHTA"-class typo
     * scores (~0.96) down to genuinely close near-duplicates, while staying
     * clear of unrelated same-length titles (spot-checked manually).
     */
    private const SIMILARITY_THRESHOLD = 0.82;

    public function __construct(
        private TitleNormalizer $normalizer,
        private JaroWinklerSimilarity $similarity,
    ) {}

    /**
     * Reranks $candidates (rows with a 'title_normalized' key, e.g. from
     * IdeaRepository::findDuplicateCandidates()) by similarity to $title.
     *
     * Candidates below SIMILARITY_THRESHOLD are dropped. The remainder is
     * sorted by similarity descending and capped at $limit. Each surviving
     * row gets a 'similarity' key added (float, 0..1, rounded to 4 decimals).
     *
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    public function rank(string $title, array $candidates, int $limit = 5): array
    {
        $queryKey = $this->normalizer->normalize($title);
        if ($queryKey === '') {
            return [];
        }

        $ranked = [];
        foreach ($candidates as $candidate) {
            $candidateKey = (string) ($candidate['title_normalized'] ?? '');
            if ($candidateKey === '') {
                continue;
            }

            $score = $this->similarity->similarity($queryKey, $candidateKey);
            if ($score < self::SIMILARITY_THRESHOLD) {
                continue;
            }

            $candidate['similarity'] = round($score, 4);
            $ranked[] = $candidate;
        }

        usort($ranked, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return array_slice($ranked, 0, $limit);
    }
}
