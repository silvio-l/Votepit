<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Http\Middleware\CsrfMiddleware;
use Votepit\Security\CsrfService;

/**
 * Behavioral test of the CSRF middleware at the middleware level (there is no
 * mutating product route yet at this stage; the full HTTP-seam test follows
 * once the login/submit routes exist).
 */
final class CsrfMiddlewareTest extends TestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** Plain 200 handler with no recording (for the paths that don't check the token). */
    private function handler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };
    }

    public function test_safe_get_issues_cookie_and_exposes_token(): void
    {
        // Inline handler with property: the precise anonymous class (not widened
        // to the interface) allows reading the passed-through token.
        $handler = new class () implements RequestHandlerInterface {
            public mixed $seenToken = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seenToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
                return (new ResponseFactory())->createResponse(200);
            }
        };

        $mw       = new CsrfMiddleware($this->csrf(), new ResponseFactory());
        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = $mw->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('votepit_csrf=', $response->getHeaderLine('Set-Cookie'));
        self::assertIsString($handler->seenToken);
    }

    public function test_mutating_post_without_token_is_rejected(): void
    {
        $mw       = new CsrfMiddleware($this->csrf(), new ResponseFactory());
        $request  = (new ServerRequestFactory())->createServerRequest('POST', '/');
        $response = $mw->process($request, $this->handler());

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_mutating_post_with_matching_token_passes(): void
    {
        $csrf    = $this->csrf();
        $token   = $csrf->generate();
        $mw      = new CsrfMiddleware($csrf, new ResponseFactory());
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])
            ->withParsedBody([$csrf->fieldName() => $token]);

        self::assertSame(200, $mw->process($request, $this->handler())->getStatusCode());
    }

    public function test_mutating_post_with_mismatched_field_is_rejected(): void
    {
        $csrf    = $this->csrf();
        $token   = $csrf->generate();
        $mw      = new CsrfMiddleware($csrf, new ResponseFactory());
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])
            ->withParsedBody([$csrf->fieldName() => 'wrong-token']);

        self::assertSame(403, $mw->process($request, $this->handler())->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Security review: header-based exemptions are path-scoped to the routes
    // whose stricter gate (API token / extension-verified header) actually replaces CSRF.
    // -------------------------------------------------------------------------

    public function test_bearer_header_exempts_only_api_v1_paths(): void
    {
        $mw = new CsrfMiddleware($this->csrf(), new ResponseFactory());

        $api = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/ideas')
            ->withHeader('Authorization', 'Bearer vp_test');
        self::assertSame(200, $mw->process($api, $this->handler())->getStatusCode());

        foreach (['/login', '/admin/smtp', '/acme/demo/ideas', '/api/v2/x', '/api/v1'] as $path) {
            $other = (new ServerRequestFactory())->createServerRequest('POST', $path)
                ->withHeader('Authorization', 'Bearer vp_test');
            self::assertSame(403, $mw->process($other, $this->handler())->getStatusCode(), $path);
        }
    }

    public function test_configured_header_exemption_applies_only_to_its_exact_path(): void
    {
        $mw = new CsrfMiddleware($this->csrf(), new ResponseFactory(), ['/ext/webhook' => 'X-Ext-Signature']);

        $webhook = (new ServerRequestFactory())->createServerRequest('POST', '/ext/webhook')
            ->withHeader('X-Ext-Signature', 'ts=1;h1=abc');
        self::assertSame(200, $mw->process($webhook, $this->handler())->getStatusCode());

        foreach (['/login', '/ext/webhook/x', '/acme/admin/boards'] as $path) {
            $other = (new ServerRequestFactory())->createServerRequest('POST', $path)
                ->withHeader('X-Ext-Signature', 'ts=1;h1=abc');
            self::assertSame(403, $mw->process($other, $this->handler())->getStatusCode(), $path);
        }

        // The exempted path without the header is an ordinary CSRF-checked request.
        $bare = (new ServerRequestFactory())->createServerRequest('POST', '/ext/webhook');
        self::assertSame(403, $mw->process($bare, $this->handler())->getStatusCode());
    }

    public function test_without_configured_exemptions_no_custom_header_bypasses_csrf(): void
    {
        $mw = new CsrfMiddleware($this->csrf(), new ResponseFactory());

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/ext/webhook')
            ->withHeader('X-Ext-Signature', 'ts=1;h1=abc');
        self::assertSame(403, $mw->process($request, $this->handler())->getStatusCode());
    }

    /** SPA fallback: the X-CSRF-Token header is accepted instead of the form field. */
    public function test_mutating_post_with_header_token_passes(): void
    {
        $csrf    = $this->csrf();
        $token   = $csrf->generate();
        $mw      = new CsrfMiddleware($csrf, new ResponseFactory());
        // No _csrf in the body, but the token in the X-CSRF-Token header
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])
            ->withHeader('X-CSRF-Token', $token);

        self::assertSame(200, $mw->process($request, $this->handler())->getStatusCode());
    }
}
