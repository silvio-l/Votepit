<?php

declare(strict_types=1);

namespace Votepit\Import;

/**
 * The uploaded file as a whole is unusable (too large, too many rows, not
 * UTF-8, no header, no title column, malformed CSV). Nothing is imported;
 * the action answers 422 with $reason — a closed machine-readable code,
 * never echoing file content back.
 */
final class ImportFileException extends \RuntimeException
{
    public const TOO_LARGE       = 'too_large';
    public const TOO_MANY_ROWS   = 'too_many_rows';
    public const EMPTY           = 'empty';
    public const INVALID_ENCODING = 'invalid_encoding';
    public const MALFORMED       = 'malformed_csv';
    public const MISSING_TITLE   = 'missing_title_column';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Import file rejected: ' . $reason);
    }
}
