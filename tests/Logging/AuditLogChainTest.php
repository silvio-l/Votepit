<?php

declare(strict_types=1);

namespace Votepit\Tests\Logging;

use PHPUnit\Framework\TestCase;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuditLogVerifier;

/**
 * OWASP/BSI audit remediation issue 11: NDJSON format + hash-chain
 * tamper detection.
 */
final class AuditLogChainTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = tempnam(sys_get_temp_dir(), 'audit-log-chain-test-');
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
    }

    public function test_each_line_is_independently_parseable_ndjson(): void
    {
        $audit = new AuditLogger($this->logPath);
        $audit->log('event.one', ['n' => 1]);
        $audit->log('event.two', ['n' => 2]);
        $audit->log('event.three', ['n' => 3]);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->logPath)), fn (string $l): bool => $l !== ''));
        self::assertCount(3, $lines);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertArrayHasKey('ts', $decoded);
            self::assertArrayHasKey('action', $decoded);
            self::assertArrayHasKey('context', $decoded);
            self::assertArrayHasKey('prev', $decoded);
            self::assertArrayHasKey('hash', $decoded);
        }
    }

    public function test_untampered_log_verifies_clean(): void
    {
        $audit = new AuditLogger($this->logPath);
        $audit->log('event.one', ['n' => 1]);
        $audit->log('event.two', ['n' => 2]);
        $audit->log('event.three', ['n' => 3]);

        self::assertNull(AuditLogVerifier::firstBrokenLine($this->logPath));
    }

    public function test_chain_links_consecutive_lines_via_prev_and_hash(): void
    {
        $audit = new AuditLogger($this->logPath);
        $audit->log('event.one', []);
        $audit->log('event.two', []);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->logPath)), fn (string $l): bool => $l !== ''));
        $first  = json_decode($lines[0], true);
        $second = json_decode($lines[1], true);

        self::assertSame(AuditLogger::genesisHash(), $first['prev']);
        self::assertSame($first['hash'], $second['prev']);
        self::assertNotSame($first['hash'], $second['hash']);
    }

    public function test_editing_a_historical_line_is_detected(): void
    {
        $audit = new AuditLogger($this->logPath);
        $audit->log('event.one', ['n' => 1]);
        $audit->log('event.two', ['n' => 2]);
        $audit->log('event.three', ['n' => 3]);

        $lines = explode("\n", trim((string) file_get_contents($this->logPath)));
        $tampered = json_decode($lines[0], true);
        $tampered['context']['n'] = 999; // attacker edits the first line's payload
        $lines[0] = (string) json_encode($tampered);
        file_put_contents($this->logPath, implode("\n", $lines) . "\n");

        self::assertSame(1, AuditLogVerifier::firstBrokenLine($this->logPath));
    }

    public function test_deleting_a_historical_line_is_detected(): void
    {
        $audit = new AuditLogger($this->logPath);
        $audit->log('event.one', []);
        $audit->log('event.two', []);
        $audit->log('event.three', []);

        $lines = explode("\n", trim((string) file_get_contents($this->logPath)));
        unset($lines[1]); // attacker deletes the middle line
        file_put_contents($this->logPath, implode("\n", $lines) . "\n");

        self::assertSame(2, AuditLogVerifier::firstBrokenLine($this->logPath));
    }

    public function test_empty_or_missing_log_verifies_clean(): void
    {
        self::assertNull(AuditLogVerifier::firstBrokenLine($this->logPath));
        self::assertNull(AuditLogVerifier::firstBrokenLine($this->logPath . '-does-not-exist'));
    }
}
