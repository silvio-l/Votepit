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
 * Verhaltenstest der CSRF-Middleware an der Middleware-Ebene (Sprint 0 hat noch
 * keine mutierende Produkt-Route; der HTTP-Seam-Volltest folgt mit den
 * Login-/Submit-Routen in Sprint 2+).
 */
final class CsrfMiddlewareTest extends TestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** Schlichter 200-Handler ohne Aufzeichnung (für die Pfade, die den Token nicht prüfen). */
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
        // Inline-Handler mit Property: die präzise (nicht zum Interface verbreiterte)
        // anonyme Klasse erlaubt das Auslesen des durchgereichten Tokens.
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
            ->withParsedBody([$csrf->fieldName() => 'falscher-token']);

        self::assertSame(403, $mw->process($request, $this->handler())->getStatusCode());
    }

    /** SPA-Fallback: X-CSRF-Token-Header wird statt des Form-Feldes akzeptiert. */
    public function test_mutating_post_with_header_token_passes(): void
    {
        $csrf    = $this->csrf();
        $token   = $csrf->generate();
        $mw      = new CsrfMiddleware($csrf, new ResponseFactory());
        // Kein _csrf im Body, aber das Token im X-CSRF-Token-Header
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])
            ->withHeader('X-CSRF-Token', $token);

        self::assertSame(200, $mw->process($request, $this->handler())->getStatusCode());
    }
}
