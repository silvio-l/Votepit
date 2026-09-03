<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Domain\CsvZipExporter;

/**
 * Unit tests for CsvZipExporter::build (2026-08-31 security review — CSV/formula
 * injection, CWE-1236). Idea titles/comment bodies are attacker-controlled (any
 * board voter) and end up as cell values in an export that the account owner
 * typically opens in Excel/Sheets — a cell starting with `=`/`+`/`-`/`@`/tab/CR
 * must NEVER be interpreted as a formula there.
 */
final class CsvZipExporterTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function formulaTriggeringValues(): array
    {
        return [
            'equals'      => ['=HYPERLINK("http://evil.example/","click")'],
            'plus'        => ['+1+1'],
            'minus'       => ['-2+3'],
            'at'          => ['@SUM(1,1)'],
            'tab-prefix'  => ["\t=cmd|'/c calc'!A1"],
            'cr-prefix'   => ["\r=1+1"],
            'dde'         => ['=cmd|\' /C calc\'!A0'],
        ];
    }

    #[DataProvider('formulaTriggeringValues')]
    public function test_formula_triggering_cell_values_are_neutralized(string $value): void
    {
        $zipBytes = CsvZipExporter::build([
            'exported_at' => '2026-08-31T00:00:00+00:00',
            'account'     => [],
            'ideas'       => [['title' => $value]],
        ]);

        $csv       = $this->extractCsv($zipBytes, 'ideas.csv');
        $dataLine  = explode("\n", trim($csv))[1] ?? '';
        $cellStart = ltrim($dataLine, '"');

        self::assertStringStartsWith("'", $cellStart, 'cell must be prefixed with a literal-string apostrophe');
        self::assertStringNotContainsString("\n" . rtrim($value, "\r"), "\n" . $csv, 'raw formula-triggering value must not appear unprefixed');
    }

    public function test_ordinary_values_are_left_untouched(): void
    {
        $zipBytes = CsvZipExporter::build([
            'exported_at' => '2026-08-31T00:00:00+00:00',
            'account'     => [],
            'ideas'       => [['title' => 'Dark mode for the dashboard']],
        ]);

        $csv = $this->extractCsv($zipBytes, 'ideas.csv');

        self::assertStringContainsString('Dark mode for the dashboard', $csv);
        self::assertStringNotContainsString("'Dark mode", $csv);
    }

    private function extractCsv(string $zipBytes, string $entry): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'votepit-export-test-');
        self::assertNotFalse($tmpPath);
        file_put_contents($tmpPath, $zipBytes);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmpPath) === true);
        $csv = (string) $zip->getFromName($entry);
        $zip->close();
        unlink($tmpPath);

        return $csv;
    }
}
