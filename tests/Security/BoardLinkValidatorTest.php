<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Security\BoardLinkValidator;

/**
 * Back link (https only) and plaintext footer note (P-31 Lite part).
 */
final class BoardLinkValidatorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function rejectedUrls(): iterable
    {
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'data scheme' => ['data:text/html,<script>alert(1)</script>'];
        yield 'http not https' => ['http://example.com'];
        yield 'userinfo' => ['https://user:pw@example.com'];
        yield 'userinfo without password' => ['https://evil@example.com'];
        yield 'explicit port' => ['https://example.com:8443'];
        yield 'whitespace inside' => ['https://exa mple.com'];
        yield 'backslash' => ['https://example.com\\evil'];
        yield 'control char' => ["https://example.com/\x07"];
        yield 'unicode host' => ['https://bücher.de'];  // export-ok: comment-language
        yield 'single label host' => ['https://localhost'];
        yield 'ip literal host' => ['https://127.0.0.1'];
        yield 'empty host' => ['https:///path'];
        yield 'too long' => ['https://example.com/' . str_repeat('a', 2100)];
    }

    #[DataProvider('rejectedUrls')]
    public function test_rejected_back_links(string $url): void
    {
        self::assertNull(BoardLinkValidator::backLinkUrl($url));
    }

    public function test_https_back_link_is_accepted_and_trimmed(): void
    {
        self::assertSame('https://www.example.com/hilfe', BoardLinkValidator::backLinkUrl('  https://www.example.com/hilfe  '));
        self::assertSame('https://xn--bcher-kva.de', BoardLinkValidator::backLinkUrl('https://xn--bcher-kva.de'));
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedPlaintext(): iterable
    {
        yield 'angle brackets in label' => ['<b>Home</b>'];
        yield 'script tag in note' => ['Hi <script>alert(1)</script>'];
        yield 'newline' => ["line one\nline two"];
        yield 'nul byte' => ["a\0b"];
    }

    #[DataProvider('rejectedPlaintext')]
    public function test_markup_and_control_characters_are_rejected(string $text): void
    {
        self::assertNull(BoardLinkValidator::footerNote($text));
        self::assertNull(BoardLinkValidator::backLinkLabel($text));
    }

    public function test_label_length_is_counted_in_characters_not_bytes(): void
    {
        self::assertNotNull(BoardLinkValidator::backLinkLabel(str_repeat('ä', 40)));  // export-ok: comment-language
        self::assertNull(BoardLinkValidator::backLinkLabel(str_repeat('ä', 41)));  // export-ok: comment-language
        self::assertNull(BoardLinkValidator::backLinkLabel(''));
    }

    public function test_footer_note_allows_empty_and_is_capped_at_280(): void
    {
        self::assertSame('', BoardLinkValidator::footerNote(''));
        self::assertNotNull(BoardLinkValidator::footerNote(str_repeat('x', 280)));
        self::assertNull(BoardLinkValidator::footerNote(str_repeat('x', 281)));
    }

    public function test_footer_note_keeps_quotes_and_ampersands_as_plain_text(): void
    {
        // Escaping for HTML happens at render time; the stored plaintext keeps them.
        self::assertSame('Tom & "Jerry" \'s', BoardLinkValidator::footerNote('Tom & "Jerry" \'s'));
    }
}
