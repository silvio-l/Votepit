<?php

declare(strict_types=1);

namespace Votepit\Import;

/**
 * One idea of the internal import model — what every adapter (CSV, Canny,
 * Featurebase) produces, already validated and normalised: plaintext title
 * (1..TITLE_MAX chars) and body, a core status value, a non-negative vote
 * baseline and plain tag names. Deliberately has no author field: imported
 * authors are never created as users and their names/e-mails are dropped
 * at parse time (data minimisation, ADR 0002).
 */
final readonly class ImportRow
{
    /** @param list<string> $tags */
    public function __construct(
        public int $line,
        public string $title,
        public string $body,
        public string $status,
        public \DateTimeImmutable $createdAt,
        public int $votes,
        public array $tags,
    ) {}
}
