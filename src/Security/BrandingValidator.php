<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Validates and sanitizes per-board branding values BEFORE they are stored AND
 * BEFORE they are emitted into CSS / HTML (Issue 08 — defense in depth).
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
