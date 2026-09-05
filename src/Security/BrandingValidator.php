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

    /** WCAG 1.4.3 AA minimum contrast ratio for normal-weight text. */
    private const MIN_CONTRAST_RATIO = 4.5;

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
     * Validates board.primary_color for WCAG 1.4.3 contrast, on top of
     * color()'s format check.
     *
     * The board's `--vp-primary` is only ever put behind TEXT/CTA content via
     * its derived `--color-vp-accent-strong` token (tokens.css,
     * BoardPage.tsx::brandingStyle()) — a fixed `color-mix(in srgb,
     * <primary> 70%, #000000)` darkening applied identically on both the
     * server's default theme and the client override, always paired with
     * white text (Button.tsx `accent` variant: `text-vp-on-ink`). Reproduce
     * that exact derivation here so a rejection here matches what would
     * actually render illegibly — not the raw, undarkened primary color.
     *
     * @return string|null normalized hex, or null if invalid format OR the
     *                      resulting accent-strong-on-white contrast would
     *                      fall below WCAG AA (4.5:1).
     */
    public static function primaryColor(string $value): ?string
    {
        $color = self::color($value);
        if ($color === null) {
            return null;
        }

        $accentStrong = self::mixTowardsBlack($color, 0.7);

        return self::contrastRatio($accentStrong, '#ffffff') >= self::MIN_CONTRAST_RATIO ? $color : null;
    }

    /**
     * Validates board.secondary_color for WCAG 1.4.3 contrast, on top of
     * color()'s format check.
     *
     * Unlike primary_color, secondary_color is used UNDARKENED as
     * `--vp-ink` — the primary CTA button background, always paired with
     * white text (`--vp-on-ink`, Button.tsx `primary` variant:
     * `bg-vp-ink text-vp-on-ink`). Check contrast against the raw value
     * directly.
     *
     * @return string|null normalized hex, or null if invalid format OR the
     *                      color-on-white contrast would fall below WCAG AA
     *                      (4.5:1).
     */
    public static function secondaryColor(string $value): ?string
    {
        $color = self::color($value);
        if ($color === null) {
            return null;
        }

        return self::contrastRatio($color, '#ffffff') >= self::MIN_CONTRAST_RATIO ? $color : null;
    }

    /**
     * Mirrors CSS `color-mix(in srgb, $hex <$weight * 100>%, #000000)`:
     * plain per-channel linear interpolation in (non-linear) sRGB space
     * towards black, NOT the gamma-corrected/linear-light mix some other
     * color spaces use. Must match the frontend's derivation exactly
     * (tokens.css, BoardPage.tsx::brandingStyle()).
     */
    private static function mixTowardsBlack(string $hex, float $weight): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);

        return sprintf('#%02x%02x%02x', (int) round($r * $weight), (int) round($g * $weight), (int) round($b * $weight));
    }

    /**
     * WCAG 2.x contrast ratio between two sRGB hex colors, (L1+0.05)/(L2+0.05)
     * with L1 the lighter relative luminance — range [1, 21].
     */
    private static function contrastRatio(string $hexA, string $hexB): float
    {
        $lumA = self::relativeLuminance($hexA);
        $lumB = self::relativeLuminance($hexB);
        $lighter = max($lumA, $lumB);
        $darker  = min($lumA, $lumB);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /** WCAG 2.x relative luminance of an sRGB hex color. */
    private static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = self::hexToRgb($hex);

        $linear = static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $linear($r) + 0.7152 * $linear($g) + 0.0722 * $linear($b);
    }

    /**
     * @return array{0: int, 1: int, 2: int} 0-255 RGB channels. $hex MUST
     *                                        already be a normalized #rgb/
     *                                        #rrggbb value (i.e. already
     *                                        passed through color()).
     */
    private static function hexToRgb(string $hex): array
    {
        $stripped = ltrim($hex, '#');
        if (strlen($stripped) === 3) {
            $stripped = $stripped[0] . $stripped[0] . $stripped[1] . $stripped[1] . $stripped[2] . $stripped[2];
        }

        return [
            (int) hexdec(substr($stripped, 0, 2)),
            (int) hexdec(substr($stripped, 2, 2)),
            (int) hexdec(substr($stripped, 4, 2)),
        ];
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
