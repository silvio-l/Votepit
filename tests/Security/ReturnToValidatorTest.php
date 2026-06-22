<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\ReturnToValidator;

/**
 * Unit-Tests für ReturnToValidator::isValid (Issue 05 — Open-Redirect-Schutz).
 *
 * Jede Regel der Validierung wird durch mindestens einen positiven und
 * einen negativen Fall fixiert. Encoded-Bypass-Varianten sichern ab, dass
 * rawurldecode-Normalisierung vor dem Check angewendet wird.
 */
final class ReturnToValidatorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Gültige Pfade
    // -------------------------------------------------------------------------

    public function test_single_slash_is_valid(): void
    {
        self::assertTrue(ReturnToValidator::isValid('/'));
    }

    public function test_simple_path_is_valid(): void
    {
        self::assertTrue(ReturnToValidator::isValid('/some/path'));
    }

    public function test_path_with_query_and_fragment_is_valid(): void
    {
        self::assertTrue(ReturnToValidator::isValid('/path?x=1#frag'));
    }

    public function test_board_path_is_valid(): void
    {
        self::assertTrue(ReturnToValidator::isValid('/some/board/path'));
    }

    public function test_path_with_encoded_space_is_valid(): void
    {
        self::assertTrue(ReturnToValidator::isValid('/my%20board'));
    }

    // -------------------------------------------------------------------------
    // Leerer String → abgewiesen
    // -------------------------------------------------------------------------

    public function test_empty_string_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid(''));
    }

    // -------------------------------------------------------------------------
    // Protokoll-relative URL → abgewiesen
    // -------------------------------------------------------------------------

    public function test_protocol_relative_url_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid('//evil.com'));
    }

    public function test_double_slash_only_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid('//'));
    }

    // -------------------------------------------------------------------------
    // Schema in URL → abgewiesen
    // -------------------------------------------------------------------------

    public function test_https_absolute_url_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid('https://evil.com'));
    }

    public function test_http_absolute_url_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid('http://evil.com'));
    }

    public function test_javascript_scheme_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid('javascript:alert(1)'));
    }

    public function test_data_scheme_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid('data:text/html,<h1>'));
    }

    // -------------------------------------------------------------------------
    // Kein führender Schrägstrich → abgewiesen
    // -------------------------------------------------------------------------

    public function test_bare_host_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid('evil.com/path'));
    }

    public function test_relative_path_without_slash_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid('path/to/resource'));
    }

    // -------------------------------------------------------------------------
    // Backslash → abgewiesen (/\evil.com Browser-Trick)
    // -------------------------------------------------------------------------

    public function test_backslash_after_leading_slash_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid('/\\evil.com'));
    }

    // -------------------------------------------------------------------------
    // Encoded Bypasses → abgewiesen
    // -------------------------------------------------------------------------

    public function test_encoded_double_slash_bypass_is_invalid(): void
    {
        // /%2Fevil.com decodes to //evil.com → muss abgewiesen werden.
        self::assertFalse(ReturnToValidator::isValid('/%2Fevil.com'));
    }

    public function test_encoded_backslash_bypass_is_invalid(): void
    {
        // /%5Cevil.com decodes to /\evil.com → muss abgewiesen werden.
        self::assertFalse(ReturnToValidator::isValid('/%5Cevil.com'));
    }
}
