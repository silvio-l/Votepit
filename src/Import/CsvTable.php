<?php

declare(strict_types=1);

namespace Votepit\Import;

/**
 * Strict CSV reader for untrusted uploads (RFC 4180 subset).
 *
 * - Hard limits BEFORE any parsing work: MAX_BYTES on the raw content and
 *   MAX_ROWS data rows; exceeding either rejects the whole file.
 * - Must be valid UTF-8 (a leading BOM is dropped); anything else is
 *   rejected instead of guessed/transcoded.
 * - Delimiter: ',' — or ';' when the header line contains ';' but no ','
 *   (spreadsheet exports in DE/FR/NL/IT locales). Decided from the header
 *   only, never per row.
 * - Quoted fields may span lines; a doubled quote is the only escape (no
 *   backslash escaping). An unterminated quote rejects the file.
 * - A data row whose field count differs from the header is not dropped
 *   silently: it is returned with `fields: null` so the caller reports it.
 *
 * Pure: no DB, no I/O beyond an in-memory stream.
 */
final readonly class CsvTable
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_ROWS  = 1000;

    /**
     * @param list<string> $header lower-cased, trimmed column names
     * @param list<array{line: int, fields: list<string>|null}> $rows
     */
    private function __construct(
        public array $header,
        public array $rows,
    ) {}

    /** @throws ImportFileException */
    public static function parse(string $content): self
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new ImportFileException(ImportFileException::TOO_LARGE);
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new ImportFileException(ImportFileException::INVALID_ENCODING);
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (trim($content) === '') {
            throw new ImportFileException(ImportFileException::EMPTY);
        }

        $records = self::records($content, self::delimiter($content));

        $headerRecord = array_shift($records);
        if ($headerRecord === null) {
            throw new ImportFileException(ImportFileException::EMPTY);
        }

        $header = array_map(static fn (string $h): string => mb_strtolower(trim($h), 'UTF-8'), $headerRecord['fields']);

        $rows = [];
        foreach ($records as $record) {
            $fields = $record['fields'];
            // Fully blank lines (e.g. a trailing newline) are not rows.
            if (count($fields) === 1 && trim($fields[0]) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new ImportFileException(ImportFileException::TOO_MANY_ROWS);
            }
            $rows[] = ['line' => $record['line'], 'fields' => count($fields) === count($header) ? $fields : null];
        }

        return new self($header, $rows);
    }

    private static function delimiter(string $content): string
    {
        $newline   = strpos($content, "\n");
        $firstLine = $newline === false ? $content : substr($content, 0, $newline);

        return str_contains($firstLine, ';') && !str_contains($firstLine, ',') ? ';' : ',';
    }

    /**
     * Character-level state machine — PHP's str_getcsv/fgetcsv accept
     * malformed input (stray quotes inside unquoted fields, unterminated
     * quotes) without telling the caller, which is exactly what "strict"
     * must not do.
     *
     * @return list<array{line: int, fields: list<string>}>
     * @throws ImportFileException
     */
    private static function records(string $content, string $delimiter): array
    {
        $records   = [];
        $fields    = [];
        $field     = '';
        $inQuotes  = false;
        $wasQuoted = false;
        $line      = 1;
        $startLine = 1;
        $len       = strlen($content);

        for ($i = 0; $i < $len; $i++) {
            $c = $content[$i];

            if ($inQuotes) {
                if ($c === '"') {
                    if ($i + 1 < $len && $content[$i + 1] === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    if ($c === "\n") {
                        $line++;
                    }
                    $field .= $c;
                }
                continue;
            }

            if ($c === '"') {
                if ($field !== '' || $wasQuoted) {
                    // A quote inside an unquoted field, or text after a closing quote.
                    throw new ImportFileException(ImportFileException::MALFORMED);
                }
                $inQuotes  = true;
                $wasQuoted = true;
                continue;
            }

            if ($c === $delimiter) {
                $fields[]  = $field;
                $field     = '';
                $wasQuoted = false;
                continue;
            }

            if ($c === "\n") {
                $fields[]  = $field;
                $records[] = ['line' => $startLine, 'fields' => $fields];
                $fields    = [];
                $field     = '';
                $wasQuoted = false;
                $line++;
                $startLine = $line;
                continue;
            }

            if ($wasQuoted) {
                throw new ImportFileException(ImportFileException::MALFORMED);
            }
            $field .= $c;
        }

        if ($inQuotes) {
            throw new ImportFileException(ImportFileException::MALFORMED);
        }

        if ($field !== '' || $wasQuoted || $fields !== []) {
            $fields[]  = $field;
            $records[] = ['line' => $startLine, 'fields' => $fields];
        }

        return $records;
    }
}
