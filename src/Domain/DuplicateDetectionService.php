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

    /**
     * Word-level second signal for rephrased titles ("I'd like a mobile
     * app" ↔ "We need a mobile app", in any language), which the compact-key
     * Jaro–Winkler misses: at least MIN_SHARED_WORDS content words in common
     * and a Dice overlap of at least WORD_OVERLAP_THRESHOLD.
     */
    private const WORD_OVERLAP_THRESHOLD = 0.66;
    private const MIN_SHARED_WORDS       = 2;

    /** Shortest word part that may match inside a compound ("smartphone" ↔ "phone"). */
    private const MIN_PART_LENGTH = 4;

    /**
     * Function words and request phrasing in the six UI languages (folded to
     * ASCII like TitleNormalizer::words()). They carry no topic, so they never
     * count as a shared word.
     */
    private const FILLER_WORDS = [
        // en
        'a', 'an', 'the', 'and', 'or', 'of', 'to', 'for', 'in', 'on', 'at', 'by', 'with', 'from', 'my', 'our',
        'your', 'it', 'is', 'be', 'i', 'we', 'you', 'me', 'us', 'please', 'add', 'need', 'want', 'would', 'like',
        'could', 'should', 'can', 'have', 'get', 'make', 'allow', 'support', 'option', 'feature', 'new', 'more',
        // de
        'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einen', 'einem', 'einer', 'und', 'oder', 'fur', // export-ok: comment-language
        'furs', 'mit', 'von', 'zu', 'zum', 'zur', 'im', 'in', 'am', 'auf', 'ich', 'wir', 'mir', 'uns', 'mein',
        'meine', 'unser', 'unsere', 'bitte', 'mochte', 'mochten', 'brauche', 'brauchen', 'brauchte', 'hatte',
        'hatten', 'haben', 'habe', 'ware', 'waren', 'wunsche', 'gerne', 'gern', 'auch', 'es', 'ist', 'sein', // export-ok: comment-language
        'neue', 'neuer', 'neues', 'mehr', 'moglichkeit', 'funktion', 'sollte', 'konnte', 'kann', // export-ok: comment-language
        // fr
        'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'et', 'ou', 'pour', 'avec', 'je', 'nous', 'voudrais',
        'veux', 'besoin', 'sil', 'vous', 'plait', 'ajouter',
        // es
        'el', 'los', 'las', 'uno', 'una', 'y', 'o', 'para', 'con', 'yo', 'nosotros', 'quiero', 'necesito',
        'necesitamos', 'por', 'favor', 'anadir',
        // nl
        'het', 'een', 'en', 'of', 'voor', 'met', 'ik', 'wij', 'we', 'graag', 'willen', 'wil', 'nodig', 'toevoegen',
        // it
        'il', 'lo', 'gli', 'uno', 'e', 'per', 'con', 'io', 'noi', 'vorrei', 'vogliamo', 'serve', 'aggiungere',
    ];

    /** Inflection endings stripped once from words of five or more letters. */
    private const ENDINGS = ['en', 'es', 'er', 'e', 'n', 's'];

    public function __construct(
        private TitleNormalizer $normalizer,
        private JaroWinklerSimilarity $similarity,
    ) {}

    /**
     * Reranks $candidates (rows with 'title' and 'title_normalized' keys, e.g.
     * from IdeaRepository::findDuplicateCandidates()) by similarity to $title.
     *
     * A candidate is a duplicate when its compact key reaches
     * SIMILARITY_THRESHOLD (typos, spacing) or it shares enough content words
     * (rephrasing). Duplicates are sorted by similarity descending and capped
     * at $limit. Each surviving row gets a 'similarity' key added (float, 0..1,
     * rounded to 4 decimals).
     *
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    public function rank(string $title, array $candidates, int $limit = 5): array
    {
        return $this->select($title, $candidates, $limit, nearMisses: false);
    }

    /**
     * The related candidates that are NOT duplicates by rank(): at least one
     * content word in common, best word overlap first, capped at $limit. The
     * small, cheap pool an optional second opinion (DuplicateConfirmer) may
     * judge when rank() found nothing.
     *
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    public function nearMisses(string $title, array $candidates, int $limit = 5): array
    {
        return $this->select($title, $candidates, $limit, nearMisses: true);
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function select(string $title, array $candidates, int $limit, bool $nearMisses): array
    {
        $queryKey = $this->normalizer->normalize($title);
        if ($queryKey === '') {
            return [];
        }
        $queryWords = $this->contentWords($title);

        $selected = [];
        foreach ($candidates as $candidate) {
            $candidateKey = (string) ($candidate['title_normalized'] ?? '');
            if ($candidateKey === '') {
                continue;
            }

            $keyScore = $this->similarity->similarity($queryKey, $candidateKey);
            [$wordScore, $shared] = $this->wordOverlap($queryWords, $this->contentWords((string) ($candidate['title'] ?? '')));
            $isDuplicate = $keyScore >= self::SIMILARITY_THRESHOLD
                || ($shared >= self::MIN_SHARED_WORDS && $wordScore >= self::WORD_OVERLAP_THRESHOLD);

            if ($nearMisses ? ($isDuplicate || $shared === 0) : !$isDuplicate) {
                continue;
            }
            $candidate['similarity'] = round($nearMisses ? $wordScore : max($keyScore, $wordScore), 4);
            $selected[] = $candidate;
        }

        usort($selected, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return array_slice($selected, 0, $limit);
    }

    /**
     * Distinct content words of $title: folded, filler words dropped, one
     * inflection ending stripped ("themes"/"theme" → "them", "apps" → "app").
     *
     * @return list<string>
     */
    private function contentWords(string $title): array
    {
        $words = [];
        foreach ($this->normalizer->words($title) as $word) {
            if (in_array($word, self::FILLER_WORDS, true)) {
                continue;
            }
            $words[$this->stem($word)] = true;
        }

        return array_map(strval(...), array_keys($words));
    }

    private function stem(string $word): string
    {
        $length = strlen($word);
        if ($length === 4 && str_ends_with($word, 's')) {
            return substr($word, 0, 3);
        }
        if ($length >= 5) {
            foreach (self::ENDINGS as $ending) {
                if (str_ends_with($word, $ending)) {
                    return substr($word, 0, -strlen($ending));
                }
            }
        }

        return $word;
    }

    /**
     * Dice overlap of two word sets (words also match as the head or tail of
     * a compound) and the number of shared words.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return array{0: float, 1: int}
     */
    private function wordOverlap(array $a, array $b): array
    {
        if ($a === [] || $b === []) {
            return [0.0, 0];
        }
        $matchedA = count(array_filter($a, fn (string $word): bool => $this->matchesAny($word, $b)));
        $matchedB = count(array_filter($b, fn (string $word): bool => $this->matchesAny($word, $a)));

        return [($matchedA + $matchedB) / (count($a) + count($b)), min($matchedA, $matchedB)];
    }

    /** @param list<string> $words */
    private function matchesAny(string $word, array $words): bool
    {
        foreach ($words as $other) {
            if ($word === $other) {
                return true;
            }
            [$short, $long] = strlen($word) <= strlen($other) ? [$word, $other] : [$other, $word];
            if (strlen($short) >= self::MIN_PART_LENGTH && (str_starts_with($long, $short) || str_ends_with($long, $short))) {
                return true;
            }
        }

        return false;
    }
}
