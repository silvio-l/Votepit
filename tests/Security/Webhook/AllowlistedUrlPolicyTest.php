<?php

declare(strict_types=1);

namespace Votepit\Tests\Security\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Security\Webhook\AllowlistedUrlPolicy;

final class AllowlistedUrlPolicyTest extends TestCase
{
    private function policy(): AllowlistedUrlPolicy
    {
        return new AllowlistedUrlPolicy(['hooks.example.com', 'alt.example.com'], '#^/hook/[A-Za-z0-9]+$#');
    }

    public function test_accepts_an_exact_host_and_matching_path(): void
    {
        self::assertTrue($this->policy()->allows('https://hooks.example.com/hook/abc123'));
        self::assertTrue($this->policy()->allows('https://alt.example.com/hook/X'));
        self::assertTrue($this->policy()->allows('HTTPS://Hooks.Example.com/hook/abc'), 'scheme/host are case-insensitive');
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'http'                 => ['http://hooks.example.com/hook/abc'];
        yield 'foreign host'         => ['https://evil.com/hook/abc'];
        yield 'suffix lookalike'     => ['https://hooks.example.com.evil.com/hook/abc'];
        yield 'subdomain of allowed' => ['https://x.hooks.example.com/hook/abc'];
        yield 'trailing dot host'    => ['https://hooks.example.com./hook/abc'];
        yield 'explicit port'        => ['https://hooks.example.com:443/hook/abc'];
        yield 'other port'           => ['https://hooks.example.com:8443/hook/abc'];
        yield 'userinfo'             => ['https://user:pw@hooks.example.com/hook/abc'];
        yield 'userinfo trick'       => ['https://hooks.example.com@evil.com/hook/abc'];
        yield 'query'                => ['https://hooks.example.com/hook/abc?x=1'];
        yield 'fragment'             => ['https://hooks.example.com/hook/abc#x'];
        yield 'path mismatch'        => ['https://hooks.example.com/other/abc'];
        yield 'path traversal'       => ['https://hooks.example.com/hook/../admin'];
        yield 'encoded chars'        => ['https://hooks.example.com/hook/a%2Fb'];
        yield 'backslash'            => ['https://hooks.example.com\\@evil.com/hook/abc'];
        yield 'whitespace'           => [' https://hooks.example.com/hook/abc'];
        yield 'newline'              => ["https://hooks.example.com/hook/abc\n"];
        yield 'empty'                => [''];
        yield 'garbage'              => ['not a url'];
        yield 'ip literal'           => ['https://93.184.216.34/hook/abc'];
        yield 'too long'             => ['https://hooks.example.com/hook/' . str_repeat('a', 3000)];
    }

    #[DataProvider('rejected')]
    public function test_rejects(string $url): void
    {
        self::assertFalse($this->policy()->allows($url));
    }
}
