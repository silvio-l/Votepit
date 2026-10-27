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
 * The body is a full HTML document that declares `color-scheme: light only`:
 * without it Apple Mail's dark mode inverted the sheet and the dark ink
 * wordmark vanished on the dark card (seen 2026-10-26 in real test mails).
 * The sheet stays a white sheet on the desk in every client that honours
 * the declaration. A hidden preheader (the first paragraph) feeds the inbox
 * preview line, and one small media query narrows the side padding on phones
 * (clients without <style> support keep the inline desktop padding).
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
    public const COLOR_INK        = '#15161a'; // near-black (headings, body text, sheet head rule)
    // Text-safe teal: the default accent's documented "text-safe" value (tokens.css, 5.5:1 with white).
    // The raw accent #1FA890 gives white button text only 2.97:1 (WCAG AA needs 4.5:1).
    public const COLOR_CTA        = '#167665'; // teal (primary button, white label)
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

    /** Fallback line under the button (HTML part); the English default, mails pass their own language. */
    public const DEFAULT_BUTTON_FALLBACK = 'If the button does not work, copy this link into your browser:';

    /**
     * @param list<string|MailBlock> $paragraphs paragraphs in order (plaintext wording); a
     *        MailBlock adds a fixed style (subheading, muted meta line, list item)
     * @param list<string> $footerLines muted closing lines (validity, "do not share")
     * @param array{label: string, url: string}|null $footerLink optional muted link after the
     *        footer lines (e.g. the weekly digest's unsubscribe link)
     * @param MailBrand|null $brand  board branding (branded board mails only); null = Votepit default.
     *        Auth and security mails never pass a brand.
     * @param string|null $headerNote short muted note right of the logo (e.g. the digest's date range)
     * @param list<array{label: string, url: string}> $footerLinks muted links shown with $footerLink
     *        on one line (e.g. mail settings next to unsubscribe)
     * @param string|null $buttonFallback line above the copyable link under the button (HTML part),
     *        already in the mail's language; null = DEFAULT_BUTTON_FALLBACK (English)
     *
     * @return array{text: string, html: string, image: InlineImage}
     */
    public static function render(
        string $heading,
        array $paragraphs,
        ?string $ctaUrl = null,
        ?string $ctaLabel = null,
        array $footerLines = [],
        ?array $footerLink = null,
        ?MailBrand $brand = null,
        ?string $headerNote = null,
        array $footerLinks = [],
        ?string $buttonFallback = null,
    ): array {
        if ($footerLink !== null) {
            $footerLinks[] = $footerLink;
        }

        return [
            'text'  => self::renderText($paragraphs, $ctaUrl, $ctaLabel, $footerLines, $footerLinks, $headerNote),
            'html'  => self::renderHtml($heading, $paragraphs, $ctaUrl, $ctaLabel, $footerLines, $footerLinks, $brand, $headerNote, $buttonFallback),
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
     * @param list<string|MailBlock> $paragraphs
     * @param list<string> $footerLines
     * @param list<array{label: string, url: string}> $footerLinks
     */
    private static function renderText(array $paragraphs, ?string $ctaUrl, ?string $ctaLabel, array $footerLines, array $footerLinks, ?string $headerNote): string
    {
        $blocks = $headerNote !== null ? [$headerNote] : [];
        $cta    = $ctaUrl === null ? null : ($ctaLabel !== null ? $ctaLabel . ":\n" . $ctaUrl : $ctaUrl);
        $list   = null; // consecutive list items stay together, one per line
        foreach ($paragraphs as $paragraph) {
            if ($paragraph instanceof MailBlock && $paragraph->style === MailBlock::ITEM) {
                $list = ($list === null ? '' : $list . "\n") . '- ' . $paragraph->text;
                continue;
            }
            if ($list !== null) {
                $blocks[] = $list;
                $list     = null;
            }
            if ($paragraph instanceof MailBlock && $paragraph->style === MailBlock::CTA) {
                if ($cta !== null) {
                    $blocks[] = $cta;
                    $cta      = null;
                }
                continue;
            }
            $blocks[] = $paragraph instanceof MailBlock ? self::blockText($paragraph) : $paragraph;
        }
        if ($list !== null) {
            $blocks[] = $list;
        }

        if ($cta !== null) {
            $blocks[] = $ctaUrl;
        }

        foreach ($footerLinks as $link) {
            $footerLines[] = $link['label'] . ': ' . $link['url'];
        }

        if ($footerLines !== []) {
            $blocks[] = implode("\n", $footerLines);
        }

        return implode("\n\n", $blocks) . "\n";
    }

    private static function blockText(MailBlock $block): string
    {
        $lines = [$block->text];
        if ($block->style === MailBlock::IDEA && $block->meta !== '') {
            $lines[] = $block->meta;
        }
        if ($block->url !== null) {
            $lines[] = $block->url;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string|MailBlock> $paragraphs
     * @param list<string> $footerLines
     * @param list<array{label: string, url: string}> $footerLinks
     */
    private static function renderHtml(
        string $heading,
        array $paragraphs,
        ?string $ctaUrl,
        ?string $ctaLabel,
        array $footerLines,
        array $footerLinks,
        ?MailBrand $brand,
        ?string $headerNote,
        ?string $buttonFallback,
    ): string {
        $font     = self::FONT_STACK;
        $ctaColor  = $brand instanceof \Votepit\Mail\MailBrand ? $brand->ctaColor : self::COLOR_CTA;
        $ruleColor = $brand instanceof \Votepit\Mail\MailBrand ? $brand->ruleColor : self::COLOR_INK;
        $parts    = [];

        $button   = '';
        $fallback = '';
        if ($ctaUrl !== null) {
            $url    = self::e($ctaUrl);
            $label  = self::e($ctaLabel ?? $ctaUrl);
            $button = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
                . ' style="margin: 24px 0 20px 0;"><tr>'
                . '<td style="background-color: ' . $ctaColor . '; border-radius: 8px;">'
                . '<a href="' . $url . '" style="display: inline-block; padding: 14px 28px;'
                . ' font-family: ' . $font . '; font-size: 16px; font-weight: 600; line-height: 1.25;'
                . ' color: #ffffff; text-decoration: none; border-radius: 8px;">' . $label . '</a>'
                . '</td></tr></table>';
            // Fallback for clients that don't render the button as clickable.
            // Mails that place the button early (CTA slot) skip it: the button is
            // a plain link and the plaintext part carries the URL anyway.
            $fallback = '<p style="margin: 24px 0 16px 0; font-family: ' . $font . '; font-size: 12px;'
                . ' line-height: 1.6; color: ' . self::COLOR_MUTED . '; word-break: break-all;">'
                . self::e($buttonFallback ?? self::DEFAULT_BUTTON_FALLBACK) . '<br>'
                . '<a href="' . $url . '" style="color: ' . self::COLOR_SECONDARY . ';">' . $url . '</a></p>';
        }

        foreach ($paragraphs as $paragraph) {
            if ($paragraph instanceof MailBlock && $paragraph->style === MailBlock::CTA) {
                $parts[]  = $button;
                $button   = '';
                $fallback = '';
                continue;
            }
            $parts[] = self::paragraphHtml($paragraph);
        }
        $parts[] = $button . $fallback;

        $footer = '';
        foreach ($footerLines as $line) {
            $footer .= '<p style="margin: 0 0 4px 0; font-family: ' . $font . '; font-size: 12px;'
                . ' line-height: 1.6; color: ' . self::COLOR_MUTED . ';">' . self::e($line) . '</p>';
        }

        if ($footerLinks !== []) {
            $links = array_map(
                static fn (array $link): string => '<a href="' . self::e($link['url']) . '" style="color: ' . self::COLOR_SECONDARY . ';">'
                    . self::e($link['label']) . '</a>',
                $footerLinks,
            );
            $footer .= '<p style="margin: 8px 0 4px 0; font-family: ' . $font . '; font-size: 12px;'
                . ' line-height: 1.6; color: ' . self::COLOR_MUTED . ';">' . implode(' &nbsp;·&nbsp; ', $links) . '</p>';
        }

        if ($footer !== '') {
            // The sheet foot: a hairline rule, then the muted closing lines.
            $footer = '<tr><td class="vp-pad" style="padding: 20px 40px 0 40px; border-top: 1px solid ' . self::COLOR_RULE . ';">'
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
            . ' background-color: ' . $ruleColor . '; border-radius: 10px 10px 0 0;">&nbsp;</td></tr>';

        $sheet = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            . ' class="vp-desk" style="background-color: ' . self::COLOR_BG . '; padding: 40px 16px;"><tr>'
            . '<td align="center">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0"'
            . ' style="max-width: 560px; width: 100%; background-color: #ffffff; border-radius: 10px;'
            . ' border: 1px solid ' . self::COLOR_RULE . ';">'
            . $rule
            . '<tr><td class="vp-pad" style="padding: 32px 40px 8px 40px;">'
            . ($headerNote === null ? $logo : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td valign="middle">' . $logo . '</td>'
                . '<td align="right" valign="middle" style="font-family: ' . $font . '; font-size: 13px; line-height: 1.4;'
                . ' color: ' . self::COLOR_SECONDARY . ';">' . self::e($headerNote) . '</td></tr></table>')
            . '</td></tr>'
            . '<tr><td class="vp-pad" style="padding: 20px 40px 0 40px;">'
            . '<h1 style="margin: 0 0 16px 0; font-family: ' . $font . '; font-size: 24px;'
            . ' font-weight: 700; line-height: 1.3; letter-spacing: -0.01em; color: ' . self::COLOR_INK . ';">'
            . self::e($heading) . '</h1>'
            . implode('', $parts)
            . '</td></tr>'
            . $footer
            . '<tr><td style="padding: 20px 40px 36px 40px;"></td></tr>'
            . '</table>'
            . '</td></tr></table>';

        return self::document($heading, self::preheader($paragraphs), $sheet);
    }

    /**
     * Inbox preview line: the first paragraph that says something (a bare
     * greeting like "Hello," makes a useless preview), else the first one.
     *
     * @param list<string|MailBlock> $paragraphs
     */
    private static function preheader(array $paragraphs): string
    {
        $texts = array_map(static fn (string|MailBlock $p): string => $p instanceof MailBlock ? $p->text : $p, $paragraphs);
        foreach ($texts as $text) {
            if (mb_strlen(trim($text)) >= 20) { // skips short lines and the empty CTA slot
                return $text;
            }
        }

        return $texts[0] ?? '';
    }

    /**
     * A row of figure tiles as a fixed table (stacking would need media
     * queries that several clients drop; three narrow tiles fit 320px).
     */
    private static function statsHtml(MailBlock $block): string
    {
        $font  = self::FONT_STACK;
        $cells = [];
        foreach ($block->tiles as $i => $tile) {
            $cells[] = '<td valign="top" width="' . intdiv(100, max(1, count($block->tiles))) . '%" style="padding: 14px 12px;'
                . ' background-color: ' . self::COLOR_BG . '; border-radius: 8px;">'
                . '<p style="margin: 0; font-family: ' . $font . '; font-size: 24px; font-weight: 700; line-height: 1.2; color: ' . self::COLOR_INK . ';">'
                . self::e($tile['value']) . '</p>'
                . '<p style="margin: 4px 0 0 0; font-family: ' . $font . '; font-size: 13px; font-weight: 600; line-height: 1.35; color: ' . self::COLOR_INK . ';">'
                . self::e($tile['label']) . '</p>'
                . '<p style="margin: 0; font-family: ' . $font . '; font-size: 12px; line-height: 1.35; color: ' . self::COLOR_SECONDARY . ';">'
                . self::e($tile['sub']) . '</p></td>';
            if ($i < count($block->tiles) - 1) {
                $cells[] = '<td width="8" style="width: 8px; font-size: 0; line-height: 0;">&nbsp;</td>';
            }
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 8px 0;"><tr>'
            . implode('', $cells) . '</tr></table>';
    }

    private static function paragraphHtml(string|MailBlock $paragraph): string
    {
        $font = self::FONT_STACK;
        if (!$paragraph instanceof MailBlock) {
            return '<p style="margin: 0 0 16px 0; font-family: ' . $font . '; font-size: 16px;'
                . ' line-height: 1.6; color: ' . self::COLOR_INK . ';">'
                . self::e($paragraph) . '</p>';
        }

        $title = $paragraph->url === null
            ? self::e($paragraph->text)
            : '<a href="' . self::e($paragraph->url) . '" style="color: ' . self::COLOR_INK . '; text-decoration: underline;'
                . ' text-decoration-color: ' . self::COLOR_RULE . '; text-underline-offset: 3px;">' . self::e($paragraph->text) . '</a>';

        return match ($paragraph->style) {
            MailBlock::SUBHEADING => '<p style="margin: 28px 0 12px 0; padding-top: 24px; border-top: 1px solid ' . self::COLOR_RULE . ';'
                . ' font-family: ' . $font . '; font-size: 18px; font-weight: 700; line-height: 1.4; color: ' . self::COLOR_INK . ';">'
                . $title . '</p>',
            MailBlock::LABEL => '<p style="margin: 20px 0 0 0; font-family: ' . $font . '; font-size: 12px; font-weight: 600;'
                . ' line-height: 1.4; letter-spacing: 0.06em; text-transform: uppercase; color: ' . self::COLOR_SECONDARY . ';">'
                . self::e($paragraph->text) . '</p>',
            MailBlock::IDEA => '<p style="margin: 0; padding: 12px 0; border-top: 1px solid ' . self::COLOR_RULE . ';'
                . ' font-family: ' . $font . '; font-size: 15px; font-weight: 600; line-height: 1.45; color: ' . self::COLOR_INK . ';">'
                . $title
                . '<br><span style="font-size: 13px; font-weight: 400; color: ' . self::COLOR_SECONDARY . ';">'
                . self::e($paragraph->meta) . '</span></p>',
            MailBlock::STATS => self::statsHtml($paragraph),
            MailBlock::META => '<p style="margin: 0 0 14px 0; font-family: ' . $font . '; font-size: 14px;'
                . ' line-height: 1.5; color: ' . self::COLOR_SECONDARY . ';">'
                . self::e($paragraph->text) . '</p>',
            default => '<p style="margin: 0 0 8px 0; padding-left: 12px; border-left: 3px solid ' . self::COLOR_RULE . ';'
                . ' font-family: ' . $font . '; font-size: 15px; line-height: 1.5; color: ' . self::COLOR_INK . ';">'
                . self::e($paragraph->text) . '</p>',
        };
    }

    /**
     * Wraps the sheet in a full document: charset, viewport, the light-only
     * colour scheme (meta + CSS, Apple Mail reads both) and the preheader.
     */
    private static function document(string $title, string $preheader, string $sheet): string
    {
        $hiddenPreheader = $preheader === '' ? '' : '<div style="display: none; max-height: 0; overflow: hidden;'
            . ' mso-hide: all; font-size: 1px; line-height: 1px; color: ' . self::COLOR_BG . '; opacity: 0;">'
            . self::e($preheader) . '</div>';

        return '<!DOCTYPE html><html><head>'
            . '<meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="color-scheme" content="light only">'
            . '<meta name="supported-color-schemes" content="light only">'
            . '<title>' . self::e($title) . '</title>'
            . '<style>:root { color-scheme: light only; supported-color-schemes: light only; }'
            . ' body { margin: 0; padding: 0; background-color: ' . self::COLOR_BG . '; }'
            . ' @media (max-width: 600px) { .vp-desk { padding: 16px 8px !important; }'
            . ' .vp-pad { padding-left: 24px !important; padding-right: 24px !important; } }</style>'
            . '</head><body style="margin: 0; padding: 0; background-color: ' . self::COLOR_BG . ';">'
            . $hiddenPreheader
            . $sheet
            . '</body></html>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
