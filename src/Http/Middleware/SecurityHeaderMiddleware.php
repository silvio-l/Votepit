<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Votepit\Security\WidgetOriginValidator;

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
 *
 * Embeddable widget (ADR 0005) — the ONE exception to "no framing": the
 * widget page action (WidgetPageAction) marks its response with the
 * internal header INTERNAL_FRAME_ANCESTORS carrying the board's validated
 * origin list. Only then is `frame-ancestors` relaxed to exactly that list
 * and X-Frame-Options omitted (XFO cannot express an allow-list; a browser
 * honoring both would block the embed). Every origin is re-validated here
 * (WidgetOriginValidator) — a single invalid/empty entry falls back to
 * `'none'` + DENY (fail-secure). Every other response keeps
 * `frame-ancestors 'none'` + `X-Frame-Options: DENY` unconditionally.
 * INTERNAL_COOKIELESS (widget page + widget-token routes) strips every
 * Set-Cookie: the iframe runs cookie-less by design. Both internal markers
 * are read from the HANDLER's response (before extension headers are
 * applied) and always removed — they never reach the client.
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
        self::INTERNAL_FRAME_ANCESTORS,
        self::INTERNAL_COOKIELESS,
    ];

    /** Cache policy an asset route may set itself (see the Cache-Control note in the class doc). */
    private const IMMUTABLE_ASSET_CACHE_CONTROL = 'public, max-age=31536000, immutable';

    /** Internal marker: space-separated widget origins allowed to frame this response (see class doc). */
    public const INTERNAL_FRAME_ANCESTORS = 'X-Votepit-Internal-Frame-Ancestors';

    /** Internal marker: strip Set-Cookie from this response (widget routes, see class doc). */
    public const INTERNAL_COOKIELESS = 'X-Votepit-Internal-Cookieless';

    /**
     * @param array<string, string> $extraHeaders
     * @param list<string>          $trackerOrigins Config::trackerOrigins(): [] by default
     */
    public function __construct(
        private array $extraHeaders = [],
        private array $trackerOrigins = [],
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $trackerSources = implode('', array_map(static fn (string $origin): string => ' ' . $origin, $this->trackerOrigins));

        $frameAncestors = $this->widgetFrameAncestors($response);
        $cookieless     = $response->hasHeader(self::INTERNAL_COOKIELESS);
        $response       = $response
            ->withoutHeader(self::INTERNAL_FRAME_ANCESTORS)
            ->withoutHeader(self::INTERNAL_COOKIELESS);
        if ($cookieless) {
            $response = $response->withoutHeader('Set-Cookie');
        }

        foreach ($this->extraHeaders as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $response = $frameAncestors === null
            ? $response->withHeader('X-Frame-Options', 'DENY')
            : $response->withoutHeader('X-Frame-Options');

        return $response
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()')
            // Exception: the one exact immutable-asset policy (public, content-addressed
            // images: avatars, board imagery — a new upload gets a new URL) is kept.
            // Everything else stays no-store, so no user-specific response is ever cached.
            ->withHeader('Cache-Control', $response->getHeaderLine('Cache-Control') === self::IMMUTABLE_ASSET_CACHE_CONTROL
                ? self::IMMUTABLE_ASSET_CACHE_CONTROL
                : 'no-store')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups')
            // The widget page is loaded cross-origin as an iframe document
            // by design; everything else stays same-origin.
            ->withHeader('Cross-Origin-Resource-Policy', $frameAncestors === null ? 'same-origin' : 'cross-origin')
            ->withHeader('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                // Third-party tracker origins (Config::trackerOrigins(): own
                // matomo_url and/or telemetry_matomo_url) are added ONLY when
                // configured; a default install allows no third-party origin.
                // The static Apache CSP in core/public/.htaccess must list the
                // same origin (see documentation/installation.md).
                "script-src 'self'" . $trackerSources,
                // No 'unsafe-inline' anywhere in style-src*: React's style={{}}
                // prop (BrandingPreview, TagBadges, per-board custom properties
                // in lib/branding.ts, …) applies each declaration via direct
                // CSSStyleDeclaration property assignment
                // (node.style[prop] = value / node.style.setProperty() for
                // custom properties) — that sink is governed by neither
                // style-src-attr nor style-src-elem, only markup/
                // setAttribute('style', …)/style.cssText are (verified against
                // the CSP spec + React's CSSPropertyOperations source, issue 06
                // of the OWASP/BSI audit remediation). style-src-attr is kept
                // at 'none' rather than dropped, so any future markup-authored
                // style="" attribute (stored-XSS or otherwise) is rejected
                // outright instead of silently falling back to style-src.
                // style-src-elem stays 'self' — neither this app nor a future
                // regression should ever need an inline <style> element.
                "style-src 'self'",
                "style-src-attr 'none'",
                "style-src-elem 'self'",
                // https: (not a domain allow-list) is required for board
                // logos: BrandingValidator::logoUrl() already restricts a
                // board's logo_url to https-only (javascript:/data:/relative
                // schemes are rejected there), so this only ever loads a URL
                // that already passed that validation — never inline script.
                // blob: is the local preview of an image picked for upload
                // (URL.createObjectURL in LogoField/BoardAssetsSettings/
                // ProfilePage); a blob URL is created by this origin only.
                "img-src 'self' data: blob: https:",
                // connect-src/frame-src: written explicitly even though they
                // currently equal the default-src/none fallback, so this stays
                // in lockstep with core/public/.htaccess's static-asset CSP
                // (review-2026-09-04-fixes item 18) instead of silently
                // diverging if default-src is ever widened for one but not
                // the other.
                "connect-src 'self'" . $trackerSources,
                "frame-src 'none'",
                // 'none' rather than 'self': nothing in this SPA renders a
                // relative <base> tag, so there is no legitimate reason for a
                // future stored-XSS to be able to inject one and hijack every
                // relative URL on the page (script/link/form/img src alike).
                "base-uri 'none'",
                "form-action 'self'",
                'frame-ancestors ' . ($frameAncestors === null ? "'none'" : implode(' ', $frameAncestors)),
                "object-src 'none'",
            ]));
    }

    /**
     * The validated widget origin list from the internal marker, or null
     * (= no framing) when absent, empty, too long or any entry is invalid.
     *
     * @return list<string>|null
     */
    private function widgetFrameAncestors(ResponseInterface $response): ?array
    {
        $raw = trim($response->getHeaderLine(self::INTERNAL_FRAME_ANCESTORS));
        if ($raw === '') {
            return null;
        }

        $origins = explode(' ', $raw);
        if (count($origins) > WidgetOriginValidator::MAX_ORIGINS) {
            return null;
        }
        foreach ($origins as $origin) {
            if (!WidgetOriginValidator::isValid($origin)) {
                return null;
            }
        }

        return $origins;
    }
}
