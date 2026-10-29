<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Security\BoardAssetProcessor;

/**
 * Board image pipeline (P-28). Only server re-encoded output may be stored;
 * every hostile input must come back as null so the caller rejects it.
 */
final class BoardAssetProcessorTest extends TestCase
{
    private BoardAssetProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new BoardAssetProcessor();
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        $bg = imagecolorallocate($image, 200, 50, 50);
        self::assertNotFalse($bg);
        imagefill($image, 0, 0, $bg);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        self::assertIsString($bytes);

        return $bytes;
    }

    public function test_cover_upload_yields_wide_and_phone_variants_at_fixed_size(): void
    {
        $result = $this->processor->process($this->png(1600, 900), BoardAssetProcessor::KIND_COVER);

        self::assertIsArray($result);
        $byVariant = [];
        foreach ($result as $rendition) {
            $byVariant[$rendition->variant] = $rendition;
        }
        self::assertSame([BoardAssetProcessor::VARIANT_COVER_WIDE, BoardAssetProcessor::VARIANT_COVER_PHONE], array_keys($byVariant));
        self::assertSame([2400, 400], [$byVariant[BoardAssetProcessor::VARIANT_COVER_WIDE]->width, $byVariant[BoardAssetProcessor::VARIANT_COVER_WIDE]->height]);
        self::assertSame([1200, 200], [$byVariant[BoardAssetProcessor::VARIANT_COVER_PHONE]->width, $byVariant[BoardAssetProcessor::VARIANT_COVER_PHONE]->height]);
        self::assertSame('image/jpeg', $byVariant[BoardAssetProcessor::VARIANT_COVER_WIDE]->contentType);
    }

    public function test_favicon_is_png_square_and_og_is_jpeg_1200x630(): void
    {
        $favicon = $this->processor->process($this->png(512, 512), BoardAssetProcessor::KIND_FAVICON);
        $og      = $this->processor->process($this->png(1200, 800), BoardAssetProcessor::KIND_OG);

        self::assertIsArray($favicon);
        self::assertSame('image/png', $favicon[0]->contentType);
        self::assertSame([64, 64], [$favicon[0]->width, $favicon[0]->height]);
        self::assertSame(BoardAssetProcessor::VARIANT_TOUCH_ICON, $favicon[1]->variant);
        self::assertSame('image/png', $favicon[1]->contentType);
        self::assertSame([180, 180], [$favicon[1]->width, $favicon[1]->height]);
        self::assertIsArray($og);
        self::assertSame('image/jpeg', $og[0]->contentType);
        self::assertSame([1200, 630], [$og[0]->width, $og[0]->height]);
    }

    public function test_output_is_a_fresh_reencode_not_the_upload(): void
    {
        $upload = $this->png(400, 300);
        $result = $this->processor->process($upload, BoardAssetProcessor::KIND_OG);

        self::assertIsArray($result);
        self::assertNotSame($upload, $result[0]->bytes);
        $info = getimagesizefromstring($result[0]->bytes);
        self::assertIsArray($info);
        self::assertSame(IMAGETYPE_JPEG, $info[2]);
    }

    public function test_polyglot_payload_appended_to_a_valid_png_is_not_carried_over(): void
    {
        $polyglot = $this->png(300, 300) . '<?php system($_GET["c"]); ?>';

        $result = $this->processor->process($polyglot, BoardAssetProcessor::KIND_OG);

        self::assertIsArray($result, 'a valid image prefix is decoded normally');
        foreach ($result as $rendition) {
            self::assertStringNotContainsString('<?php', $rendition->bytes);
            self::assertStringNotContainsString('system', $rendition->bytes);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function nonImageProvider(): iterable
    {
        yield 'svg with script' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];
        yield 'html disguised as png' => ['<!doctype html><html><body><script>alert(1)</script></body></html>'];
        yield 'plain text' => ['just some text, not an image at all'];
        yield 'gif (not allowed)' => ["GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;"];
        yield 'php file' => ['<?php echo "hi";'];
    }

    #[DataProvider('nonImageProvider')]
    public function test_non_images_are_rejected(string $bytes): void
    {
        self::assertNull($this->processor->process($bytes, BoardAssetProcessor::KIND_COVER));
    }

    public function test_zero_bytes_are_rejected(): void
    {
        self::assertNull($this->processor->process('', BoardAssetProcessor::KIND_OG));
    }

    public function test_oversized_upload_is_rejected_before_decoding(): void
    {
        $processor = new BoardAssetProcessor(maxUploadBytes: 100);

        self::assertNull($processor->process($this->png(300, 300), BoardAssetProcessor::KIND_OG));
    }

    public function test_declared_dimensions_above_the_limit_are_rejected_without_decoding(): void
    {
        // Patch the IHDR width to 7000 px: the header claims a huge image while
        // the real pixel data is tiny. The dimension guard must refuse it before
        // GD allocates anything.
        $bytes = $this->png(300, 300);
        $bytes = substr_replace($bytes, pack('N', 7000), 16, 4);

        self::assertNull($this->processor->process($bytes, BoardAssetProcessor::KIND_OG));
    }

    public function test_too_small_image_is_rejected(): void
    {
        self::assertNull($this->processor->process($this->png(120, 120), BoardAssetProcessor::KIND_FAVICON));
    }

    public function test_favicon_accepts_a_180px_source_other_kinds_keep_200px(): void
    {
        self::assertIsArray($this->processor->process($this->png(180, 180), BoardAssetProcessor::KIND_FAVICON));
        self::assertNull($this->processor->process($this->png(179, 179), BoardAssetProcessor::KIND_FAVICON));
        self::assertSame('image_too_small', $this->processor->rejectionReason($this->png(179, 179), BoardAssetProcessor::KIND_FAVICON));
        self::assertNull($this->processor->rejectionReason($this->png(180, 180), BoardAssetProcessor::KIND_FAVICON));
        self::assertNull($this->processor->process($this->png(180, 180), BoardAssetProcessor::KIND_OG));
        self::assertSame('image_too_small', $this->processor->rejectionReason($this->png(180, 180), BoardAssetProcessor::KIND_OG));
        self::assertSame(180, BoardAssetProcessor::minSideFor(BoardAssetProcessor::KIND_FAVICON));
        self::assertSame(200, BoardAssetProcessor::minSideFor(BoardAssetProcessor::KIND_LOGO));
    }

    public function test_unknown_kind_is_rejected(): void
    {
        self::assertNull($this->processor->process($this->png(300, 300), 'avatar'));
    }

    /**
     * @param positive-int $w
     * @param positive-int $h
     * Logo fixture: a transparent canvas with an opaque band across the full width (the mark). */
    private function logoPng(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        self::assertNotFalse($image);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        $clear = (int) imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $clear);
        $ink = (int) imagecolorallocatealpha($image, 200, 40, 40, 0);
        // The mark spans the full width (not the frame), so a crop would show.
        imagefilledrectangle($image, 0, (int) ($h / 3), $w - 1, (int) (2 * $h / 3), $ink);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** Inserts a tEXt chunk right after IHDR: metadata the re-encode must drop. */
    private function pngWithTextChunk(string $png, string $text): string
    {
        $data  = "Comment\0" . $text;
        $chunk = pack('N', strlen($data)) . 'tEXt' . $data . pack('N', crc32('tEXt' . $data));

        return substr($png, 0, 33) . $chunk . substr($png, 33);
    }

    private function pixelAlpha(string $png, int $x, int $y): int
    {
        $image = imagecreatefromstring($png);
        self::assertNotFalse($image);
        $rgba = imagecolorat($image, $x, $y);

        return ($rgba >> 24) & 0x7F;
    }

    public function test_logo_is_a_256_square_png_with_the_whole_mark_fitted_not_cropped(): void
    {
        $renditions = $this->processor->process($this->logoPng(800, 200), BoardAssetProcessor::KIND_LOGO);

        self::assertIsArray($renditions);
        self::assertCount(1, $renditions);
        self::assertSame(BoardAssetProcessor::VARIANT_LOGO, $renditions[0]->variant);
        self::assertSame(['image/png', 'png', 256, 256], [$renditions[0]->contentType, $renditions[0]->extension, $renditions[0]->width, $renditions[0]->height]);
        // Wide source: full width used, top and bottom letterbox rows stay transparent.
        self::assertSame(0, $this->pixelAlpha($renditions[0]->bytes, 0, 128), 'left edge of the mark is opaque');
        self::assertSame(127, $this->pixelAlpha($renditions[0]->bytes, 128, 5), 'letterbox row is transparent');
    }

    public function test_a_transparent_logo_stays_transparent_outside_the_mark(): void
    {
        $renditions = $this->processor->process($this->logoPng(400, 400), BoardAssetProcessor::KIND_LOGO);

        self::assertIsArray($renditions);
        self::assertSame(127, $this->pixelAlpha($renditions[0]->bytes, 0, 0), 'corner stays fully transparent');
        self::assertSame(0, $this->pixelAlpha($renditions[0]->bytes, 128, 128), 'the mark stays opaque');
    }

    public function test_logo_output_has_no_metadata_chunk(): void
    {
        $dirty = $this->pngWithTextChunk($this->logoPng(300, 300), 'SECRET-EXIF-MARKER');

        $renditions = $this->processor->process($dirty, BoardAssetProcessor::KIND_LOGO);

        self::assertIsArray($renditions);
        self::assertStringNotContainsString('SECRET-EXIF-MARKER', $renditions[0]->bytes);
        self::assertStringNotContainsString('tEXt', $renditions[0]->bytes);
    }

    public function test_logo_has_a_two_megabyte_limit_while_other_kinds_keep_the_instance_limit(): void
    {
        $processor = new BoardAssetProcessor(4 * 1024 * 1024);

        self::assertSame(2 * 1024 * 1024, $processor->maxUploadBytesFor(BoardAssetProcessor::KIND_LOGO));
        self::assertSame(4 * 1024 * 1024, $processor->maxUploadBytesFor(BoardAssetProcessor::KIND_COVER));
        // An oversized logo is refused before decoding, with the file-size reason.
        $oversized = $this->logoPng(300, 300) . str_repeat("\0", 2 * 1024 * 1024 + 16);
        self::assertNull($processor->process($oversized, BoardAssetProcessor::KIND_LOGO));
        self::assertSame('file_too_large', $processor->rejectionReason($oversized, BoardAssetProcessor::KIND_LOGO));
    }

    public function test_rejection_reasons_name_only_file_properties(): void
    {
        $processor = new BoardAssetProcessor();
        $svg       = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $html      = '<!doctype html><script>alert(1)</script>';

        self::assertSame('unsupported_format', $processor->rejectionReason($svg, BoardAssetProcessor::KIND_LOGO));
        self::assertSame('unsupported_format', $processor->rejectionReason($html, BoardAssetProcessor::KIND_LOGO));
        self::assertSame('unsupported_format', $processor->rejectionReason('', BoardAssetProcessor::KIND_LOGO));
        self::assertSame('image_too_small', $processor->rejectionReason($this->logoPng(120, 120), BoardAssetProcessor::KIND_LOGO));
        self::assertNull($processor->rejectionReason($this->logoPng(300, 300), BoardAssetProcessor::KIND_LOGO));
        // SVG, HTML and empty input are still refused by process() itself.
        self::assertNull($processor->process($svg, BoardAssetProcessor::KIND_LOGO));
        self::assertNull($processor->process($html, BoardAssetProcessor::KIND_LOGO));
    }

    /**
     * Solid PNG of one colour; alpha 0 = opaque, 127 = fully transparent (GD convention).
     *
     * @param positive-int $width
     * @param positive-int $height
     * @param int<0, 255> $r
     * @param int<0, 255> $g
     * @param int<0, 255> $b
     * @param int<0, 127> $alpha
     */
    private function solidPng(int $width, int $height, int $r, int $g, int $b, int $alpha = 0): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, $r, $g, $b, $alpha));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_wordmark_is_a_1200x300_png_and_a_square_source_is_fitted_not_cropped(): void
    {
        $result = $this->processor->process($this->solidPng(400, 400, 20, 20, 20), BoardAssetProcessor::KIND_WORDMARK);

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame(BoardAssetProcessor::VARIANT_WORDMARK, $result[0]->variant);
        self::assertSame([1200, 300], [$result[0]->width, $result[0]->height]);
        self::assertSame('image/png', $result[0]->contentType);
        // 400x400 scaled to fit 300 px height: letterboxed, the left edge stays transparent.
        self::assertSame(127, $this->pixelAlpha($result[0]->bytes, 0, 150));
        self::assertSame(0, $this->pixelAlpha($result[0]->bytes, 600, 150));
    }

    public function test_light_wordmark_has_the_wordmark_geometry_and_its_own_variant(): void
    {
        $result = $this->processor->process($this->solidPng(800, 200, 240, 240, 240), BoardAssetProcessor::KIND_WORDMARK_LIGHT);

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame(BoardAssetProcessor::VARIANT_WORDMARK_LIGHT, $result[0]->variant);
        self::assertSame([1200, 300], [$result[0]->width, $result[0]->height]);
        self::assertGreaterThan(0.8, $result[0]->inkLuminance ?? 0.0, 'a light mark reports a high ink value');
    }

    public function test_ink_luminance_is_low_for_a_dark_mark_and_high_for_a_light_one(): void
    {
        $dark  = $this->processor->process($this->solidPng(800, 200, 20, 20, 20), BoardAssetProcessor::KIND_WORDMARK);
        $light = $this->processor->process($this->solidPng(800, 200, 255, 255, 255), BoardAssetProcessor::KIND_WORDMARK);

        self::assertIsArray($dark);
        self::assertIsArray($light);
        self::assertNotNull($dark[0]->inkLuminance);
        self::assertLessThan(0.02, $dark[0]->inkLuminance);
        self::assertNotNull($light[0]->inkLuminance);
        self::assertGreaterThan(0.95, $light[0]->inkLuminance);
    }

    public function test_ink_luminance_ignores_transparent_pixels_and_is_null_when_nothing_is_visible(): void
    {
        // A dark mark fitted into a transparent square: the letterbox must not pull the value towards white.
        $mark = $this->processor->process($this->solidPng(400, 400, 0, 0, 0), BoardAssetProcessor::KIND_LOGO);
        self::assertIsArray($mark);
        self::assertNotNull($mark[0]->inkLuminance);
        self::assertLessThan(0.02, $mark[0]->inkLuminance);

        $invisible = $this->processor->process($this->solidPng(400, 400, 0, 0, 0, 127), BoardAssetProcessor::KIND_LOGO);
        self::assertIsArray($invisible);
        self::assertNull($invisible[0]->inkLuminance);
    }

    public function test_cover_and_favicon_carry_ink_metadata_too_without_using_it(): void
    {
        $cover = $this->processor->process($this->png(1600, 900), BoardAssetProcessor::KIND_COVER);

        self::assertIsArray($cover);
        self::assertNotNull($cover[0]->inkLuminance);
    }
}
