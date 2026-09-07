<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Converts an AccountExportService::build() document into a ZIP archive of
 * one CSV file per table (customer self-export — the
 * requirement is explicitly "JSON/CSV"; a single nested JSON document is the
 * natural relational representation, a ZIP-of-CSVs is the natural per-table
 * representation for spreadsheet tools — this class implements the latter so
 * both are actually supported instead of picking just one).
 *
 * `account` (a single associative row, not a list) becomes account.csv with
 * exactly one data row. Every other top-level key is a list of associative
 * rows and becomes <key>.csv with one header row (from the first row's keys)
 * followed by one line per row. An empty list becomes an empty (headerless)
 * CSV file — still present in the archive, so the file set is identical
 * regardless of whether a given table happens to be empty for this account.
 * `exported_at` is written into a small meta.csv rather than dropped.
 */
final class CsvZipExporter
{
    /**
     * @param array<string, mixed> $data AccountExportService::build() output.
     */
    public static function build(array $data): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'votepit-export-');
        if ($tmpPath === false) {
            throw new \RuntimeException('CsvZipExporter: could not create a temporary file.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpPath, \ZipArchive::OVERWRITE) !== true) {
            unlink($tmpPath);
            throw new \RuntimeException('CsvZipExporter: ZipArchive could not be opened.');
        }

        $zip->addFromString('meta.csv', self::rowsToCsv([
            ['exported_at' => is_string($data['exported_at'] ?? null) ? $data['exported_at'] : ''],
        ]));

        $account = $data['account'] ?? [];
        $zip->addFromString('account.csv', self::rowsToCsv(is_array($account) && $account !== [] ? [$account] : []));

        foreach ($data as $key => $rows) {
            if ($key === 'exported_at' || $key === 'account') {
                continue;
            }
            /** @var list<array<string, mixed>> $rows */
            $zip->addFromString($key . '.csv', self::rowsToCsv($rows));
        }

        $zip->close();

        $bytes = file_get_contents($tmpPath);
        unlink($tmpPath);

        if ($bytes === false) {
            throw new \RuntimeException('CsvZipExporter: ZIP file could not be read.');
        }

        return $bytes;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function rowsToCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('CsvZipExporter: could not open a stream.');
        }

        if ($rows !== []) {
            fputcsv($stream, array_map(strval(...), array_keys($rows[0])), escape: '\\');
            foreach ($rows as $row) {
                fputcsv($stream, array_map(
                    static fn (mixed $v): string => self::sanitizeCell($v === null ? '' : (is_scalar($v) ? (string) $v : (string) json_encode($v))),
                    $row,
                ), escape: '\\');
            }
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }

    /**
     * CSV/formula-injection defense (CWE-1236): idea titles, comment bodies etc. are
     * attacker-controlled (any board voter) and land verbatim as cell values in an
     * account owner's export. A value like `=HYPERLINK(...)` or `=cmd|'/c calc'!A1`
     * executes as a formula the moment the owner opens the file in Excel/Sheets —
     * prefixing a leading `'` (the standard OWASP mitigation) forces spreadsheet
     * apps to treat it as a literal string instead.
     */
    private static function sanitizeCell(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }
}
