<?php

declare(strict_types=1);

namespace Votepit\Mail;

/**
 * Shared transactional mail template (branding, multipart bodies).
 *
 * Renders both bodies of a multipart/alternative mail from ONE set of
 * content building blocks (heading, paragraphs, optional CTA link with
 * button label, muted footer lines): the plaintext part (wording
 * deliberately identical to the earlier plain-text mails) and a branded
 * HTML part.
 *
 * HTML is deliberately old-school: table layout + inline styles (Outlook
 * Desktop supports neither Flex/Grid nor reliable <style> blocks), no
 * external resources (no CSS, no JS, no lazily loaded images). The
 * word-image mark in the header is the real lockup graphic (hexagon icon +
 * wordmark) as a CID inline image — `render()` supplies the needed
 * {@see InlineImage} for it, and the sending adapter embeds it.
 * System-sans stack instead of webfonts (mail clients don't reliably load
 * @font-face).
 *
 * Security: all content is server-authorized (never user/tenant input);
 * nonetheless EVERY interpolated value is HTML-escaped — defense in depth,
 * in case a future caller does pass through dynamic values. Plaintext
 * tokens still belong ONLY in the link URL.
 */
final class MailTemplate
{
    // Brand tokens — canonical source: core/packages/ui/src/tokens.css (the
    // app's "result sheet" palette: desk, white sheet, ink head rule).
    private const COLOR_INK       = '#15161a'; // near-black (headings, body text, sheet head rule)
    private const COLOR_CTA       = '#1FA890'; // teal-green (primary button)
    private const COLOR_SECONDARY = '#565a62'; // secondary text
    private const COLOR_MUTED     = '#6b707c'; // footer / fallback link line
    private const COLOR_BG        = '#f4f4f1'; // the desk the sheet lies on
    private const COLOR_RULE      = '#e6e6e1'; // hairline rule
    private const FONT_STACK      = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

    // Word-image mark (light variant — the mail card is white). The CID name
    // is the anchor between the HTML (`src="cid:…"`) and the inline attachment.
    public const LOGO_CID = 'votepit-logo';
    private const LOGO_RELATIVE_PATH = '/public/assets/brand/votepit-lockup-light.png';
    // Display dimensions: source 1090×236 px (~4.62:1) → 148×32 CSS px (retina-sharp).
    private const LOGO_WIDTH  = 148;
    private const LOGO_HEIGHT = 32;

    /**
     * @param list<string> $paragraphs  paragraphs in order (plaintext wording)
     * @param list<string> $footerLines muted closing lines (validity, "do not share")
     *
     * @return array{text: string, html: string, image: InlineImage}
     */
    public static function render(
        string $heading,
        array $paragraphs,
        ?string $ctaUrl = null,
        ?string $ctaLabel = null,
        array $footerLines = [],
    ): array {
        return [
            'text'  => self::renderText($paragraphs, $ctaUrl, $footerLines),
            'html'  => self::renderHtml($heading, $paragraphs, $ctaUrl, $ctaLabel, $footerLines),
            'image' => self::logo(),
        ];
    }

    /**
     * The header logo as an embeddable inline image. Path resolved relative
     * to `core/` (never hardcoded per deployment) — this works identically
     * for self-host installations at any location and for the cloud.
     */
    private static function logo(): InlineImage
    {
        return new InlineImage(self::LOGO_CID, \dirname(__DIR__, 2) . self::LOGO_RELATIVE_PATH);
    }

