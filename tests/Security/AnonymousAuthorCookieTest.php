<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\AnonymousAuthorCookie;
use Votepit\Security\AnonymousVoterHasher;

/**
 * Guest author device cookie `vp_anon_author` (ADR 0006 §2).
 */
final class AnonymousAuthorCookieTest extends TestCase
{
    public function test_issue_sets_the_documented_attributes(): void
    {
        $response = (new AnonymousAuthorCookie(true))->issue((new ResponseFactory())->createResponse(), 'tok');
        $header   = $response->getHeaderLine('Set-Cookie');

        self::assertStringStartsWith('vp_anon_author=tok;', $header);
        self::assertStringContainsString('Path=/', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringContainsString('Secure', $header);
        self::assertStringContainsString('Max-Age=15552000', $header);
        self::assertStringNotContainsString('Domain=', $header);
    }

    public function test_no_secure_flag_outside_prod(): void
    {
        $response = (new AnonymousAuthorCookie(false))->issue((new ResponseFactory())->createResponse(), 'tok');

        self::assertStringNotContainsString('Secure', $response->getHeaderLine('Set-Cookie'));
    }

    public function test_read_accepts_only_well_formed_tokens(): void
    {
        $cookie = new AnonymousAuthorCookie(false);
        $token  = AnonymousVoterHasher::generateToken();

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        self::assertNull($cookie->read($request));
        self::assertSame($token, $cookie->read($request->withCookieParams(['vp_anon_author' => $token])));
        self::assertNull($cookie->read($request->withCookieParams(['vp_anon_author' => 'short'])));
        self::assertNull($cookie->read($request->withCookieParams(['vp_anon_author' => str_repeat('a', 40) . ';x=1'])));
    }

    public function test_initial_token_takes_over_a_valid_voter_cookie_value(): void
    {
        $voter   = AnonymousVoterHasher::generateToken();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')->withCookieParams(['vp_anon_voter' => $voter]);

        self::assertSame($voter, AnonymousAuthorCookie::initialToken($request));
    }

    public function test_initial_token_is_fresh_without_or_with_a_malformed_voter_cookie(): void
    {
        $plain     = (new ServerRequestFactory())->createServerRequest('POST', '/');
        $malformed = $plain->withCookieParams(['vp_anon_voter' => 'x']);

        $a = AnonymousAuthorCookie::initialToken($plain);
        $b = AnonymousAuthorCookie::initialToken($malformed);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $a);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $b);
        self::assertNotSame($a, $b);
    }
}
