<?php

declare(strict_types=1);

namespace Votepit\Import;

use Doctrine\DBAL\Connection;
use Votepit\Domain\TitleNormalizer;
use Votepit\Persistence\IdeaImportRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\TagRepository;

/**
 * Board import: dry-run preview and apply (issue cp-16).
 *
 * The caller (BoardImportAction) has already resolved the board strictly
 * inside the request's account and authorised an account admin; every
 * write here binds that one board_id. Flow:
 *
 *   1. ImportAdapter parses the file into ImportRows + validation report.
 *   2. Rows whose normalised title already exists on the board (or earlier
 *      in the same file) are skipped as `duplicate_title` — a re-upload
 *      under a fresh idempotency key therefore cannot double the board.
 *   3. Tags resolve case-insensitively against the board's tags; at most
 *      MAX_NEW_TAGS new tags per import, further new names are dropped.
 *   4. Dry run stops here. Apply runs in ONE transaction (all or nothing):
 *      record the import (UNIQUE(board_id, idempotency_key) backstop),
 *      create tags, create ideas through IdeaRepository::createImported()
 *      authored by the importing admin, assign tags.
 *
 * Idempotency: the same key with the same file returns the stored summary
 * (`replayed: true`) without writing; the same key with different content is
 * a conflict. No webhooks or notifications are fired for imported ideas —
 * a bulk import must not fan out hundreds of `idea.created` deliveries.
 */
