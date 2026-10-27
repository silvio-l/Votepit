<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Renders a board's results (broaden-use-cases issue 07) as one CSV file
 * for spreadsheets — minutes attachments, planning meetings, team reviews.
 *
 * - UTF-8 with BOM (Excel otherwise guesses a legacy code page), CRLF line
 *   ends and RFC 4180 quoting (doubled quotes, no backslash escape).
 * - Separator chosen by the caller (`;` for German Excel, `,` otherwise).
 * - Text cells (title, body, status label, tags) are attacker-controlled
 *   and pass through CsvZipExporter::sanitizeCell() (formula injection,
 *   CWE-1236). Numeric cells are written as numbers, never prefixed, so a
 *   negative score stays a number.
 * - Deliberately no author identity (no author id, name or e-mail): the
 *   export is a results document, not a personal-data export.
 *
 * Input rows are IdeaRepository::listForResultsExport() rows; tags come
 * from TagRepository::tagsForIdeas() (one set-based query).
 */
final class BoardResultsCsv
{
    /** Header row (stable, machine-friendly English keys). */
    public const COLUMNS = [
        'id', 'title', 'body', 'status_key', 'status_label', 'status_category',
        'up', 'down', 'score', 'consensus_percent', 'comments', 'tags',
        'created_at', 'target_date',
    ];

    /**
     * @param list<array<string, mixed>>              $ideas
     * @param array<int, list<array{name: string}>>   $tagsByIdea
     */
    public static function build(array $ideas, array $tagsByIdea, string $separator): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('BoardResultsCsv: could not open a stream.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        self::write($stream, self::COLUMNS, $separator);

        foreach ($ideas as $idea) {
            $id   = self::int($idea['id'] ?? 0);
            $up   = self::int($idea['up_count'] ?? 0);
            $down = self::int($idea['down_count'] ?? 0);
            $tags = array_map(static fn (array $tag): string => $tag['name'], $tagsByIdea[$id] ?? []);

            self::write($stream, [
                (string) $id,
                self::text($idea['title'] ?? ''),
                self::text($idea['body'] ?? ''),
                self::plain($idea['status_key'] ?? ''),
                self::text($idea['status_label'] ?? ''),
                self::plain($idea['status_category'] ?? ''),
                (string) $up,
                (string) $down,
                (string) self::int($idea['score_cache'] ?? 0),
                $up + $down > 0 ? (string) ApprovalDisplay::percent(100 * $up / ($up + $down), $up > 0, $down > 0) : '',
                (string) self::int($idea['comment_count'] ?? 0),
                self::text(implode(', ', $tags)),
                self::plain($idea['created_at'] ?? ''),
                self::plain($idea['target_date'] ?? ''),
            ], $separator);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }

    /**
     * @param resource     $stream
     * @param list<string> $fields
     */
    private static function write($stream, array $fields, string $separator): void
    {
        fputcsv($stream, $fields, $separator, '"', '', "\r\n");
    }

    private static function text(mixed $value): string
    {
        return CsvZipExporter::sanitizeCell(self::plain($value));
    }

    private static function plain(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
