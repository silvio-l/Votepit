<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sets the security headers on EVERY response (A05 — Security Misconfiguration).
 *
 * - HSTS: enforces HTTPS after the first visit.
 * - no-referrer: prevents a magic-link leak via Referer to third-party sites.
 * - X-Frame-Options DENY / frame-ancestors 'none': no clickjacking.
 * - CSP without unsafe-inline/unsafe-eval for script; default 'self'.
 * - nosniff: no MIME sniffing.
 * - Permissions-Policy: all sensitive features locked down.
 * - Cache-Control: no-store — every response from this middleware is an API JSON
 *   response (CSRF token, session/user data, board/idea content); must never end up
 *   in a shared intermediate cache (corporate proxy, CDN). Static SPA assets
 *   (index.html, JS/CSS bundles) do NOT go through this middleware — Apache serves
 *   those directly.
 * - Cross-Origin-Opener-Policy: same-origin-allow-popups — isolates against
 *   window.opener access from foreign origins without breaking third-party popups
 *   (e.g. an extension's checkout overlay).
 * - Cross-Origin-Resource-Policy: same-origin — no foreign page may embed our
 *   API responses/assets via <img>/<script> (Spectre-class data exfiltration).
 *
 * $extraHeaders are additional static headers an extension contributes
 * (AppExtension::responseHeaders(), e.g. X-Robots-Tag). They are written
 * BEFORE core's own headers, so even if ExtensionLoader's reserved-name
 * check were bypassed, core's values would still win.
 *
 * The CSP is deliberately strict. If a feature later needs inline JS, that's a
 * review point (then nonce-based instead of unsafe-inline).
 */
final readonly class SecurityHeaderMiddleware implements MiddlewareInterface
{
    /** Header names this middleware owns — extensions may not declare them. */
    public const CORE_HEADERS = [
        'Strict-Transport-Security',
        'X-Content-Type-Options',
        'Referrer-Policy',
        'X-Frame-Options',
        'Permissions-Policy',
        'Cache-Control',
        'Cross-Origin-Opener-Policy',
        'Cross-Origin-Resource-Policy',
        'Content-Security-Policy',
    ];

    /** @param array<string, string> $extraHeaders */
    public function __construct(private array $extraHeaders = []) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach ($this->extraHeaders as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->withHeader('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                // matomo.silvio-und-maik.de: the one real Matomo instance this  // export-ok: comment-language
                // app ever talks to — Cloud's own analytics (Config::matomoUrl)
                // and/or Community Edition's product telemetry
                // (Votepit\Telemetry\CommunityTelemetry) both live there. A
                // self-hoster who points matomo_url at a DIFFERENT Matomo
                // instance must widen this themselves (see
                // documentation/installation.md).
                "script-src 'self' https://matomo.silvio-und-maik.de", // export-ok: comment-language
                // style-src stays as the fallback for browsers without Level-3
                // support; style-src-attr/-elem narrow it further where
                // understood. React's style={{}} prop and BrandingValidator's
                // validated-hex-only <html> style attribute both need the
                // *attribute* sink — neither ever writes a <style> element, so
                // style-src-elem can stay 'self' (blocks any future stored-XSS
                // that tries to inject a <style> block).
                "style-src 'self' 'unsafe-inline'",
                "style-src-attr 'unsafe-inline'",
                "style-src-elem 'self'",
                // https: (not a domain allow-list) is required for board
                // logos: BrandingValidator::logoUrl() already restricts a
                // board's logo_url to https-only (javascript:/data:/relative
                // schemes are rejected there), so this only ever loads a URL
                // that already passed that validation — never inline script.
                "img-src 'self' data: https:",
                // connect-src/frame-src: written explicitly even though they
                // currently equal the default-src/none fallback, so this stays
                // in lockstep with core/public/.htaccess's static-asset CSP
                // (review-2026-09-04-fixes item 18) instead of silently
                // diverging if default-src is ever widened for one but not
                // the other.
                "connect-src 'self' https://matomo.silvio-und-maik.de", // export-ok: comment-language
                "frame-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "object-src 'none'",
            ]));
    }
}
