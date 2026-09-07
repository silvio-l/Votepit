<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Validates AND re-encodes an uploaded avatar image (profile-avatar-social).
 *
 * CLAUDE.md §🔒 "no active user content": an uploaded file is treated as
 * hostile input regardless of its claimed filename/content-type. This class
 * NEVER writes the raw uploaded bytes anywhere — the only thing that ever
 * reaches disk is the output of decode() → cover-crop-to-square() →
 * re-encode(), which:
 *   - strips EXIF/metadata as a side effect of re-encoding (GD never copies
 *     the source's metadata into a freshly created true-color image);
 *   - neutralizes any polyglot/embedded-payload attack (a JPEG-with-
 *     appended-PHP or similar trick decodes fine but re-encodes to pixels
 *     only — the appended bytes are simply not part of the decoded image
 *     and are dropped);
 *   - structurally rejects SVG (GD has no SVG decoder — imagecreatefromstring
 *     returns false for it, same code path as "not a decodable raster image").
 *
 * Real image-type sniffing: getimagesize() inspects the actual byte
 * signature, not the client-supplied filename/Content-Type header, and is
 * checked BEFORE ext-gd ever touches the bytes.
 */
final class AvatarProcessor
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB

    public const OUTPUT_SIZE = 256; // px, square

    private const ALLOWED_IMAGETYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /**
     * Decodes, cover-crops to a 256x256 square, and re-encodes as JPEG
     * (quality 85). Returns null on ANY validation/decode failure — the
     * caller must treat null as "reject the upload", never fall back to
     * storing the original bytes.
     */
    public function process(string $rawBytes): ?string
    {
        if ($rawBytes === '' || strlen($rawBytes) > self::MAX_UPLOAD_BYTES) {
            return null;
        }

        // getimagesizefromstring() sniffs the real byte signature — an
        // SVG (XML text, no magic-number image header) or any non-image
        // file fails this check regardless of its claimed extension/MIME.
        $info = @getimagesizefromstring($rawBytes);
        if ($info === false || !in_array($info[2], self::ALLOWED_IMAGETYPES, true)) {
            return null;
        }

        $source = @imagecreatefromstring($rawBytes);
        if ($source === false) {
            return null;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);

        // Cover-crop: take the largest centered square from the source,
        // then scale it to the fixed output size.
        $cropSize = min($srcW, $srcH);
        $cropX    = intdiv($srcW - $cropSize, 2);
        $cropY    = intdiv($srcH - $cropSize, 2);

        $dest = imagecreatetruecolor(self::OUTPUT_SIZE, self::OUTPUT_SIZE);
        if ($dest === false) {
            return null;
        }

        imagecopyresampled(
            $dest,
            $source,
            0,
            0,
            $cropX,
            $cropY,
            self::OUTPUT_SIZE,
            self::OUTPUT_SIZE,
            $cropSize,
            $cropSize,
        );

        ob_start();
        $ok = imagejpeg($dest, null, 85);
        $encoded = ob_get_clean();

        if (!$ok || !is_string($encoded) || $encoded === '') {
            return null;
        }

        return $encoded;
    }

    /** Fixed extension for process()'s output — never derived from user input. */
    public function outputExtension(): string
    {
        return 'jpg';
    }

    /** Fixed Content-Type for process()'s output — the ONLY value ever served for an avatar. */
    public function outputContentType(): string
    {
        return 'image/jpeg';
    }
}
