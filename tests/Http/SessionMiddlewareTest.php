<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Http\Middleware\SessionMiddleware;
use Votepit\Security\SessionService;

/**
 * SessionMiddleware — duplicate-cookie robustness (live-debugged on
 * staging, 2026-09-05, see class doc). A browser that sends more than one
 * `votepit_sess=...` in a single Cookie header (a stale duplicate alongside
 * a valid fresh one) must still resolve the valid one, regardless of
 * which duplicate a naive single-value cookie parser would have kept.
 */
final class SessionMiddlewareTest extends TestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function sessions(): SessionService
    {
        return new SessionService(self::APP_KEY, 3600, false);
    }

    private function capturedUserId(string $rawCookieHeader): ?int
    {
        $middleware = new SessionMiddleware($this->sessions());

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/bootstrap')
            ->withHeader('Cookie', $rawCookieHeader);

        // Round-trips the resolved user ID through a response header instead
        // of a by-ref captured variable, so PHPStan doesn't flag the
        // handler's own state as write-only.
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $userId = $request->getAttribute(SessionMiddleware::ATTR_USER_ID);
                return (new ResponseFactory())->createResponse()
                    ->withHeader('X-Test-User-Id', $userId === null ? '' : (string) $userId);
            }
        };

        $response = $middleware->process($request, $handler);
        $value    = $response->getHeaderLine('X-Test-User-Id');

        return $value === '' ? null : (int) $value;
    }

    public function test_single_valid_cookie_resolves_normally(): void
    {
        $cookie = $this->sessions()->sign(['uid' => 7, 'v' => 0]);

        self::assertSame(7, $this->capturedUserId('votepit_sess=' . $cookie));
    }

    public function test_stale_duplicate_before_a_valid_cookie_does_not_shadow_it(): void
    {
        $stale = 'garbage-invalid-signature';
        $fresh = $this->sessions()->sign(['uid' => 7, 'v' => 0]);

        self::assertSame(
            7,
            $this->capturedUserId('votepit_sess=' . $stale . '; votepit_sess=' . $fresh),
        );
    }

    public function test_stale_duplicate_after_a_valid_cookie_does_not_shadow_it(): void
    {
        $fresh = $this->sessions()->sign(['uid' => 7, 'v' => 0]);
        $stale = 'garbage-invalid-signature';

        self::assertSame(
            7,
            $this->capturedUserId('votepit_sess=' . $fresh . '; votepit_sess=' . $stale),
        );
    }

    public function test_two_valid_but_different_sessions_resolves_the_first_when_issued_at_the_same_time(): void
    {
        $first  = $this->sessions()->sign(['uid' => 3, 'v' => 0]);
        $second = $this->sessions()->sign(['uid' => 7, 'v' => 0]);

        self::assertSame(
            3,
            $this->capturedUserId('votepit_sess=' . $first . '; votepit_sess=' . $second),
        );
    }

    /**
     * Regression test for the actual production bug (2026-09-05): a browser
     * sends duplicate `votepit_sess` cookies oldest-first (RFC 6265 §5.4).
     * The stale one here is listed FIRST in the header — exactly how a real
     * browser would send it — and must not shadow the session the user just
     * logged into.
     */
    public function test_older_duplicate_listed_first_does_not_shadow_the_newer_session(): void
    {
        $stale = $this->sessions()->sign(['uid' => 3, 'v' => 0, 'iat' => 1000]);
        $fresh = $this->sessions()->sign(['uid' => 7, 'v' => 0, 'iat' => 2000]);

        self::assertSame(
            7,
            $this->capturedUserId('votepit_sess=' . $stale . '; votepit_sess=' . $fresh),
        );
    }

    public function test_all_duplicates_invalid_resolves_to_anonymous(): void
    {
        self::assertNull(
            $this->capturedUserId('votepit_sess=nope; votepit_sess=also-nope'),
        );
    }

    public function test_no_cookie_header_resolves_to_anonymous(): void
    {
        self::assertNull($this->capturedUserId(''));
    }

    public function test_unrelated_cookies_around_the_session_cookie_are_ignored(): void
    {
        $fresh = $this->sessions()->sign(['uid' => 7, 'v' => 0]);

        self::assertSame(
            7,
            $this->capturedUserId('cf_clearance=abc123; votepit_sess=' . $fresh . '; other=xyz'),
        );
    }
}
