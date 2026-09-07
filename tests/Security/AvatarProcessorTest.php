<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\AvatarProcessor;

/**
 * Unit tests for AvatarProcessor (profile-avatar-social) — the ONE place
 * uploaded avatar bytes are ever decoded/re-encoded. Covers every hard
 * requirement from the ticket: SVG rejection, oversized-upload rejection,
 * non-image rejection, and that a valid image re-encodes into a fixed-size
 * square JPEG (which structurally strips any EXIF/metadata/polyglot payload
 * the source carried, since GD never copies metadata into a freshly created
 * true-color image).
 */
final class AvatarProcessorTest extends TestCase
{
    private AvatarProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new AvatarProcessor();
    }

    private function makePngBytes(int $width, int $height): string
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('width/height must be positive');
        }

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

    public function test_valid_png_is_reencoded_to_fixed_size_jpeg(): void
    {
        $bytes = $this->makePngBytes(400, 300); // non-square source → cover-crop path

        $result = $this->processor->process($bytes);

        self::assertIsString($result);
        $info = getimagesizefromstring($result);
        self::assertIsArray($info);
        self::assertSame(IMAGETYPE_JPEG, $info[2], 'output must always be JPEG regardless of source format');
        self::assertSame(AvatarProcessor::OUTPUT_SIZE, $info[0]);
        self::assertSame(AvatarProcessor::OUTPUT_SIZE, $info[1]);
    }

    public function test_svg_is_rejected(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>';

        self::assertNull($this->processor->process($svg));
    }

    public function test_svg_disguised_with_png_looking_bytes_is_still_rejected(): void
    {
        // A polyglot attempt: SVG content is what matters — GD has no SVG
        // decoder, so this fails the same "not a decodable raster image"
        // path regardless of any surrounding bytes.
        $payload = "\x89PNG\r\n" . '<svg onload="alert(1)"></svg>';

        self::assertNull($this->processor->process($payload));
    }

    public function test_oversized_upload_is_rejected(): void
    {
        $oversized = str_repeat('a', AvatarProcessor::MAX_UPLOAD_BYTES + 1);

        self::assertNull($this->processor->process($oversized));
    }

    public function test_non_image_bytes_are_rejected(): void
    {
        self::assertNull($this->processor->process('this is definitely not an image'));
    }

    public function test_empty_input_is_rejected(): void
    {
        self::assertNull($this->processor->process(''));
    }

    public function test_corrupt_truncated_image_is_rejected(): void
    {
        $bytes = $this->makePngBytes(100, 100);

        self::assertNull($this->processor->process(substr($bytes, 0, intdiv(strlen($bytes), 2))));
    }

    public function test_php_script_appended_to_valid_image_does_not_survive_reencode(): void
    {
        // Polyglot GIF/PHP-style attack: valid image bytes with a PHP
        // payload appended. It decodes fine (GD only reads the image
        // structure), but the re-encoded output must contain none of the
        // appended bytes — this is the "strips embedded payloads" guarantee.
        $bytes   = $this->makePngBytes(64, 64);
        $payload = $bytes . '<?php system($_GET["c"]); ?>';

        $result = $this->processor->process($payload);

        self::assertIsString($result);
        self::assertStringNotContainsString('<?php', $result);
        self::assertStringNotContainsString('system(', $result);
    }

    public function test_output_content_type_is_always_jpeg(): void
    {
        self::assertSame('image/jpeg', $this->processor->outputContentType());
        self::assertSame('jpg', $this->processor->outputExtension());
    }
}