    /**
     * Plaintext part — structure exactly like the earlier plain-text mails:
     * paragraphs separated by a blank line, link on its own line, footer
     * lines one per line.
     *
     * @param list<string> $paragraphs
     * @param list<string> $footerLines
     */
    private static function renderText(array $paragraphs, ?string $ctaUrl, array $footerLines): string
    {
        $blocks = $paragraphs;
        if ($ctaUrl !== null) {
            $blocks[] = $ctaUrl;
        }

        if ($footerLines !== []) {
            $blocks[] = implode("\n", $footerLines);
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * @param list<string> $paragraphs
     * @param list<string> $footerLines
     */
    private static function renderHtml(
        string $heading,
        array $paragraphs,
        ?string $ctaUrl,
        ?string $ctaLabel,
        array $footerLines,
    ): string {
        $font  = self::FONT_STACK;
        $parts = [];

        foreach ($paragraphs as $paragraph) {
            $parts[] = '<p style="margin: 0 0 16px 0; font-family: ' . $font . '; font-size: 16px;'
                . ' line-height: 1.6; color: ' . self::COLOR_INK . ';">'
                . self::e($paragraph) . '</p>';
        }

        if ($ctaUrl !== null) {
            $url     = self::e($ctaUrl);
            $label   = self::e($ctaLabel ?? $ctaUrl);
            $parts[] = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
                . ' style="margin: 28px 0 20px 0;"><tr>'
                . '<td style="background-color: ' . self::COLOR_CTA . '; border-radius: 8px;">'
                . '<a href="' . $url . '" style="display: inline-block; padding: 14px 28px;'
                . ' font-family: ' . $font . '; font-size: 16px; font-weight: 600; line-height: 1.25;'
                . ' color: #ffffff; text-decoration: none; border-radius: 8px;">' . $label . '</a>'
                . '</td></tr></table>';
            // Fallback for clients that don't render the button as clickable.
            $parts[] = '<p style="margin: 0 0 16px 0; font-family: ' . $font . '; font-size: 12px;'
                . ' line-height: 1.6; color: ' . self::COLOR_MUTED . '; word-break: break-all;">'
                . 'If the button does not work, copy this link into your browser:<br>'
                . '<a href="' . $url . '" style="color: ' . self::COLOR_SECONDARY . ';">' . $url . '</a></p>';
        }

        $footer = '';
        foreach ($footerLines as $line) {
            $footer .= '<p style="margin: 0 0 4px 0; font-family: ' . $font . '; font-size: 12px;'
                . ' line-height: 1.6; color: ' . self::COLOR_MUTED . ';">' . self::e($line) . '</p>';
        }

        if ($footer !== '') {
            // The sheet foot: a hairline rule, then the muted closing lines.
            $footer = '<tr><td style="padding: 20px 40px 0 40px; border-top: 1px solid ' . self::COLOR_RULE . ';">'
                . $footer . '</td></tr>';
        }

        // Word-image mark (hexagon icon + wordmark) as a CID inline image —
        // fixed width/height attributes (Outlook likes to ignore CSS sizing),
        // display:block against baseline gaps under images inside tables.
        $logo = '<img src="cid:' . self::LOGO_CID . '" alt="Votepit"'
            . ' width="' . self::LOGO_WIDTH . '" height="' . self::LOGO_HEIGHT . '"'
            . ' style="display: block; width: ' . self::LOGO_WIDTH . 'px;'
            . ' height: ' . self::LOGO_HEIGHT . 'px; border: 0;">';

        // The sheet's head rule (the app's `.vp-sheet--ruled`): a 3px ink row
        // as the first table row rather than a border-top, so it survives
        // clients that drop borders on table cells.
        $rule = '<tr><td style="height: 3px; line-height: 3px; font-size: 0;'
            . ' background-color: ' . self::COLOR_INK . '; border-radius: 10px 10px 0 0;">&nbsp;</td></tr>';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            . ' style="background-color: ' . self::COLOR_BG . '; padding: 40px 16px;"><tr>'
            . '<td align="center">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0"'
            . ' style="max-width: 560px; width: 100%; background-color: #ffffff; border-radius: 10px;'
            . ' border: 1px solid ' . self::COLOR_RULE . ';">'
            . $rule
            . '<tr><td style="padding: 32px 40px 8px 40px;">'
            . $logo
            . '</td></tr>'
            . '<tr><td style="padding: 20px 40px 0 40px;">'
            . '<h1 style="margin: 0 0 16px 0; font-family: ' . $font . '; font-size: 24px;'
            . ' font-weight: 700; line-height: 1.3; letter-spacing: -0.01em; color: ' . self::COLOR_INK . ';">'
            . self::e($heading) . '</h1>'
            . implode('', $parts)
            . '</td></tr>'
            . $footer
            . '<tr><td style="padding: 20px 40px 36px 40px;"></td></tr>'
            . '</table>'
            . '</td></tr></table>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
