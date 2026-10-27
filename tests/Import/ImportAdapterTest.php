<?php

declare(strict_types=1);

namespace Votepit\Tests\Import;

use PHPUnit\Framework\TestCase;
use Votepit\Import\ImportAdapter;
use Votepit\Import\ImportFileException;
use Votepit\Import\ImportParseResult;

final class ImportAdapterTest extends TestCase
{
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-01 12:00:00');
    }

    private function parse(string $format, string $csv): ImportParseResult
    {
        $adapter = ImportAdapter::forFormat($format);
        self::assertNotNull($adapter);

        return $adapter->parse($csv, $this->now());
    }

    /**
     * @param list<array{line: int, field: string, code: string, severity: string}> $issues
     * @return list<string>
     */
    private function codes(array $issues): array
    {
        return array_map(static fn (array $i): string => $i['line'] . ':' . $i['code'] . ':' . $i['severity'], $issues);
    }

    public function test_unknown_format_has_no_adapter(): void
    {
        self::assertNull(ImportAdapter::forFormat('uservoice'));
        self::assertNull(ImportAdapter::forFormat(''));
    }

    public function test_votepit_csv_maps_every_template_column(): void
    {
        $result = $this->parse('csv', "title,body,status,created_at,votes,tags\n"
            . "Dark mode,Please add it,in progress,2024-03-05 10:20:30,42,\"UI, Theme\"\n");

        self::assertSame([], $result->issues);
        $row = $result->rows[0];
        self::assertSame('Dark mode', $row->title);
        self::assertSame('Please add it', $row->body);
        self::assertSame('in_progress', $row->status);
        self::assertSame('2024-03-05 10:20:30', $row->createdAt->format('Y-m-d H:i:s'));
        self::assertSame(42, $row->votes);
        self::assertSame(['UI', 'Theme'], $row->tags);
    }

    public function test_missing_title_column_rejects_the_file(): void
    {
        $this->expectException(ImportFileException::class);
        $this->parse('csv', "name,votes\nA,1\n");
    }

    public function test_canny_export_maps_its_columns_and_drops_author_data(): void
    {
        $result = $this->parse('canny', "Title,Details,Board,Status,Score,Tags,Author Name,Author Email,Created\n"
            . "Slack integration,Notify us,Feedback,complete,17,Integrations,Jane Doe,jane@example.com,2023-11-02T08:15:00.000Z\n");

        self::assertSame([], $result->issues);
        self::assertSame(['board', 'author name', 'author email'], $result->ignoredColumns);
        $row = $result->rows[0];
        self::assertSame('Slack integration', $row->title);
        self::assertSame('Notify us', $row->body);
        self::assertSame('done', $row->status);
        self::assertSame(17, $row->votes);
        self::assertSame(['Integrations'], $row->tags);
        self::assertSame('2023-11-02', $row->createdAt->format('Y-m-d'));

        // No trace of the author's identity anywhere in the import model.
        self::assertStringNotContainsString('jane', strtolower(serialize($result->rows)));
    }

    public function test_featurebase_html_content_becomes_plaintext(): void
    {
        $html = '<h2>Why</h2><p>We need <strong>SSO</strong> &amp; SCIM.<br>Soon!</p>'
            . '<ul><li>Okta</li><li>Azure</li></ul><script>alert(1)</script><img src=x onerror=alert(2)>';
        $csv = "Title,Content,Status,Upvotes,Tags,Created at,Author Email\n"
            . 'SSO,"' . str_replace('"', '""', $html) . "\",Rejected,8,Security,2024-01-15,bob@example.com\n";

        $row = $this->parse('featurebase', $csv)->rows[0];

        self::assertSame("Why\nWe need SSO & SCIM.\nSoon!\n\n- Okta\n- Azure", $row->body);
        self::assertStringNotContainsString('<', $row->body);
        self::assertStringNotContainsString('alert', $row->body);
        self::assertSame('declined', $row->status);
        self::assertSame(8, $row->votes);
    }

    public function test_plain_csv_body_is_kept_verbatim_as_text(): void
    {
        // Not HTML-interpreted — the SPA escapes it like any user-submitted body.
        $row = $this->parse('csv', "title,body\n\"=HYPERLINK(\"\"x\"\")\",<b>bold</b>\n")->rows[0];

        self::assertSame('=HYPERLINK("x")', $row->title);
        self::assertSame('<b>bold</b>', $row->body);
    }

    public function test_row_errors_skip_the_row_and_warnings_fall_back(): void
    {
        $longTitle = str_repeat('t', ImportAdapter::TITLE_MAX + 1);
        $result    = $this->parse('csv', "title,status,created_at,votes,tags\n"
            . ",open,,1,\n"                                 // 2: title missing → error
            . "{$longTitle},open,,1,\n"                     // 3: title too long → error
            . "Weird,someday,not a date,-3,\n"              // 4: three warnings
            . "Future,open,2099-01-01,1,\n"                 // 5: future date → warning
            . "Tags,open,,\"1,234\"," . str_repeat('x', 41) . ";ok;OK\n"); // 6: long tag dropped, dup removed

        self::assertSame(['Weird', 'Future', 'Tags'], array_map(static fn (\Votepit\Import\ImportRow $r): string => $r->title, $result->rows));
        self::assertSame([
            '2:title_missing:error',
            '3:title_too_long:error',
            '4:status_unknown:warning',
            '4:created_at_invalid:warning',
            '4:votes_invalid:warning',
            '5:created_at_invalid:warning',
            '6:tag_too_long:warning',
        ], $this->codes($result->issues));

        self::assertSame('open', $result->rows[0]->status);
        self::assertSame(0, $result->rows[0]->votes);
        self::assertEquals($this->now(), $result->rows[0]->createdAt);
        self::assertSame(1234, $result->rows[2]->votes);
        self::assertSame(['ok'], $result->rows[2]->tags);
        self::assertSame(5, $result->totalRows);
    }

    public function test_column_count_mismatch_is_a_row_error(): void
    {
        $result = $this->parse('csv', "title,votes\nA\nB,2\n");

        self::assertSame(['2:column_count_mismatch:error'], $this->codes($result->issues));
        self::assertCount(1, $result->rows);
    }

    public function test_control_characters_are_stripped_and_title_whitespace_collapsed(): void
    {
        $row = $this->parse('csv', "title,body\n\"Multi\nline\x07 title\",\"keep\nnewlines\x00\"\n")->rows[0];

        self::assertSame('Multi line title', $row->title);
        self::assertSame("keep\nnewlines", $row->body);
    }

    public function test_tags_per_row_are_capped(): void
    {
        $tags   = implode(',', array_map(static fn (int $i): string => "t{$i}", range(1, ImportAdapter::TAGS_PER_ROW + 2)));
        $result = $this->parse('csv', "title,tags\nA,\"{$tags}\"\n");

        self::assertCount(ImportAdapter::TAGS_PER_ROW, $result->rows[0]->tags);
        self::assertSame(['2:too_many_tags:warning'], $this->codes($result->issues));
    }

    public function test_dates_in_common_export_formats_are_accepted(): void
    {
        $result = $this->parse('canny', "title,created\nA,2024-03-05\nB,05.03.2024\nC,03/05/2024\nD,2024-03-05T10:00:00+02:00\n");

        self::assertSame([], $result->issues);
        self::assertSame(
            ['2024-03-05', '2024-03-05', '2024-03-05', '2024-03-05'],
            array_map(static fn (\Votepit\Import\ImportRow $r): string => $r->createdAt->format('Y-m-d'), $result->rows),
        );
    }
}
