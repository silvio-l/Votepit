<?php

declare(strict_types=1);

namespace Votepit\Tests\Security\OAuthServer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Security\OAuth\PkceGenerator;
use Votepit\Security\OAuthServer\ClientRegistrationValidator;
use Votepit\Security\OAuthServer\OAuthScope;
use Votepit\Security\OAuthServer\OAuthServerMetadata;
use Votepit\Security\OAuthServer\PkceVerifier;
use Votepit\Security\OAuthServer\RedirectUriPolicy;

/**
 * Pure policy pieces of the MCP OAuth server (issue 21): redirect URI
 * policy, registration validation, scope ceiling, PKCE and metadata.
 */
final class OAuthServerPolicyTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function allowedRedirectUris(): array
    {
        return [
            'https'              => ['https://claude.ai/api/mcp/auth_callback'],
            'https with port'    => ['https://example.com:8443/cb?x=1'],
            'loopback ipv4'      => ['http://127.0.0.1:33418/callback'],
            'loopback localhost' => ['http://localhost:6274/oauth/callback'],
            'loopback ipv6'      => ['http://[::1]:5000/cb'],
        ];
    }

    #[DataProvider('allowedRedirectUris')]
    public function test_allows_https_and_loopback_redirects(string $uri): void
    {
        self::assertTrue(RedirectUriPolicy::isAllowed($uri));
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedRedirectUris(): array
    {
        return [
            'plain http'        => ['http://example.com/cb'],
            'custom scheme'     => ['cursor://anysphere.cursor-retrieval/oauth/callback'],
            'javascript'        => ['javascript:alert(1)'],
            'data'              => ['data:text/html,x'],
            'fragment'          => ['https://example.com/cb#frag'],
            'userinfo'          => ['https://user@example.com/cb'],
            'loopback lookalike' => ['http://127.0.0.1.evil.com/cb'],
            'no host'           => ['https:///cb'],
            'relative'          => ['/cb'],
            'whitespace'        => ['https://example.com/c b'],
            'backslash'         => ['https://example.com\\@evil.com/'],
            'non-ascii host'    => ['https://exämple.com/cb'], // export-ok: comment-language
            'too long'          => ['https://example.com/' . str_repeat('a', 600)],
        ];
    }

    #[DataProvider('rejectedRedirectUris')]
    public function test_rejects_unsafe_redirects(string $uri): void
    {
        self::assertFalse(RedirectUriPolicy::isAllowed($uri));
    }

    public function test_registration_accepts_a_minimal_public_client(): void
    {
        $result = ClientRegistrationValidator::validate([
            'redirect_uris' => ['http://127.0.0.1:1234/cb', 'http://127.0.0.1:1234/cb'],
        ]);

        self::assertSame(['client_name' => 'MCP client', 'redirect_uris' => ['http://127.0.0.1:1234/cb']], $result);
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function invalidRegistrations(): array
    {
        $ok = ['redirect_uris' => ['https://example.com/cb']];

        return [
            'not an object'        => ['x', 'invalid_client_metadata'],
            'confidential client'  => [$ok + ['token_endpoint_auth_method' => 'client_secret_basic'], 'invalid_client_metadata'],
            'implicit grant'       => [$ok + ['grant_types' => ['implicit']], 'invalid_client_metadata'],
            'token response type'  => [$ok + ['response_types' => ['token']], 'invalid_client_metadata'],
            'no redirect uris'     => [['redirect_uris' => []], 'invalid_redirect_uri'],
            'too many uris'        => [['redirect_uris' => array_map(static fn (int $i): string => 'https://e.com/' . $i, range(1, 6))], 'invalid_redirect_uri'],
            'bad uri'              => [['redirect_uris' => ['http://evil.com/cb']], 'invalid_redirect_uri'],
            'name not a string'    => [$ok + ['client_name' => ['x']], 'invalid_client_metadata'],
            'name too long'        => [$ok + ['client_name' => str_repeat('a', 101)], 'invalid_client_metadata'],
            'name control char'    => [$ok + ['client_name' => "Evil\u{202E}txt"], 'invalid_client_metadata'],
            'name newline'         => [$ok + ['client_name' => "a\nb"], 'invalid_client_metadata'],
        ];
    }

    #[DataProvider('invalidRegistrations')]
    public function test_registration_rejects(mixed $metadata, string $error): void
    {
        $result = ClientRegistrationValidator::validate($metadata);

        self::assertSame($error, $result['error'] ?? null);
    }

    public function test_registration_keeps_markup_as_plain_text(): void
    {
        $result = ClientRegistrationValidator::validate([
            'redirect_uris' => ['https://example.com/cb'],
            'client_name'   => '<img src=x onerror=alert(1)>',
            'logo_uri'      => 'https://evil.example/logo.png',
        ]);

        // Stored verbatim as plaintext (escaped on output); unknown fields dropped.
        self::assertSame(['client_name' => '<img src=x onerror=alert(1)>', 'redirect_uris' => ['https://example.com/cb']], $result);
    }

    public function test_scope_ceiling(): void
    {
        self::assertSame('moderate', OAuthScope::maxLevel(null));
        self::assertSame('moderate', OAuthScope::maxLevel(''));
        self::assertSame('read', OAuthScope::maxLevel('read'));
        self::assertSame('write', OAuthScope::maxLevel('read  write'));
        self::assertNull(OAuthScope::maxLevel('read admin'));
        self::assertTrue(OAuthScope::within('read', 'write'));
        self::assertFalse(OAuthScope::within('moderate', 'write'));
        self::assertFalse(OAuthScope::within('bogus', 'moderate'));
        self::assertSame('read moderate', OAuthScope::grantedScope([1 => 'moderate', 2 => 'read', 3 => 'read']));
    }

    public function test_pkce_s256(): void
    {
        $verifier  = str_repeat('v', 50);
        $challenge = PkceGenerator::challengeFor($verifier);

        self::assertTrue(PkceVerifier::isValidChallenge($challenge));
        self::assertTrue(PkceVerifier::verify($verifier, $challenge));
        self::assertFalse(PkceVerifier::verify(str_repeat('w', 50), $challenge));
        self::assertFalse(PkceVerifier::isValidVerifier('short'));
        self::assertFalse(PkceVerifier::isValidChallenge($verifier));
    }

    public function test_metadata_documents(): void
    {
        $meta = new OAuthServerMetadata('https://app.example.com/');

        self::assertSame('https://app.example.com', $meta->issuer());
        self::assertSame('https://app.example.com/api/v1/mcp', $meta->resource());
        self::assertSame('https://app.example.com/.well-known/oauth-protected-resource/api/v1/mcp', $meta->resourceMetadataUrl());
        self::assertTrue($meta->acceptsResource('https://app.example.com/api/v1/mcp?board=x'));
        self::assertTrue($meta->acceptsResource('https://app.example.com'));
        self::assertFalse($meta->acceptsResource('https://evil.example.com/api/v1/mcp'));

        $as = $meta->authorizationServerDocument();
        self::assertSame(['S256'], $as['code_challenge_methods_supported']);
        self::assertSame(['none'], $as['token_endpoint_auth_methods_supported']);
        self::assertSame(['https://app.example.com'], $meta->protectedResourceDocument()['authorization_servers']);
    }
}
