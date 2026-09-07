<?php

declare(strict_types=1);

namespace Votepit\Migrations;

use Doctrine\DBAL\Connection;

/**
 * Wraps a .sql migration file as a Migration.
 *
 * version() is the filename without extension (e.g. "0000_baseline" for
 * migrations/0000_baseline.sql).
 *
 * Statement split: this project's convention is that a migration file
 * contains a few related DDL statements, separated by ";\n" (a semicolon
 * directly followed by a newline). Deliberate, documented limitation:
 * string literals with an embedded ";\n" are not supported — not needed
 * for DDL migrations (CREATE/ALTER/DROP TABLE).
 */
final readonly class SqlFileMigration implements Migration
{
    private string $version;

    public function __construct(private string $path)
    {
        $this->version = pathinfo($path, PATHINFO_FILENAME);
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * MySQL DDL auto-commits per statement (implicit commit before/after
     * CREATE/ALTER/DROP TABLE), so a surrounding transaction cannot roll a
     * partially-applied multi-statement migration back — a statement
     * failing partway through this file leaves the earlier statements
     * already committed to the schema, with this migration still untracked
     * (review-2026-09-04-fixes item 5). Rather than pretend otherwise, a
     * failure here reports exactly which statement failed and how many
     * preceding statements in this same file already landed, so an operator
     * knows the actual schema state instead of having to diff it by hand —
     * and knows NOT to just blindly re-run the migration (it would replay
     * the already-applied statements too).
     */
    public function up(Connection $conn): void
    {
        $statements = $this->statements();
        $total      = count($statements);

        foreach ($statements as $i => $statement) {
            try {
                $conn->executeStatement($statement);
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf(
                    "statement %d of %d failed (%d statement(s) before it were already applied " .
                    "to the schema and cannot be rolled back — do not simply re-run this " .
                    "migration, inspect the schema first): %s\nFailing statement: %s",
                    $i + 1,
                    $total,
                    $i,
                    $e->getMessage(),
                    $statement,
                ), 0, $e);
            }
        }
    }

    /** @return list<string> */
    private function statements(): array
    {
        $parts = explode(";\n", $this->contents());

        return array_values(array_filter(
            array_map(trim(...), $parts),
            static fn (string $s): bool => $s !== '',
        ));
    }

    private function contents(): string
    {
        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new \RuntimeException("Migration: file not readable: {$this->path}");
        }

        return $contents;
    }
}
