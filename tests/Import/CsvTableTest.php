<?php

declare(strict_types=1);

namespace Votepit\Tests\Import;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Import\CsvTable;
use Votepit\Import\ImportFileException;

final class CsvTableTest extends TestCase
{
    public function test_parses_header_and_rows_with_line_numbers(): void
    {
        $table = CsvTable::parse("Title,Votes\nDark mode,5\nExport,2\n");

        self::assertSame(['title', 'votes'], $table->header);
        self::assertSame([
            ['line' => 2, 'fields' => ['Dark mode', '5']],
            ['line' => 3, 'fields' => ['Export', '2']],
        ], $table->rows);
    }

    public function test_quoted_fields_may_contain_delimiters_quotes_and_newlines(): void
    {
        $table = CsvTable::parse("title,body\r\n\"A, B\",\"He said \"\"hi\"\"\nsecond line\"\r\nNext,x\r\n");

        self::assertSame(['A, B', "He said \"hi\"\nsecond line"], $table->rows[0]['fields']);
        // The multi-line record started on line 2; the next one is on line 4.
        self::assertSame(4, $table->rows[1]['line']);
    }

    public function test_strips_bom_and_detects_semicolon_delimiter_from_header(): void
    {
        $table = CsvTable::parse("\xEF\xBB\xBFtitle;votes\nIdee, mit Komma;3\n");

        self::assertSame(['title', 'votes'], $table->header);
        self::assertSame(['Idee, mit Komma', '3'], $table->rows[0]['fields']);
    }

    public function test_row_with_wrong_field_count_is_flagged_not_dropped(): void
    {
        $table = CsvTable::parse("title,votes\nonly-one\nok,1\n");

        self::assertNull($table->rows[0]['fields']);
        self::assertSame(['ok', '1'], $table->rows[1]['fields']);
    }

    public function test_skips_blank_lines(): void
    {
        self::assertCount(1, CsvTable::parse("title\n\nA\n\n")->rows);
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedFiles(): iterable
    {
        yield 'empty' => ['   ', ImportFileException::EMPTY];
        yield 'latin-1' => ["title\nCaf\xE9\n", ImportFileException::INVALID_ENCODING];
        yield 'unterminated quote' => ["title\n\"open\n", ImportFileException::MALFORMED];
        yield 'quote inside unquoted field' => ["title\nab\"c\n", ImportFileException::MALFORMED];
        yield 'text after closing quote' => ["title\n\"a\"b\n", ImportFileException::MALFORMED];
        yield 'too large' => [str_repeat('a', CsvTable::MAX_BYTES + 1), ImportFileException::TOO_LARGE];
        yield 'too many rows' => ["title\n" . str_repeat("x\n", CsvTable::MAX_ROWS + 1), ImportFileException::TOO_MANY_ROWS];
    }

    #[DataProvider('rejectedFiles')]
    public function test_rejects_unusable_files(string $content, string $reason): void
    {
        try {
            CsvTable::parse($content);
            self::fail('Expected ImportFileException');
        } catch (ImportFileException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function test_exactly_max_rows_is_accepted(): void
    {
        self::assertCount(CsvTable::MAX_ROWS, CsvTable::parse("title\n" . str_repeat("x\n", CsvTable::MAX_ROWS))->rows);
    }
}
