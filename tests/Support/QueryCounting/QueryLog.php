<?php

declare(strict_types=1);

namespace Votepit\Tests\Support\QueryCounting;

/**
 * Test-only sink for QueryCountingMiddleware: records every SQL statement
 * the driver connection actually executes (query/exec/prepared execute).
 *
 * Mutable and shared by reference between the middleware layers and the
 * test, so a test can reset() right before the request it wants to measure.
 */
final class QueryLog
{
    /** @var list<string> */
    private array $statements = [];

    public function record(string $sql): void
    {
        $this->statements[] = $sql;
    }

    public function reset(): void
    {
        $this->statements = [];
    }

    public function count(): int
    {
        return count($this->statements);
    }

    /** @return list<string> */
    public function statements(): array
    {
        return $this->statements;
    }

    /** Numbered, whitespace-collapsed listing for assertion failure messages. */
    public function dump(): string
    {
        $lines = [];
        foreach ($this->statements as $i => $sql) {
            $lines[] = sprintf('%3d. %s', $i + 1, (string) preg_replace('/\s+/', ' ', trim($sql)));
        }

        return implode("\n", $lines);
    }
}
