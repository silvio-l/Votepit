<?php

declare(strict_types=1);

namespace Votepit\Mail;

/**
 * A styled content block for MailTemplate::render(), next to plain string
 * paragraphs. Only a fixed set of styles exists — every text is plaintext
 * and is escaped by MailTemplate like any paragraph; URLs are escaped too.
 *
 *  - subheading: a section title (e.g. one board in the weekly digest),
 *    separated from the previous section by a hairline rule, optionally linked
 *  - meta:       a muted, smaller line (figures, dates)
 *  - item:       one entry of a compact list (highlights), "- " in plaintext
 *  - label:      a small uppercase section label ("Ideas to watch")
 *  - stats:      a row of figure tiles (big value, label, muted sub-label)
 *  - idea:       one linked row: title on its own line, muted meta below
 *  - cta:        where the mail's single button goes (default: after all blocks)
 */
final readonly class MailBlock
{
    public const SUBHEADING = 'subheading';
    public const META       = 'meta';
    public const ITEM       = 'item';
    public const LABEL      = 'label';
    public const STATS      = 'stats';
    public const IDEA       = 'idea';
    public const CTA        = 'cta';

    /**
     * @param list<array{value: string, label: string, sub: string}> $tiles stats tiles only
     */
    private function __construct(
        public string $style,
        public string $text,
        public ?string $url = null,
        public string $meta = '',
        public array $tiles = [],
    ) {}

    public static function subheading(string $text, ?string $url = null): self
    {
        return new self(self::SUBHEADING, $text, $url);
    }

    public static function meta(string $text): self
    {
        return new self(self::META, $text);
    }

    public static function item(string $text): self
    {
        return new self(self::ITEM, $text);
    }

    public static function label(string $text): self
    {
        return new self(self::LABEL, $text);
    }

    /**
     * @param list<array{value: string, label: string, sub: string}> $tiles
     * @param string $text the plaintext rendering of the whole row
     */
    public static function stats(array $tiles, string $text): self
    {
        return new self(self::STATS, $text, tiles: $tiles);
    }

    public static function idea(string $title, string $meta, ?string $url = null): self
    {
        return new self(self::IDEA, $title, $url, $meta);
    }

    public static function cta(): self
    {
        return new self(self::CTA, '');
    }
}
