<?php

declare(strict_types=1);

namespace Votepit\Import;

use Votepit\Domain\StatusService;
use Votepit\Persistence\TagRepository;

/**
 * Pure parser from an uploaded export file into the internal import model
 * (ImportRow + validation report). One configured instance per supported
 * source format:
 *
 *  - `csv`:         Votepit's own template — columns title, body, status,
 *                   created_at, votes, tags (header names case-insensitive).
 *  - `canny`:       Canny's posts CSV export ("Export Data" on a board or
 *                   the feedback view): Title, Details, Status, Score (total
 *                   votes), Tags, Created/Date. Plaintext details.
 *  - `featurebase`: Featurebase's posts CSV export (Settings → Feedback →
 *                   Import/Export): Title, Content (HTML), Status, Upvotes,
 *                   Tags, Created at. Content is reduced to plaintext.
 *
 * Neither vendor documents exact header spellings, so each format accepts a
 * small alias list per field. Every other column — notably author name,
 * author e-mail, owner, MRR, image/post URLs — is ignored and reported back
 * as ignored; author identities are never part of the import model.
 *
 * Shared-origin invariant: the output is plaintext only. HTML is never
 * stored as markup (Featurebase content is converted to text, tags and
 * entities resolved), control characters are stripped, and the SPA renders
 * bodies through the same escaping pipeline as any user-submitted idea.
 * Formula-like values (`=…`, `+…`) are kept verbatim — they are inert text
 * here, and CsvZipExporter neutralises them on every export.
 */
