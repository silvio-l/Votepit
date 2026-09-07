<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Jaro–Winkler string similarity.
 *
 * Pure, deterministic algorithm — no DB/HTTP dependency, no external service
 * (free-tier discipline: duplicate reranking must be fully local/DB-side).
 *
 * Returns a similarity score in [0.0, 1.0]; 1.0 means identical strings.
 * Used to rerank FULLTEXT recall candidates (IdeaRepository::findDuplicateCandidates())
 * against the normalized query title (TitleNormalizer).
 */
final readonly class JaroWinklerSimilarity
{
    /** Winkler prefix-boost scale factor (standard value, must stay <= 0.25). */
    private const PREFIX_SCALE = 0.1;

    /** Winkler prefix-boost only considers up to this many leading characters. */
    private const MAX_PREFIX_LENGTH = 4;

    /**
     * Computes the Jaro–Winkler similarity between $a and $b.
     */
    public function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $jaro = $this->jaro($a, $b);
        if ($jaro <= 0.0) {
            return 0.0;
        }

        $prefixLength = $this->commonPrefixLength($a, $b);

        return $jaro + ($prefixLength * self::PREFIX_SCALE * (1 - $jaro));
    }

    /**
     * Computes the (unboosted) Jaro similarity between $a and $b.
     */
    private function jaro(string $a, string $b): float
    {
        $aLen = mb_strlen($a);
        $bLen = mb_strlen($b);

        if ($aLen === 0 || $bLen === 0) {
            return 0.0;
        }

        $matchDistance = max((int) floor(max($aLen, $bLen) / 2) - 1, 0);

        $aChars = mb_str_split($a);
        $bChars = mb_str_split($b);

        $aMatched = array_fill(0, $aLen, false);
        $bMatched = array_fill(0, $bLen, false);

        $matches = 0;
        for ($i = 0; $i < $aLen; $i++) {
            $start = max(0, $i - $matchDistance);
            $end   = min($i + $matchDistance + 1, $bLen);

            for ($j = $start; $j < $end; $j++) {
                if ($bMatched[$j] || $aChars[$i] !== $bChars[$j]) {
                    continue;
                }
                $aMatched[$i] = true;
                $bMatched[$j] = true;
                $matches++;
                break;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        $transpositions = 0;
        $k = 0;
        for ($i = 0; $i < $aLen; $i++) {
            if (!$aMatched[$i]) {
                continue;
            }
            while (!$bMatched[$k]) {
                $k++;
            }
            if ($aChars[$i] !== $bChars[$k]) {
                $transpositions++;
            }
            $k++;
        }
        $transpositions = intdiv($transpositions, 2);

        return (
            ($matches / $aLen)
            + ($matches / $bLen)
            + (($matches - $transpositions) / $matches)
        ) / 3;
    }

    /**
     * Length of the common leading substring of $a and $b, capped at
     * MAX_PREFIX_LENGTH (standard Winkler boost rule).
     */
    private function commonPrefixLength(string $a, string $b): int
    {
        $aChars = mb_str_split($a);
        $bChars = mb_str_split($b);
        $max    = min(self::MAX_PREFIX_LENGTH, count($aChars), count($bChars));

        $length = 0;
        for ($i = 0; $i < $max; $i++) {
            if ($aChars[$i] !== $bChars[$i]) {
                break;
            }
            $length++;
        }

        return $length;
    }
}
