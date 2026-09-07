<?php

declare(strict_types=1);

namespace Votepit\Tests\Logging;

use PHPUnit\Framework\TestCase;
use Votepit\Logging\AuditLogger;

/**
 * AuditLogger::mask() defense-in-depth (deep-review-2026-09 finding n):
 * masking must not depend solely on an exact key-name match, since a future
 * PII field with a differently-named key would otherwise leak in plaintext.
 */
final class AuditLoggerTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = tempnam(sys_get_temp_dir(), 'audit-log-test-');
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
    }

    /** @param array<string, mixed> $context */
    private function logAndDecode(string $action, array $context): mixed
    {
        (new AuditLogger($this->logPath))->log($action, $context);
        $lines = array_filter(explode("\n", trim((string) file_get_contents($this->logPath))), fn (string $l): bool => $l !== '');
        $line  = (string) end($lines);
        $pos   = strpos($line, '{');
        $json  = substr($line, $pos !== false ? $pos : 0);
        return json_decode($json, true);
    }

    public function test_exact_email_key_is_masked(): void
    {
        $decoded = $this->logAndDecode('test.event', ['email' => 'alice@example.com']);
        self::assertMatchesRegularExpression('/^a\*\*@e\*\*#[0-9a-f]{12}$/', $decoded['email']);
    }

    public function test_differently_named_key_is_still_masked_by_substring(): void
    {
        $decoded = $this->logAndDecode('test.event', [
            'user_email'         => 'bob@example.com',
            'notification_email' => 'carol@example.com',
            'csrf_token'         => 'abc123',
        ]);

        self::assertMatchesRegularExpression('/^b\*\*@e\*\*#[0-9a-f]{12}$/', $decoded['user_email']);
        self::assertMatchesRegularExpression('/^c\*\*@e\*\*#[0-9a-f]{12}$/', $decoded['notification_email']);
        self::assertSame('***', $decoded['csrf_token']);
    }

    public function test_email_shaped_value_is_masked_even_under_an_unrelated_key_name(): void
    {
        // The real safety net: no key-name heuristic can enumerate every
        // possible future field name, so any value that looks like an email
        // is masked regardless of what its key is called.
        $decoded = $this->logAndDecode('test.event', ['reporter_contact' => 'dave@example.com']);
        self::assertMatchesRegularExpression('/^d\*\*@e\*\*#[0-9a-f]{12}$/', $decoded['reporter_contact']);
    }

    public function test_non_pii_fields_pass_through_unmasked(): void
    {
        $decoded = $this->logAndDecode('test.event', ['board_slug' => 'my-board', 'idea_id' => 42]);
        self::assertSame('my-board', $decoded['board_slug']);
        self::assertSame(42, $decoded['idea_id']);
    }

    public function test_masking_recurses_into_nested_arrays(): void
    {
        $decoded = $this->logAndDecode('test.event', [
            'payload' => ['email' => 'erin@example.com', 'ok' => true],
        ]);
        self::assertMatchesRegularExpression('/^e\*\*@e\*\*#[0-9a-f]{12}$/', $decoded['payload']['email']);
        self::assertTrue($decoded['payload']['ok']);
    }

    public function test_same_email_masks_to_the_same_hash_suffix_for_correlation(): void
    {
        $first  = $this->logAndDecode('test.event', ['email' => 'frank@example.com']);
        $second = $this->logAndDecode('test.event', ['email' => 'frank@example.com']);
        self::assertSame($first['email'], $second['email']);
    }
}
