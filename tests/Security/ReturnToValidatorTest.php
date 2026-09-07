<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\ReturnToValidator;

/**
 * Unit tests for ReturnToValidator::isValid (open-redirect protection).
 *
 * Every validation rule is pinned down by at least one positive and one
 * negative case. Encoded-bypass variants ensure that rawurldecode
 * normalization and control-character filtering are applied before the check.
 */
final class ReturnToValidatorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Valid paths
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
    // Empty string → rejected
    // -------------------------------------------------------------------------

    public function test_empty_string_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid(''));
    }

    // -------------------------------------------------------------------------
    // Protocol-relative URL → rejected
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
    // Scheme in URL → rejected
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
    // No leading slash → rejected
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
    // Backslash → rejected (/\evil.com browser trick)
    // -------------------------------------------------------------------------

    public function test_backslash_after_leading_slash_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid('/\\evil.com'));
    }

    // -------------------------------------------------------------------------
    // Encoded bypasses → rejected
    // -------------------------------------------------------------------------

    public function test_encoded_double_slash_bypass_is_invalid(): void
    {
        // /%2Fevil.com decodes to //evil.com → must be rejected.
        self::assertFalse(ReturnToValidator::isValid('/%2Fevil.com'));
    }

    public function test_encoded_backslash_bypass_is_invalid(): void
    {
        // /%5Cevil.com decodes to /\evil.com → must be rejected.
        self::assertFalse(ReturnToValidator::isValid('/%5Cevil.com'));
    }

    // -------------------------------------------------------------------------
    // Security Hardening: Multi-level URL encoding bypasses → rejected
    // -------------------------------------------------------------------------

    public function test_double_encoded_double_slash_is_invalid(): void
    {
        // /%252f%252fevil.com decodes -> /%2f%2fevil.com -> //evil.com
        self::assertFalse(ReturnToValidator::isValid('/%252f%252fevil.com'));
    }

    public function test_double_encoded_backslash_is_invalid(): void
    {
        // /%255cevil.com decodes -> /%5cevil.com -> /\evil.com
        self::assertFalse(ReturnToValidator::isValid('/%255cevil.com'));
    }

    public function test_double_encoded_scheme_colon_is_invalid(): void
    {
        // /javascript%253aalert(1) decodes -> /javascript%3aalert(1) -> /javascript:alert(1)
        self::assertFalse(ReturnToValidator::isValid('/javascript%253aalert(1)'));
    }

    // -------------------------------------------------------------------------
    // Security Hardening: Control character / WHATWG tab/newline stripping → rejected
    // -------------------------------------------------------------------------

    public function test_tab_in_protocol_relative_is_invalid(): void
    {
        // /\t/evil.com -> WHATWG URL parser strips \t -> //evil.com
        self::assertFalse(ReturnToValidator::isValid("/\t/evil.com"));
    }

    public function test_encoded_tab_in_protocol_relative_is_invalid(): void
    {
        // /%09/evil.com -> decodes to /\t/evil.com
        self::assertFalse(ReturnToValidator::isValid('/%09/evil.com'));
    }

    public function test_newline_in_protocol_relative_is_invalid(): void
    {
        // /\n/evil.com -> WHATWG URL parser strips \n -> //evil.com
        self::assertFalse(ReturnToValidator::isValid("/\n/evil.com"));
    }

    public function test_encoded_newline_in_protocol_relative_is_invalid(): void
    {
        // /%0a/evil.com -> decodes to /\n/evil.com
        self::assertFalse(ReturnToValidator::isValid('/%0a/evil.com'));
    }

    public function test_carriage_return_in_protocol_relative_is_invalid(): void
    {
        // /\r/evil.com -> WHATWG URL parser strips \r -> //evil.com
        self::assertFalse(ReturnToValidator::isValid("/\r/evil.com"));
    }

    public function test_encoded_null_byte_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid('/%00/evil.com'));
    }

    // -------------------------------------------------------------------------
    // Security Hardening: Space-padded protocol-relative tricks → rejected
    // -------------------------------------------------------------------------

    public function test_space_padded_double_slash_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid('/ /evil.com'));
    }

    public function test_space_padded_slash_backslash_is_invalid(): void
    {
        self::assertFalse(ReturnToValidator::isValid('/ \\evil.com'));
    }
}
