<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Security\SocialLinkValidator;

final class SocialLinkValidatorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // website() — bare domain only, no scheme, no path.
    // -------------------------------------------------------------------------

    public function test_valid_bare_domain_is_accepted(): void
    {
        self::assertSame('example.com', SocialLinkValidator::website('example.com'));
    }

    public function test_domain_is_lowercased(): void
    {
        self::assertSame('example.com', SocialLinkValidator::website('EXAMPLE.com'));
    }

    public function test_subdomain_is_accepted(): void
    {
        self::assertSame('sub.example.com', SocialLinkValidator::website('sub.example.com'));
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedWebsiteProvider(): array
    {
        return [
            'empty'                     => [''],
            'whitespace only'           => ['   '],
            'https scheme'              => ['https://example.com'],
            'http scheme'               => ['http://example.com'],
            'javascript scheme'         => ['javascript:alert(1)'],
            'data scheme'               => ['data:text/html,<script>alert(1)</script>'],
            'protocol-relative'         => ['//evil.example.com'],
            'query string'              => ['example.com?a=1'],
            'fragment'                  => ['example.com#frag'],
            'userinfo'                  => ['user:pass@example.com'],
            'path segment'              => ['example.com/portfolio'],
            'trailing slash'            => ['example.com/'],
            'ipv4 literal'              => ['1.2.3.4'],
            'ipv6 literal'              => ['::1'],
            'ipv6 bracketed'            => ['[::1]'],
            'single label, no tld'      => ['localhost'],
            'homoglyph (cyrillic a)'    => ["ex\u{0430}mple.com"],
            'punycode-looking non-tld'  => ['example.xn--'],
            'leading hyphen label'      => ['-example.com'],
            'trailing hyphen label'     => ['example-.com'],
            'consecutive hyphen label'  => ['ex--ample.com'],
            'uppercase-only reject via scheme' => ['HTTPS://example.com'],
            'numeric tld'               => ['example.123'],
            'embedded newline'          => ["example.com\nHost: evil"],
            'port suffix'               => ['example.com:8080'],
            'overlong label'            => [str_repeat('a', 64) . '.com'],
        ];
    }

    #[DataProvider('rejectedWebsiteProvider')]
    public function test_invalid_domain_is_rejected(string $value): void
    {
        self::assertNull(SocialLinkValidator::website($value));
    }

    public function test_overlong_domain_is_rejected(): void
    {
        // Each label kept <= 63 chars but the overall host exceeds 253.
        $labels = array_fill(0, 10, str_repeat('a', 30));
        $domain = implode('.', $labels) . '.com';
        self::assertGreaterThan(253, strlen($domain));
        self::assertNull(SocialLinkValidator::website($domain));
    }

    // -------------------------------------------------------------------------
    // xHandle() — bare handle, optional leading "@" stripped, max 15 chars.
    // -------------------------------------------------------------------------

    public function test_valid_x_handle_is_accepted(): void
    {
        self::assertSame('my_handle', SocialLinkValidator::xHandle('my_handle'));
    }

    public function test_x_handle_strips_one_leading_at(): void
    {
        self::assertSame('my_handle', SocialLinkValidator::xHandle('@my_handle'));
    }

    public function test_x_handle_at_15_chars_is_accepted(): void
    {
        $handle = str_repeat('a', 15);
        self::assertSame($handle, SocialLinkValidator::xHandle($handle));
    }

    public function test_x_handle_at_16_chars_is_rejected(): void
    {
        self::assertNull(SocialLinkValidator::xHandle(str_repeat('a', 16)));
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedXHandleProvider(): array
    {
        return [
            'empty'                => [''],
            'just an at sign'      => ['@'],
            'double at'            => ['@@handle'],
            'contains slash'       => ['han/dle'],
            'contains query'       => ['handle?x=1'],
            'contains hash'        => ['handle#x'],
            'contains space'       => ['han dle'],
            'contains hyphen'      => ['han-dle'],
            'contains dot'         => ['han.dle'],
            'whitespace only'      => ['   '],
        ];
    }

    #[DataProvider('rejectedXHandleProvider')]
    public function test_invalid_x_handle_is_rejected(string $value): void
    {
        self::assertNull(SocialLinkValidator::xHandle($value));
    }

    // -------------------------------------------------------------------------
    // youtubeHandle() — bare handle, "@" NEVER accepted from the user, 3-30 chars.
    // -------------------------------------------------------------------------

    public function test_valid_youtube_handle_is_accepted(): void
    {
        self::assertSame('my-channel_01', SocialLinkValidator::youtubeHandle('my-channel_01'));
    }

    public function test_youtube_handle_leading_at_is_rejected(): void
    {
        self::assertNull(SocialLinkValidator::youtubeHandle('@my-channel'));
    }

    public function test_youtube_handle_at_3_chars_is_accepted(): void
    {
        self::assertSame('abc', SocialLinkValidator::youtubeHandle('abc'));
    }

    public function test_youtube_handle_at_2_chars_is_rejected(): void
    {
        self::assertNull(SocialLinkValidator::youtubeHandle('ab'));
    }

    public function test_youtube_handle_at_30_chars_is_accepted(): void
    {
        $handle = str_repeat('a', 30);
        self::assertSame($handle, SocialLinkValidator::youtubeHandle($handle));
    }

    public function test_youtube_handle_at_31_chars_is_rejected(): void
    {
        self::assertNull(SocialLinkValidator::youtubeHandle(str_repeat('a', 31)));
    }

    public function test_youtube_handle_allows_period(): void
    {
        self::assertSame('my.channel', SocialLinkValidator::youtubeHandle('my.channel'));
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedYoutubeHandleProvider(): array
    {
        return [
            'empty'           => [''],
            'contains slash'  => ['my/channel'],
            'contains space'  => ['my channel'],
            'contains hash'   => ['my#channel'],
            'contains query'  => ['my?channel'],
            'contains plus'   => ['my+channel'],
        ];
    }

    #[DataProvider('rejectedYoutubeHandleProvider')]
    public function test_invalid_youtube_handle_is_rejected(string $value): void
    {
        self::assertNull(SocialLinkValidator::youtubeHandle($value));
    }

    // -------------------------------------------------------------------------
    // githubUsername() — real GitHub grammar: alnum + single hyphens, max 39.
    // -------------------------------------------------------------------------

    public function test_valid_github_username_is_accepted(): void
    {
        self::assertSame('octo-cat', SocialLinkValidator::githubUsername('octo-cat'));
    }

    public function test_github_username_leading_hyphen_is_rejected(): void
    {
        self::assertNull(SocialLinkValidator::githubUsername('-octocat'));
    }

    public function test_github_username_trailing_hyphen_is_rejected(): void
    {
        self::assertNull(SocialLinkValidator::githubUsername('octocat-'));
    }

    public function test_github_username_consecutive_hyphens_are_rejected(): void
    {
        self::assertNull(SocialLinkValidator::githubUsername('octo--cat'));
    }

    public function test_github_username_at_39_chars_is_accepted(): void
    {
        $username = str_repeat('a', 39);
        self::assertSame($username, SocialLinkValidator::githubUsername($username));
    }

    public function test_github_username_at_40_chars_is_rejected(): void
    {
        self::assertNull(SocialLinkValidator::githubUsername(str_repeat('a', 40)));
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedGithubUsernameProvider(): array
    {
        return [
            'empty'           => [''],
            'contains slash'  => ['octo/cat'],
            'contains dot'    => ['octo.cat'],
            'contains space'  => ['octo cat'],
            'leading at'      => ['@octocat'],
            'underscore'      => ['octo_cat'],
        ];
    }

    #[DataProvider('rejectedGithubUsernameProvider')]
    public function test_invalid_github_username_is_rejected(string $value): void
    {
        self::assertNull(SocialLinkValidator::githubUsername($value));
    }
}
