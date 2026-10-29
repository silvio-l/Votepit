<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Domain\UrlInText;

/**
 * URL detection for guest submissions (ADR 0006 §6): links are the main
 * spam/phishing vector on the shared origin, so a guest idea may not
 * contain a scheme URL or a www. host — including obfuscated variants.
 * Bare domains stay allowed (too many false positives, never autolinked).
 */
final class UrlInTextTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function urls(): array
    {
        return [
            'https'              => ['see https://example.com now'],
            'http'               => ['http://evil.test'],
            'upper case'         => ['HTTPS://EXAMPLE.COM'],
            'hxxp obfuscation'   => ['hxxp://evil.test/login'],
            'hxxps obfuscation'  => ['visit hxxps://evil.test'],
            'spaces around colon' => ['http : // evil.test'],
            'www'                => ['go to www.example.com'],
            'www with space'     => ['go to www .example.com'],
            'full-width'         => ['ｈｔｔｐｓ://example.com'],
            'full-width www'     => ['ｗｗｗ．example.com'],
            'zero-width inside'  => ["ht\u{200B}tps://example.com"],
            'soft hyphen inside' => ["w\u{00AD}ww.example.com"],
        ];
    }

    #[DataProvider('urls')]
    public function test_url_variants_are_detected(string $text): void
    {
        self::assertTrue(UrlInText::containsUrl($text));
    }

    /** @return array<string, array{string}> */
    public static function plainTexts(): array
    {
        return [
            'bare domain'      => ['we use example.com for this'],
            'node.js'          => ['Please support Node.js 22'],
            'abbreviation'     => ['z.B. mehr Bänke am Platz'], // export-ok: comment-language
            'mailto is inert'  => ['write to mailto:someone'],
            'plain sentence'   => ['A longer lunch break would be great'],
            'http as a word'   => ['the http protocol is old'],
            'www as a word'    => ['the www is big'],
        ];
    }

    #[DataProvider('plainTexts')]
    public function test_plain_text_is_allowed(string $text): void
    {
        self::assertFalse(UrlInText::containsUrl($text));
    }

    public function test_long_input_is_handled_in_linear_time(): void
    {
        $start = microtime(true);
        self::assertFalse(UrlInText::containsUrl(str_repeat('h  : ', 20_000)));
        self::assertLessThan(1.0, microtime(true) - $start);
    }
}
