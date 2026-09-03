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

    public function up(Connection $conn): void
    {
        foreach ($this->statements() as $statement) {
            $conn->executeStatement($statement);
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
