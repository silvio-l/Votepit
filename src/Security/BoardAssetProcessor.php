<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Validates AND re-encodes a board image upload (cover, favicon, share image).
 *
 * Same hostile-input discipline as AvatarProcessor (see its class doc): the
 * uploaded bytes never reach disk. Only the output of decode -> centred
 * crop to a fixed ratio -> resample -> re-encode is ever stored, which
 *   - strips every metadata chunk / EXIF / comment (GD never copies them);
 *   - drops polyglot payloads appended after the image data;
 *   - rejects SVG, HTML and every other non-raster input (no GD decoder);
 *   - rejects oversized dimensions BEFORE decoding (decompression-bomb guard:
 *     getimagesizefromstring() reads only the header, so the pixel budget is
 *     enforced without allocating the bitmap).
 *
 * Output geometry is fixed server-side (VARIANTS). A cover upload yields a
 * 6:1 desktop crop (2400x400) and a 6:1 phone crop (1200x200), matching the
 * shared cover band (ds-board-cover, 56-160 px high at every width); the
 * favicon is a 1:1 PNG (keeps transparency); the share image is 1200x630
 * (og:image). The logo is a 256x256
 * PNG: the whole logo is fitted ("contain") into the square on a transparent
 * background, never cropped.
 */
final readonly class BoardAssetProcessor
{
    public const KIND_COVER   = 'cover';
    public const KIND_FAVICON = 'favicon';
    public const KIND_OG      = 'og';
    public const KIND_LOGO    = 'logo';
    public const KIND_WORDMARK = 'wordmark';
    /** Optional light wordmark for dark topbars (phase 4); same geometry and gate as the wordmark. */
    public const KIND_WORDMARK_LIGHT = 'wordmark_light';

    /** @var list<string> */
    public const KINDS = [self::KIND_COVER, self::KIND_FAVICON, self::KIND_OG, self::KIND_LOGO, self::KIND_WORDMARK, self::KIND_WORDMARK_LIGHT];

    public const VARIANT_COVER_WIDE  = 'cover_wide';
    public const VARIANT_COVER_PHONE = 'cover_phone';
    public const VARIANT_FAVICON     = 'favicon';
    /** Home-screen icon (apple-touch-icon), rendered from the favicon upload. */
    public const VARIANT_TOUCH_ICON  = 'touch_icon';
    public const VARIANT_OG          = 'og';
    public const VARIANT_LOGO        = 'logo';
    public const VARIANT_WORDMARK    = 'wordmark';
    public const VARIANT_WORDMARK_LIGHT = 'wordmark_light';

    /**
     * variant => fixed output geometry and format (never user-controlled).
     * `fit` is 'cover' (centred crop) or 'contain' (whole source, letterboxed
     * on a transparent square).
     */
    public const VARIANTS = [
        self::VARIANT_COVER_WIDE  => ['width' => 2400, 'height' => 400, 'format' => 'jpeg', 'fit' => 'cover'],
        self::VARIANT_COVER_PHONE => ['width' => 1200, 'height' => 200, 'format' => 'jpeg', 'fit' => 'cover'],
        self::VARIANT_FAVICON     => ['width' => 64, 'height' => 64, 'format' => 'png', 'fit' => 'cover'],
        self::VARIANT_TOUCH_ICON  => ['width' => 180, 'height' => 180, 'format' => 'png', 'fit' => 'cover'],
        self::VARIANT_OG          => ['width' => 1200, 'height' => 630, 'format' => 'jpeg', 'fit' => 'cover'],
        self::VARIANT_LOGO        => ['width' => 256, 'height' => 256, 'format' => 'png', 'fit' => 'contain'],
        self::VARIANT_WORDMARK    => ['width' => 1200, 'height' => 300, 'format' => 'png', 'fit' => 'contain'],
        self::VARIANT_WORDMARK_LIGHT => ['width' => 1200, 'height' => 300, 'format' => 'png', 'fit' => 'contain'],
    ];

    /** kind => variants it produces. */
    public const KIND_VARIANTS = [
        self::KIND_COVER   => [self::VARIANT_COVER_WIDE, self::VARIANT_COVER_PHONE],
        self::KIND_FAVICON => [self::VARIANT_FAVICON, self::VARIANT_TOUCH_ICON],
        self::KIND_OG      => [self::VARIANT_OG],
        self::KIND_LOGO    => [self::VARIANT_LOGO],
        self::KIND_WORDMARK => [self::VARIANT_WORDMARK],
        self::KIND_WORDMARK_LIGHT => [self::VARIANT_WORDMARK_LIGHT],
    ];

    /** Upload limit per kind, in bytes. Kinds not listed use the instance limit. */
    public const KIND_MAX_BYTES = [
        self::KIND_LOGO     => 2 * 1024 * 1024,
        self::KIND_WORDMARK => 2 * 1024 * 1024,
        self::KIND_WORDMARK_LIGHT => 2 * 1024 * 1024,
    ];

    /** Largest accepted source side, in px. */
    public const MAX_SIDE = 6000;
    /** Largest accepted source area, in px (≈ 4000x3000). */
    public const MAX_PIXELS = 12_000_000;
    /** Smallest accepted source side, in px (a crop below this is too blurry to use). */
    public const MIN_SIDE = 200;
    /** Smallest accepted source side per kind, in px. Kinds not listed use MIN_SIDE. */
    public const KIND_MIN_SIDE = [
        // 180 px is the common home-screen icon size; the largest rendition is 180 px.
        self::KIND_FAVICON => 180,
    ];

    public const JPEG_QUALITY = 85;

    public function __construct(
        private int $maxUploadBytes = 4 * 1024 * 1024,
    ) {}

    public function maxUploadBytes(): int
    {
        return $this->maxUploadBytes;
    }

    /** Upload limit for one kind: the kind's own limit, capped by the instance limit. */
    public function maxUploadBytesFor(string $kind): int
    {
        $kindLimit = self::KIND_MAX_BYTES[$kind] ?? $this->maxUploadBytes;

        return min($kindLimit, $this->maxUploadBytes);
    }

    public static function minSideFor(string $kind): int
    {
        return self::KIND_MIN_SIDE[$kind] ?? self::MIN_SIDE;
    }

    /**
     * Why an upload is rejected, for the user-facing message only. Null when
     * the file looks acceptable here (the caller then gets the generic
     * rejection from process(), which stays authoritative). Only file
     * properties are named, never server details.
     *
     * @return 'file_too_large'|'unsupported_format'|'image_too_small'|'image_too_large'|null
     */
    public function rejectionReason(string $rawBytes, string $kind): ?string
    {
        if (strlen($rawBytes) > $this->maxUploadBytesFor($kind)) {
            return 'file_too_large';
        }
        $info = $rawBytes === '' ? false : @getimagesizefromstring($rawBytes);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return 'unsupported_format';
        }
        if ($info[0] < self::minSideFor($kind) || $info[1] < self::minSideFor($kind)) {
            return 'image_too_small';
        }
        if ($info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE || $info[0] * $info[1] > self::MAX_PIXELS) {
            return 'image_too_large';
        }

        return null;
    }

    /**
     * @return list<BoardAssetRendition>|null null on ANY failure — the caller
     *                                        must reject the upload, never
     *                                        fall back to the original bytes.
     */
    public function process(string $rawBytes, string $kind): ?array
    {
        if (!in_array($kind, self::KINDS, true)) {
            return null;
        }
        if ($rawBytes === '' || strlen($rawBytes) > $this->maxUploadBytesFor($kind)) {
            return null;
        }

        // Header-only sniff of the REAL byte signature (never the client's
        // filename/Content-Type). SVG, HTML and plain text have no image
        // header and fail here.
        $info = @getimagesizefromstring($rawBytes);
        if ($info === false) {
            return null;
        }
        if (!in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return null;
        }

        [$srcW, $srcH] = [$info[0], $info[1]];
        if ($srcW < self::minSideFor($kind) || $srcH < self::minSideFor($kind)) {
            return null;
        }
        if ($srcW > self::MAX_SIDE || $srcH > self::MAX_SIDE || $srcW * $srcH > self::MAX_PIXELS) {
            return null;
        }

        $source = @imagecreatefromstring($rawBytes);
        if ($source === false) {
            return null;
        }

        $renditions = [];
        foreach (self::KIND_VARIANTS[$kind] as $variant) {
            $rendition = $this->render($source, $srcW, $srcH, $variant);
            if (!$rendition instanceof \Votepit\Security\BoardAssetRendition) {
                return null;
            }
            $renditions[] = $rendition;
        }

        return $renditions;
    }

    private function render(\GdImage $source, int $srcW, int $srcH, string $variant): ?BoardAssetRendition
    {
        $spec   = self::VARIANTS[$variant];
        $tw     = $spec['width'];
        $th     = $spec['height'];
        $isPng  = $spec['format'] === 'png';

        $dest = imagecreatetruecolor($tw, $th);
        if ($isPng) {
            imagealphablending($dest, false);
            imagesavealpha($dest, true);
            $clear = imagecolorallocatealpha($dest, 0, 0, 0, 127);
            if ($clear === false) {
                return null;
            }
            imagefill($dest, 0, 0, $clear);
        } else {
            // Flatten onto white: a transparent PNG source must not turn black in JPEG.
            $white = imagecolorallocate($dest, 255, 255, 255);
            if ($white === false) {
                return null;
            }
            imagefill($dest, 0, 0, $white);
        }
        imagealphablending($dest, true);

        if ($spec['fit'] === 'contain') {
            // Whole source, scaled to fit the square and centred; the rest stays transparent.
            $scale = min($tw / $srcW, $th / $srcH);
            $dw    = max(1, (int) round($srcW * $scale));
            $dh    = max(1, (int) round($srcH * $scale));
            imagecopyresampled($dest, $source, intdiv($tw - $dw, 2), intdiv($th - $dh, 2), 0, 0, $dw, $dh, $srcW, $srcH);
        } else {
            [$x, $y, $cw, $ch] = $this->centredCrop($srcW, $srcH, $tw / $th);
            imagecopyresampled($dest, $source, 0, 0, $x, $y, $tw, $th, $cw, $ch);
        }

        $ink = $this->inkLuminance($dest, $tw, $th);

        ob_start();
        $ok = $isPng ? imagepng($dest, null, 9) : imagejpeg($dest, null, self::JPEG_QUALITY);
        $encoded = ob_get_clean();

        if (!$ok || !is_string($encoded) || $encoded === '') {
            return null;
        }

        return $isPng
            ? new BoardAssetRendition($variant, $encoded, 'image/png', 'png', $tw, $th, $ink)
            : new BoardAssetRendition($variant, $encoded, 'image/jpeg', 'jpg', $tw, $th, $ink);
    }

    /**
     * Mean WCAG relative luminance of the visible pixels (alpha below half),
     * sampled on a grid of at most ~128 steps per side. A heuristic for the
     * app's contrast check against the topbar colour, not a colour analysis.
     * Null when the rendition has no visible pixel.
     */
    private function inkLuminance(\GdImage $image, int $width, int $height): ?float
    {
        $step = max(1, intdiv(max($width, $height), 128));
        $sum  = 0.0;
        $n    = 0;
        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                $colour = imagecolorat($image, $x, $y);
                // GD alpha: 0 = opaque, 127 = fully transparent.
                if ((($colour >> 24) & 0x7F) >= 64) {
                    continue;
                }
                $sum += $this->relativeLuminance(($colour >> 16) & 0xFF, ($colour >> 8) & 0xFF, $colour & 0xFF);
                $n++;
            }
        }

        return $n === 0 ? null : round($sum / $n, 3);
    }

    /** WCAG 2.x relative luminance of one sRGB colour, 0 (black) to 1 (white). */
    private function relativeLuminance(int $r, int $g, int $b): float
    {
        $lin = static function (int $c): float {
            $v = $c / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    /**
     * Largest centred box with the given width/height ratio inside the source.
     *
     * @return array{int, int, int, int} x, y, width, height
     */
    private function centredCrop(int $srcW, int $srcH, float $ratio): array
    {
        if ($srcW / $srcH > $ratio) {
            $cropH = $srcH;
            $cropW = (int) round($srcH * $ratio);
        } else {
            $cropW = $srcW;
            $cropH = (int) round($srcW / $ratio);
        }

        return [
            intdiv($srcW - $cropW, 2),
            intdiv($srcH - $cropH, 2),
            max(1, $cropW),
            max(1, $cropH),
        ];
    }
}
