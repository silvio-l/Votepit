<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Votepit\Domain\BoardResultsCsv;

/**
 * Board results export (broaden-use-cases issue 07): pure CSV rendering —
 * BOM, separator, RFC 4180 quoting, consensus and formula-injection defense.
 */
final class BoardResultsCsvTest extends TestCase
{
    public function test_empty_export_is_bom_plus_header_only(): void
    {
        $csv = BoardResultsCsv::build([], [], ',');

        self::assertSame(
            "\xEF\xBB\xBF" . 'id,title,body,status_key,status_label,status_category,up,down,score,consensus_percent,comments,tags,created_at,target_date' . "\r\n",
            $csv,
        );
    }

    public function test_formula_prefixes_are_neutralised_in_every_text_cell(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"] as $evil) {
            $csv = BoardResultsCsv::build([$this->idea(['title' => $evil, 'body' => $evil, 'status_label' => $evil])], [7 => [['name' => $evil]]], ',');
            $row = $this->firstRow($csv);

            self::assertSame("'" . $evil, $row[1], 'title: ' . json_encode($evil));
            self::assertSame("'" . $evil, $row[2], 'body');
            self::assertSame("'" . $evil, $row[4], 'status_label');
            self::assertSame("'" . $evil, $row[11], 'tags');
        }
    }

    public function test_numeric_cells_stay_numeric_even_when_negative(): void
    {
        $row = $this->firstRow(BoardResultsCsv::build([$this->idea(['up_count' => 1, 'down_count' => 4, 'score_cache' => -3])], [], ','));

        self::assertSame(['1', '4', '-3', '20'], [$row[6], $row[7], $row[8], $row[9]]);
    }

    public function test_consensus_is_empty_without_votes_and_rounded_otherwise(): void
    {
        self::assertSame('', $this->firstRow(BoardResultsCsv::build([$this->idea()], [], ','))[9]);
        self::assertSame('67', $this->firstRow(BoardResultsCsv::build([$this->idea(['up_count' => 2, 'down_count' => 1, 'score_cache' => 1])], [], ','))[9]);
    }

    public function test_consensus_never_shows_100_with_down_votes_or_0_with_up_votes(): void
    {
        self::assertSame('99', $this->firstRow(BoardResultsCsv::build([$this->idea(['up_count' => 199, 'down_count' => 1, 'score_cache' => 198])], [], ','))[9]);
        self::assertSame('100', $this->firstRow(BoardResultsCsv::build([$this->idea(['up_count' => 1, 'down_count' => 0, 'score_cache' => 1])], [], ','))[9]);
        self::assertSame('0', $this->firstRow(BoardResultsCsv::build([$this->idea(['up_count' => 0, 'down_count' => 5, 'score_cache' => -5])], [], ','))[9]);
        self::assertSame('1', $this->firstRow(BoardResultsCsv::build([$this->idea(['up_count' => 1, 'down_count' => 300, 'score_cache' => -299])], [], ','))[9]);
    }

    public function test_semicolon_separator_and_quoting_round_trip(): void
    {
        $title = 'A; "quoted", multi' . "\n" . 'line';
        $csv   = BoardResultsCsv::build([$this->idea(['title' => $title])], [7 => [['name' => 'B'], ['name' => 'A']]], ';');
        $row   = $this->firstRow($csv, ';');

        self::assertSame($title, $row[1]);
        self::assertSame('B, A', $row[11]);
        self::assertStringContainsString('id;title;body;', $csv);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function idea(array $overrides = []): array
    {
        return array_merge([
            'id'              => 7,
            'title'           => 'T',
            'body'            => 'B',
            'status_key'      => 'open',
            'status_label'    => 'Open',
            'status_category' => 'inbox',
            'up_count'        => 0,
            'down_count'      => 0,
            'score_cache'     => 0,
            'comment_count'   => 0,
            'created_at'      => '2026-09-01 10:00:00',
            'target_date'     => null,
        ], $overrides);
    }

    /** @return list<string> */
    private function firstRow(string $csv, string $separator = ','): array
    {
        $stream = fopen('php://temp', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        fgetcsv($stream, null, $separator, '"', '');
        $row = fgetcsv($stream, null, $separator, '"', '');
        fclose($stream);
        self::assertIsArray($row);

        /** @var list<string> $row */
        return $row;
    }
}