final readonly class BoardImportService
{
    public const MAX_NEW_TAGS = 50;
    public const PREVIEW_ROWS = 20;

    /** Same default colour as TagCreateAction. */
    private const NEW_TAG_COLOR = '#3b82f6';

    public const RESULT_OK       = 'ok';
    public const RESULT_REPLAYED = 'replayed';
    public const RESULT_CONFLICT = 'conflict';

    public function __construct(
        private Connection $conn,
        private IdeaRepository $ideaRepo,
        private TagRepository $tagRepo,
        private IdeaImportRepository $importRepo,
        private TitleNormalizer $normalizer,
    ) {}

    /**
     * @return array{
     *     result: string,
     *     total_rows: int,
     *     importable: int,
     *     skipped: int,
     *     new_tags: list<string>,
     *     ignored_columns: list<string>,
     *     issues: list<array{line: int, field: string, code: string, severity: 'error'|'warning'}>,
     *     preview: list<array{line: int, title: string, status: string, votes: int, created_at: string, tags: list<string>}>,
     *     import_id: int|null
     * }
     * @throws ImportFileException
     */
    public function run(
        int $boardId,
        int $actorUserId,
        ImportAdapter $adapter,
        string $content,
        bool $dryRun,
        ?string $idempotencyKey,
        \DateTimeImmutable $now,
    ): array {
        $parsed = $adapter->parse($content, $now);
        $issues = $parsed->issues;

        // 2. duplicate titles (board + within the file)
        $seenTitles = $this->ideaRepo->normalizedTitlesForBoard($boardId);
        $rows       = [];
        foreach ($parsed->rows as $row) {
            $key = $this->normalizer->normalize($row->title);
            if (isset($seenTitles[$key])) {
                $issues[] = ['line' => $row->line, 'field' => 'title', 'code' => 'duplicate_title', 'severity' => 'error'];
                continue;
            }
            $seenTitles[$key] = true;
            $rows[]           = ['row' => $row, 'normalized' => $key];
        }

        // 3. tags
        $tagIds = [];
        foreach ($this->tagRepo->listByBoard($boardId) as $tag) {
            $tagIds[mb_strtolower($tag['name'], 'UTF-8')] = $tag['id'];
        }
        $newTags = [];
        foreach ($rows as $i => $entry) {
            $kept = [];
            foreach ($entry['row']->tags as $name) {
                $lower = mb_strtolower($name, 'UTF-8');
                if (!isset($tagIds[$lower]) && !isset($newTags[$lower])) {
                    if (count($newTags) >= self::MAX_NEW_TAGS) {
                        $issues[] = ['line' => $entry['row']->line, 'field' => 'tags', 'code' => 'tag_limit', 'severity' => 'warning'];
                        continue;
                    }
                    $newTags[$lower] = $name;
                }
                $kept[] = $name;
            }
            $rows[$i]['tags'] = $kept;
        }

        // One entry per (line, field, code) — e.g. two over-long tags in one
        // row are one note, not two.
        $unique = [];
        foreach ($issues as $issue) {
            $unique[$issue['line'] . '|' . $issue['field'] . '|' . $issue['code']] ??= $issue;
        }
        $issues = array_values($unique);
        usort($issues, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        $report = [
            'result'          => self::RESULT_OK,
            'total_rows'      => $parsed->totalRows,
            'importable'      => count($rows),
            'skipped'         => $parsed->totalRows - count($rows),
            'new_tags'        => array_values($newTags),
            'ignored_columns' => $parsed->ignoredColumns,
            'issues'          => $issues,
            'preview'         => array_map(
                static fn (array $e): array => [
                    'line'       => $e['row']->line,
                    'title'      => $e['row']->title,
                    'status'     => $e['row']->status,
                    'votes'      => $e['row']->votes,
                    'created_at' => $e['row']->createdAt->format('Y-m-d H:i:s'),
                    'tags'       => $e['tags'],
                ],
                array_slice($rows, 0, self::PREVIEW_ROWS),
            ),
            'import_id'       => null,
        ];

        if ($dryRun || $idempotencyKey === null) {
            return $report;
        }

        $sha      = hash('sha256', $content);
        $existing = $this->importRepo->findByKey($boardId, $idempotencyKey);
        if ($existing !== null) {
            return $this->replayOrConflict($report, $existing, $adapter->format, $sha);
        }

        $importId = $this->conn->transactional(function () use ($boardId, $actorUserId, $idempotencyKey, $adapter, $sha, $report, $rows, $tagIds, $newTags): ?int {
            $importId = $this->importRepo->create($boardId, $actorUserId, $idempotencyKey, $adapter->format, $sha, $report['importable'], $report['skipped']);
            if ($importId === null) {
                return null;
            }

            foreach ($newTags as $lower => $name) {
                $tagId = $this->tagRepo->create($boardId, $name, self::NEW_TAG_COLOR)
                    ?? $this->existingTagId($boardId, $name);
                if ($tagId !== null) {
                    $tagIds[$lower] = $tagId;
                }
            }

            foreach ($rows as $entry) {
                $row    = $entry['row'];
                $ideaId = $this->ideaRepo->createImported(
                    $boardId,
                    $actorUserId,
                    $importId,
                    $row->title,
                    $entry['normalized'],
                    $row->body,
                    $row->status,
                    $row->createdAt,
                    $row->votes,
                );
                foreach ($entry['tags'] as $name) {
                    $tagId = $tagIds[mb_strtolower($name, 'UTF-8')] ?? null;
                    if ($tagId !== null) {
                        $this->tagRepo->assignToIdea($ideaId, $tagId);
                    }
                }
            }

            return $importId;
        });

        if ($importId === null) {
            // Lost a race against a concurrent request with the same key.
            $existing = $this->importRepo->findByKey($boardId, $idempotencyKey);

            return $existing === null
                ? [...$report, 'result' => self::RESULT_CONFLICT]
                : $this->replayOrConflict($report, $existing, $adapter->format, $sha);
        }

        return [...$report, 'import_id' => $importId];
    }

    /**
     * A new tag name hit UNIQUE(board_id, name) — the MySQL collation is
     * case- and accent-insensitive ("Café" = "cafe"), or a concurrent request
     * created it. Reuse the tag the database considers equal; if none can be
     * matched, the tag is simply not assigned (never fails the import).
     */
    private function existingTagId(int $boardId, string $name): ?int
    {
        $wanted = $this->normalizer->normalize($name);
        foreach ($this->tagRepo->listByBoard($boardId) as $tag) {
            if ($this->normalizer->normalize($tag['name']) === $wanted) {
                return $tag['id'];
            }
        }

        return null;
    }

    /**
     * @param array{result: string, total_rows: int, importable: int, skipped: int, new_tags: list<string>, ignored_columns: list<string>, issues: list<array{line: int, field: string, code: string, severity: 'error'|'warning'}>, preview: list<array{line: int, title: string, status: string, votes: int, created_at: string, tags: list<string>}>, import_id: int|null} $report
     * @param array{id: int, format: string, content_sha256: string, imported_count: int, skipped_count: int} $existing
     * @return array{result: string, total_rows: int, importable: int, skipped: int, new_tags: list<string>, ignored_columns: list<string>, issues: list<array{line: int, field: string, code: string, severity: 'error'|'warning'}>, preview: list<array{line: int, title: string, status: string, votes: int, created_at: string, tags: list<string>}>, import_id: int|null}
     */
    private function replayOrConflict(array $report, array $existing, string $format, string $sha): array
    {
        if (!hash_equals($existing['content_sha256'], $sha) || $existing['format'] !== $format) {
            return [...$report, 'result' => self::RESULT_CONFLICT];
        }

        return [
            ...$report,
            'result'     => self::RESULT_REPLAYED,
            'importable' => $existing['imported_count'],
            'skipped'    => $existing['skipped_count'],
            'new_tags'   => [],
            'issues'     => [],
            'preview'    => [],
            'import_id'  => $existing['id'],
        ];
    }
}