final readonly class ImportAdapter
{
    public const FORMATS = ['csv', 'canny', 'featurebase'];

    public const TITLE_MAX     = 200;
    public const BODY_MAX      = 10000;
    public const VOTES_MAX     = 1000000;
    public const TAGS_PER_ROW  = 10;

    /**
     * Source status label (lower-cased) → core status. Covers Votepit's own
     * values plus the default workflows of Canny (open, under review,
     * planned, in progress, complete, closed) and Featurebase (in review,
     * planned, in progress, completed, rejected).
     */
    private const STATUS_MAP = [
        'open'         => 'open',
        'new'          => 'open',
        'under review' => 'open',
        'in review'    => 'open',
        'review'       => 'open',
        'planned'      => 'planned',
        'in_progress'  => 'in_progress',
        'in progress'  => 'in_progress',
        'in-progress'  => 'in_progress',
        'done'         => 'done',
        'complete'     => 'done',
        'completed'    => 'done',
        'shipped'      => 'done',
        'released'     => 'done',
        'declined'     => 'declined',
        'rejected'     => 'declined',
        'closed'       => 'declined',
        'archived'     => 'archived',
    ];

    /** Accepted explicit date formats (tried in order, interpreted in the server timezone unless the value carries an offset). */
    private const DATE_FORMATS = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd.m.Y H:i', 'd.m.Y', 'm/d/Y H:i:s', 'm/d/Y H:i', 'm/d/Y'];

    /**
     * @param array<'title'|'body'|'status'|'created_at'|'votes'|'tags', list<string>> $columns field => accepted header names
     */
    private function __construct(
        public string $format,
        private array $columns,
        private bool $htmlBody,
    ) {}

    public static function forFormat(string $format): ?self
    {
        return match ($format) {
            'csv' => new self('csv', [
                'title'      => ['title'],
                'body'       => ['body', 'description'],
                'status'     => ['status'],
                'created_at' => ['created_at', 'created at', 'date'],
                'votes'      => ['votes', 'vote_count'],
                'tags'       => ['tags'],
            ], false),
            'canny' => new self('canny', [
                'title'      => ['title', 'post title'],
                'body'       => ['details', 'detail', 'post details', 'post detail', 'description'],
                'status'     => ['status', 'post status'],
                'created_at' => ['created', 'created at', 'createdat', 'date', 'date created', 'created date'],
                'votes'      => ['score', 'votes', 'vote count', 'total votes', 'score (total votes)'],
                'tags'       => ['tags', 'tag'],
            ], false),
            'featurebase' => new self('featurebase', [
                'title'      => ['title', 'post title'],
                'body'       => ['content', 'post content', 'description'],
                'status'     => ['status', 'post status'],
                'created_at' => ['created at', 'createdat', 'creation date', 'created', 'date'],
                'votes'      => ['upvotes', 'upvote count', 'upvotes count', 'votes'],
                'tags'       => ['tags', 'tag', 'categories'],
            ], true),
            default => null,
        };
    }

    /** @throws ImportFileException */
    public function parse(string $content, \DateTimeImmutable $now): ImportParseResult
    {
        $table = CsvTable::parse($content);

        $index   = [];
        $ignored = [];
        foreach ($table->header as $position => $name) {
            $field = $this->fieldFor($name);
            if ($field === null) {
                if ($name !== '') {
                    $ignored[] = $name;
                }
                continue;
            }
            $index[$field] ??= $position;
        }

        if (!isset($index['title'])) {
            throw new ImportFileException(ImportFileException::MISSING_TITLE);
        }

        $rows   = [];
        $issues = [];
        foreach ($table->rows as $record) {
            $line = $record['line'];
            if ($record['fields'] === null) {
                $issues[] = ['line' => $line, 'field' => 'row', 'code' => 'column_count_mismatch', 'severity' => 'error'];
                continue;
            }

            $cell = static fn (string $f): string => isset($index[$f]) ? $record['fields'][$index[$f]] : '';

            $row = $this->buildRow($line, $cell, $now, $issues);
            if ($row instanceof \Votepit\Import\ImportRow) {
                $rows[] = $row;
            }
        }

        return new ImportParseResult($rows, $issues, count($table->rows), $ignored);
    }

    /**
     * @param \Closure(string): string $cell
     * @param list<array{line: int, field: string, code: string, severity: 'error'|'warning'}> $issues
     */
    private function buildRow(int $line, \Closure $cell, \DateTimeImmutable $now, array &$issues): ?ImportRow
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $this->plaintext($cell('title'))));
        if ($title === '') {
            $issues[] = ['line' => $line, 'field' => 'title', 'code' => 'title_missing', 'severity' => 'error'];
            return null;
        }
        if (mb_strlen($title, 'UTF-8') > self::TITLE_MAX) {
            $issues[] = ['line' => $line, 'field' => 'title', 'code' => 'title_too_long', 'severity' => 'error'];
            return null;
        }

        $rawBody = $cell('body');
        $body    = trim($this->plaintext($this->htmlBody ? $this->htmlToText($rawBody) : $rawBody));
        if (mb_strlen($body, 'UTF-8') > self::BODY_MAX) {
            $issues[] = ['line' => $line, 'field' => 'body', 'code' => 'body_too_long', 'severity' => 'error'];
            return null;
        }

        $rawStatus = mb_strtolower(trim($cell('status')), 'UTF-8');
        $status    = $rawStatus === '' ? 'open' : (self::STATUS_MAP[$rawStatus] ?? null);
        if ($status === null || !in_array($status, StatusService::VALID_STATUSES, true)) {
            $issues[] = ['line' => $line, 'field' => 'status', 'code' => 'status_unknown', 'severity' => 'warning'];
            $status   = 'open';
        }

        $rawDate   = trim($cell('created_at'));
        $createdAt = $rawDate === '' ? $now : $this->parseDate($rawDate, $now);
        if (!$createdAt instanceof \DateTimeImmutable) {
            $issues[]  = ['line' => $line, 'field' => 'created_at', 'code' => 'created_at_invalid', 'severity' => 'warning'];
            $createdAt = $now;
        }

        $rawVotes = str_replace([' ', ',', "'"], '', trim($cell('votes')));
        $votes    = 0;
        if ($rawVotes !== '') {
            if (preg_match('/^\d{1,7}$/', $rawVotes) === 1 && (int) $rawVotes <= self::VOTES_MAX) {
                $votes = (int) $rawVotes;
            } else {
                $issues[] = ['line' => $line, 'field' => 'votes', 'code' => 'votes_invalid', 'severity' => 'warning'];
            }
        }

        return new ImportRow($line, $title, $body, $status, $createdAt, $votes, $this->tags($line, $cell('tags'), $issues));
    }

    /**
     * @param list<array{line: int, field: string, code: string, severity: 'error'|'warning'}> $issues
     * @return list<string>
     */
    private function tags(int $line, string $raw, array &$issues): array
    {
        $tags = [];
        $seen = [];
        $parts = preg_split('/[,;|]/', $this->plaintext($raw));
        foreach ($parts === false ? [] : $parts as $part) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $part));
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name, 'UTF-8') > TagRepository::NAME_MAX_LENGTH) {
                $issues[] = ['line' => $line, 'field' => 'tags', 'code' => 'tag_too_long', 'severity' => 'warning'];
                continue;
            }
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            if (count($tags) >= self::TAGS_PER_ROW) {
                $issues[] = ['line' => $line, 'field' => 'tags', 'code' => 'too_many_tags', 'severity' => 'warning'];
                break;
            }
            $seen[$key] = true;
            $tags[]     = $name;
        }

        return $tags;
    }

    private function fieldFor(string $header): ?string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $header));
        foreach ($this->columns as $field => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $field;
            }
        }

        return null;
    }

    private function parseDate(string $raw, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $tz     = $now->getTimezone();
        $parsed = null;

        // ISO 8601 with time and optional fraction/offset (e.g. 2024-03-05T10:20:30.123Z).
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?$/', $raw) === 1) {
            try {
                $parsed = new \DateTimeImmutable($raw, $tz);
            } catch (\Exception) {
                $parsed = null;
            }
        } else {
            foreach (self::DATE_FORMATS as $format) {
                $candidate = \DateTimeImmutable::createFromFormat('!' . $format, $raw, $tz);
                $errors    = \DateTimeImmutable::getLastErrors();
                if ($candidate !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    $parsed = $candidate;
                    break;
                }
            }
        }

        if ($parsed === null) {
            return null;
        }

        $parsed = $parsed->setTimezone($tz);

        // A creation date in the future (or before 2000) is not a real creation date.
        return $parsed > $now || (int) $parsed->format('Y') < 2000 ? null : $parsed;
    }

    /** Strips control characters (keeps \n and \t) — plaintext only. */
    private function plaintext(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    }

    /**
     * Reduces an HTML fragment to plaintext: block ends and <br> become line
     * breaks, list items get a "- " prefix, every tag is dropped and entities
     * are decoded. The result is stored and rendered as text, never markup.
     */
    private function htmlToText(string $html): string
    {
        $text = (string) preg_replace('#<\s*(script|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $text = (string) preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text);
        $text = (string) preg_replace('#<\s*li\b[^>]*>#i', "\n- ", $text);
        $text = (string) preg_replace('#<\s*/\s*(p|div|h[1-6]|blockquote|pre|ul|ol|tr)\s*>#i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);

        return (string) preg_replace('/\n{3,}/', "\n\n", $text);
    }
}
