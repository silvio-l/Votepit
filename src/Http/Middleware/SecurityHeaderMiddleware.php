<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Setzt die Security-Header auf JEDEM Response (A05 — Security Misconfiguration).
 *
 * - HSTS: erzwingt HTTPS nach erstem Besuch.
 * - no-referrer: verhindert Magic-Link-Leak via Referer an Drittseiten.
 * - X-Frame-Options DENY / frame-ancestors 'none': kein Clickjacking.
 * - CSP ohne unsafe-inline/unsafe-eval für script; default 'self'.
 * - nosniff: kein MIME-Sniffing.
 * - Permissions-Policy: alle sensiblen Features gesperrt.
 *
 * Die CSP ist bewusst streng. Falls ein Feature später Inline-JS braucht,
 * ist das ein Review-Punkt (dann nonce-basiert statt unsafe-inline).
 */
final class SecurityHeaderMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()')
            ->withHeader('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "object-src 'none'",
            ]));
    }
}
