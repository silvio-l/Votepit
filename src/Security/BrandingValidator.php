<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Validates and sanitizes per-board branding values BEFORE they are stored AND
 * BEFORE they are emitted into CSS / HTML (defense in depth).
 *
 * Treat every branding value as hostile input. Brand colors flow into a `style`
 * attribute (`--vp-primary: <value>`); only a strictly validated hex literal may
 * ever reach that sink. An invalid value is REJECTED → null → caller falls back
 * to the default theme. No unvalidated value is ever interpolated into CSS.
 *
 * `logo_url` is restricted to a relative internal path or an absolute https URL;
 * `javascript:` / `data:` and protocol-relative `//` are rejected. Output is
 * additionally escaped by Twig autoescape (never `|raw`).
 *
 * Only the BRAND layer is overridable. The semantic tokens (--vp-vote-up/-down,
 * --vp-consensus-*, status, fonts) are NEVER emitted here.
 */
final class BrandingValidator
{
    private const COLOR_PATTERN = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    private const MAX_LOGO_URL_LENGTH = 512;

    /**
     * Branding tiers — ASSUMPTION: no exact cap is specified by the product
     * requirements; 1000 chars comfortably fits a short intro
     * paragraph (well above logoUrl's 512-char cap for what is meant to be a
     * one-liner, well below a full "about" page) while still bounding
     * storage/rendering cost.
     */
    private const MAX_INTRO_LENGTH = 1000;

    /**
     * Returns a normalized (lowercased) hex color (#rgb or #rrggbb), or null if
     * the value is not a strict hex literal. Anything that could break out of the
     * CSS value context (`;`, `}`, spaces, `url(`, …) fails the pattern → null.
     */
    public static function color(string $value): ?string
    {
        $trimmed = trim($value);

        if (preg_match(self::COLOR_PATTERN, $trimmed) !== 1) {
            return null;
        }

        return strtolower($trimmed);
    }

    /**
     * Returns a safe logo URL (relative internal path or absolute https URL), or
     * null. Rejects empty, overlong, protocol-relative (`//`), backslash, and any
     * non-https scheme (javascript:, data:, http:, …).
     */
    public static function logoUrl(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > self::MAX_LOGO_URL_LENGTH) {
            return null;
        }

        // No whitespace/control characters anywhere in the URL.
        if (preg_match('/\s/', $trimmed) === 1) {
            return null;
        }

        if (str_contains($trimmed, '\\')) {
            return null;
        }

        // Relative internal path: starts with a single '/'.
        if (str_starts_with($trimmed, '/')) {
            return str_starts_with($trimmed, '//') ? null : $trimmed;
        }

        // Absolute URL: https only.
        if (preg_match('#^https://#i', $trimmed) === 1
            && filter_var($trimmed, FILTER_VALIDATE_URL) !== false) {
            return $trimmed;
        }

        return null;
    }

    /**
     * Returns a normalized (trimmed) plaintext intro/tagline, or null if the
     * value is empty, overlong, or contains ANY markup/script vector.
     *
     * `intro` is rendered as a plain React text
     * node (BoardPage.tsx) — never via `dangerouslySetInnerHTML` — but this
     * validator treats it as hostile input regardless, exactly like color()/
     * logoUrl(): a shared-origin invariant applies to EVERY branding field,
     * not just the ones with an obvious CSS/URL sink today. Three
     * independent checks, any one of which rejects outright (never
     * strips-and-keeps the rest of the value):
     *   - any HTML/Markdown-style tag (`strip_tags()` changing the value
     *     means one was present) — blocks `<script>`, `<img onerror=…>`, …
     *   - a bare `on*=` event-handler-looking fragment, even without a full
     *     tag (e.g. a naked `onerror="alert(1)"` fragment) — defense in
     *     depth against a future consumer that interpolates intro into an
     *     HTML attribute context.
     *   - an embedded `javascript:` pseudo-scheme — defense in depth against
     *     a future consumer that renders intro as/inside a link, mirroring
     *     logoUrl()'s scheme rejection.
     */
    public static function introText(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || mb_strlen($trimmed) > self::MAX_INTRO_LENGTH) {
            return null;
        }

        if (strip_tags($trimmed) !== $trimmed) {
            return null;
        }

        if (preg_match('/\bon\w+\s*=/i', $trimmed) === 1) {
            return null;
        }

        if (preg_match('/javascript\s*:/i', $trimmed) === 1) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Builds the inline `style` string of validated brand-token overrides for the
     * `<html>` element, e.g. "--vp-primary: #112233; --vp-secondary: #445566;".
     *
     * Only validated values are included; an invalid/empty value is silently
     * dropped (→ default token from :root wins). Returns '' when nothing valid is
     * present, so the layout renders the plain default theme. The semantic layer
     * is never touched here.
     */
    public static function inlineStyle(?string $primary, ?string $secondary): string
    {
        $parts = [];

        $validPrimary = $primary !== null ? self::color($primary) : null;
        if ($validPrimary !== null) {
            $parts[] = '--vp-primary: ' . $validPrimary;
        }

        $validSecondary = $secondary !== null ? self::color($secondary) : null;
        if ($validSecondary !== null) {
            $parts[] = '--vp-secondary: ' . $validSecondary;
        }

        return $parts === [] ? '' : implode('; ', $parts) . ';';
    }
}
