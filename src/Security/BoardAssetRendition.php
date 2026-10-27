<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * One server-rendered output file of a board image upload. Every field is
 * produced by BoardAssetProcessor from fixed server-side values; nothing here
 * ever derives from the uploaded file's name, metadata or claimed type.
 */
final readonly class BoardAssetRendition
{
    public function __construct(
        public string $variant,
        public string $bytes,
        public string $contentType,
        public string $extension,
        public int $width,
        public int $height,
        /** Mean relative luminance of the visible pixels (0..1), or null when none are visible. */
        public ?float $inkLuminance = null,
    ) {}
}
