<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\BoardProBrandingValidator;

final class BoardProBrandingValidatorTest extends TestCase
{
    public function test_enum_presets_accept_only_the_allow_list(): void
    {
        self::assertSame('default', BoardProBrandingValidator::typography(null));
        self::assertSame('default', BoardProBrandingValidator::typography(''));
        self::assertSame('mono', BoardProBrandingValidator::typography(' MONO '));
        self::assertSame('square', BoardProBrandingValidator::shape('square'));
        self::assertSame('compact', BoardProBrandingValidator::density('compact'));
        self::assertNull(BoardProBrandingValidator::typography('url(https://evil.example/x.woff2)'));
        self::assertNull(BoardProBrandingValidator::shape('round; background:red'));
        self::assertNull(BoardProBrandingValidator::density(['compact']));
        self::assertNull(BoardProBrandingValidator::density(true));
    }

    public function test_wording_is_cleaned_per_locale_and_empty_texts_drop(): void
    {
        $clean = BoardProBrandingValidator::wording([
            'de' => ['idea_singular' => ' Vorschlag ', 'vote_for' => '   '],
            'en' => ['idea_singular' => 'Suggestion', 'thank_you' => 'Thanks!'],
        ]);

        self::assertSame(
            ['de' => ['idea_singular' => 'Vorschlag'], 'en' => ['idea_singular' => 'Suggestion', 'thank_you' => 'Thanks!']],
            $clean,
        );
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejectedWording(): iterable
    {
        yield 'markup' => [['en' => ['thank_you' => '<img src=x onerror=alert(1)>']]];
        yield 'braces reserved for i18n' => [['en' => ['idea_singular' => '{count}']]];
        yield 'control character' => [['en' => ['vote_for' => "Yes\nNo"]]];
        yield 'unknown locale' => [['xx' => ['vote_for' => 'Support']]];
        yield 'unknown field' => [['en' => ['admin_note' => 'x']]];
        yield 'too long' => [['en' => ['vote_for' => str_repeat('a', 25)]]];
        yield 'non-string text' => [['en' => ['vote_for' => ['Support']]]];
        yield 'not an object' => [['en']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedWording')]
    public function test_wording_rejects_unsafe_or_unknown_input(mixed $raw): void
    {
        self::assertNull(BoardProBrandingValidator::wording($raw));
    }

    public function test_decode_wording_treats_unreadable_json_as_no_wording(): void
    {
        self::assertSame([], BoardProBrandingValidator::decodeWording('not json'));
        self::assertSame([], BoardProBrandingValidator::decodeWording('{"en":{"thank_you":"<b>x</b>"}}'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejectedLinks(): iterable
    {
        yield 'javascript' => [[['url' => 'javascript:alert(1)', 'label' => 'x']]];
        yield 'data' => [[['url' => 'data:text/html,hi', 'label' => 'x']]];
        yield 'http' => [[['url' => 'http://example.com', 'label' => 'x']]];
        yield 'protocol relative' => [[['url' => '//example.com', 'label' => 'x']]];
        yield 'explicit port' => [[['url' => 'https://example.com:8443/', 'label' => 'x']]];
        yield 'userinfo' => [[['url' => 'https://user@example.com/', 'label' => 'x']]];
        yield 'half filled' => [[['url' => 'https://example.com/', 'label' => '']]];
        yield 'label too long' => [[['url' => 'https://example.com/', 'label' => str_repeat('a', 31)]]];
        yield 'label with markup' => [[['url' => 'https://example.com/', 'label' => '<a>x</a>']]];
        yield 'six links' => [array_fill(0, 6, ['url' => 'https://example.com/', 'label' => 'x'])];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedLinks')]
    public function test_header_links_reject_unsafe_entries(mixed $raw): void
    {
        self::assertNull(BoardProBrandingValidator::headerLinks($raw));
    }

    public function test_header_links_accept_https_and_skip_blank_rows(): void
    {
        $clean = BoardProBrandingValidator::headerLinks([
            ['url' => 'https://example.com/about', 'label' => 'About us'],
            ['url' => '', 'label' => ''],
        ]);

        self::assertSame([['url' => 'https://example.com/about', 'label' => 'About us']], $clean);
    }
}
