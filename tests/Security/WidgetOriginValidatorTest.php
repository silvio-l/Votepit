<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Security\WidgetOriginValidator;

/**
 * The allowed-origin list is emitted verbatim into a CSP frame-ancestors
 * directive, so it must be exactly `https://host[:port]` — anything that
 * could widen or inject into the directive is rejected.
 */
final class WidgetOriginValidatorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validOrigins(): iterable
    {
        yield 'plain host'  => ['https://example.com'];
        yield 'subdomain'   => ['https://app.customer.example.co.uk'];
        yield 'with port'   => ['https://example.com:8443'];
        yield 'punycode'    => ['https://xn--mnchen-3ya.de'];
    }

    #[DataProvider('validOrigins')]
    public function test_valid_origins(string $origin): void
    {
        self::assertTrue(WidgetOriginValidator::isValid($origin));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOrigins(): iterable
    {
        yield 'http'                => ['http://example.com'];
        yield 'wildcard host'       => ['https://*.example.com'];
        yield 'bare wildcard'       => ['*'];
        yield 'scheme only'         => ['https:'];
        yield 'self keyword'        => ["'self'"];
        yield 'trailing slash'      => ['https://example.com/'];
        yield 'path'                => ['https://example.com/page'];
        yield 'query'               => ['https://example.com?x=1'];
        yield 'userinfo'            => ['https://user@example.com'];
        yield 'space injection'     => ['https://example.com https://evil.example'];
        yield 'semicolon injection' => ['https://example.com; script-src *'];
        yield 'single label'        => ['https://localhost'];
        yield 'ipv4'                => ['https://1.2.3.4'];
        yield 'port zero'           => ['https://example.com:0'];
        yield 'port too large'      => ['https://example.com:70000'];
        yield 'uppercase'           => ['https://Example.com'];
        yield 'idn unicode'         => ['https://münchen.de']; // export-ok: comment-language
        yield 'leading hyphen'      => ['https://-example.com'];
        yield 'newline'             => ["https://example.com\n"];
        yield 'data scheme'         => ['data:'];
    }

    #[DataProvider('invalidOrigins')]
    public function test_invalid_origins(string $origin): void
    {
        self::assertFalse(WidgetOriginValidator::isValid($origin));
    }

    public function test_normalize_trims_lowercases_dedupes_and_skips_blank_lines(): void
    {
        self::assertSame(
            ['https://example.com', 'https://shop.example.com'],
            WidgetOriginValidator::normalize(['  https://Example.com ', '', 'https://example.com', 'https://shop.example.com']),
        );
    }

    public function test_normalize_reports_the_first_invalid_entry(): void
    {
        self::assertSame('invalid:1', WidgetOriginValidator::normalize(['https://ok.example', 'http://bad.example']));
        self::assertSame('invalid:0', WidgetOriginValidator::normalize([42]));
    }

    public function test_normalize_caps_the_list(): void
    {
        $origins = array_map(static fn (int $i): string => "https://s{$i}.example.com", range(1, WidgetOriginValidator::MAX_ORIGINS + 1));

        self::assertSame('too_many', WidgetOriginValidator::normalize($origins));
        self::assertCount(WidgetOriginValidator::MAX_ORIGINS, (array) WidgetOriginValidator::normalize(array_slice($origins, 0, WidgetOriginValidator::MAX_ORIGINS)));
    }

    public function test_origin_of_extracts_the_origin_of_a_referer(): void
    {
        self::assertSame('https://shop.example.com', WidgetOriginValidator::originOf('https://Shop.Example.com/shop?x=1#a'));
        self::assertSame('https://shop.example.com:8443', WidgetOriginValidator::originOf('https://shop.example.com:8443/'));
        self::assertNull(WidgetOriginValidator::originOf('http://shop.example.com/'));
        self::assertNull(WidgetOriginValidator::originOf(''));
    }
}
