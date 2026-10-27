<?php

declare(strict_types=1);

namespace Votepit\Import;

/**
 * Output of an ImportAdapter: the importable rows plus the validation
 * report. An issue with severity `error` means its row is NOT in $rows;
 * `warning` means the row is imported with a documented fallback (unknown
 * status → open, unparsable date → import time, over-long tag → dropped).
 * `field`/`code` are closed machine-readable values, never file content.
 */
final readonly class ImportParseResult
{
    /**
     * @param list<ImportRow> $rows
     * @param list<array{line: int, field: string, code: string, severity: 'error'|'warning'}> $issues
     * @param list<string> $ignoredColumns header names that matched no known column
     */
    public function __construct(
        public array $rows,
        public array $issues,
        public int $totalRows,
        public array $ignoredColumns,
    ) {}
}
