<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pfpms\Storage\ImageProcessor;

/** Picture cleaning (US-13, UC-05 §4.4): re-encode, strip metadata, scale down, refuse non-pictures. */
final class ImageProcessorTest extends TestCase
{
    public function testAPngIsReEncodedAsPngWithoutItsMetadataAndKeepsTransparency(): void
    {
        $png = self::withPngText(self::png(40, 30), 'Comment', 'secret-marker');
        $this->assertStringContainsString('secret-marker', $png, 'the test picture carries metadata');

        $out = ImageProcessor::sanitize($png);

        $this->assertSame('image/png', $out['mime']);
        $this->assertStringStartsWith("\x89PNG", $out['bytes']);
        $this->assertStringNotContainsString('secret-marker', $out['bytes']);
        $image = imagecreatefromstring($out['bytes']);
        $this->assertNotFalse($image);
        $this->assertSame([40, 30], [imagesx($image), imagesy($image)]);
        $this->assertSame(127, (imagecolorat($image, 0, 0) >> 24) & 0x7F, 'the transparent corner stays transparent');
    }

    public function testAJpegIsReEncodedAsJpegWithoutItsComments(): void
    {
        $jpeg = self::withJpegSegment(self::jpeg(50, 40), "\xFF\xFE", 'secret-marker');
        $this->assertStringContainsString('secret-marker', $jpeg);

        $out = ImageProcessor::sanitize($jpeg);

        $this->assertSame('image/jpeg', $out['mime']);
        $this->assertStringStartsWith("\xFF\xD8", $out['bytes']);
        $this->assertStringNotContainsString('secret-marker', $out['bytes']);
        $this->assertSame([50, 40, 'image/jpeg'], self::dimensions($out['bytes']));
    }

    public function testAWebpBecomesJpeg(): void
    {
        $image = imagecreatetruecolor(20, 10);
        ob_start();
        imagewebp($image);
        $webp = (string) ob_get_clean();

        $out = ImageProcessor::sanitize($webp);

        $this->assertSame('image/jpeg', $out['mime']);
        $this->assertSame([20, 10, 'image/jpeg'], self::dimensions($out['bytes']));
    }

    public function testALargePictureIsScaledToFitKeepingItsShape(): void
    {
        $out = ImageProcessor::sanitize(self::jpeg(3000, 1000));
        $this->assertSame([1600, 533, 'image/jpeg'], self::dimensions($out['bytes']));

        $tall = ImageProcessor::sanitize(self::png(500, 1000), 200);
        $this->assertSame([100, 200, 'image/png'], self::dimensions($tall['bytes']));
    }

    public function testTransparentAreasOfALargeWebpTurnWhiteNotBlack(): void
    {
        foreach ([[100, 50], [3200, 1600]] as [$width, $height]) { // kept size, and scaled down
            $image = imagecreatetruecolor($width, $height);
            imagealphablending($image, false);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
            ob_start();
            imagewebp($image, null, 100);
            $webp = (string) ob_get_clean();

            $out = imagecreatefromstring(ImageProcessor::sanitize($webp)['bytes']);
            $this->assertNotFalse($out);
            $corner = imagecolorsforindex($out, imagecolorat($out, 2, 2));
            $this->assertSame([255, 255, 255], [$corner['red'], $corner['green'], $corner['blue']], "{$width}x{$height}");
        }
    }

    public function testASidewaysPhoneJpegIsTurnedUpright(): void
    {
        if (!function_exists('exif_read_data')) {
            $this->markTestSkipped('ext-exif is not loaded');
        }
        $out = ImageProcessor::sanitize(self::withExifOrientation(self::jpeg(60, 20), 6));
        $this->assertSame([20, 60, 'image/jpeg'], self::dimensions($out['bytes']));

        $large = ImageProcessor::sanitize(self::withExifOrientation(self::jpeg(3000, 1000), 8));
        $this->assertSame([533, 1600, 'image/jpeg'], self::dimensions($large['bytes']), 'scaled, then turned');
    }

    public function testBytesThatAreNotAPictureAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImageProcessor::sanitize("<?php echo 'hello'; ?>");
    }

    public function testAPictureTypeThatIsNotAcceptedIsRefused(): void
    {
        ob_start();
        imagegif(imagecreatetruecolor(10, 10));
        $gif = (string) ob_get_clean();
        $this->expectException(InvalidArgumentException::class);
        ImageProcessor::sanitize($gif);
    }

    public function testACorruptPictureIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImageProcessor::sanitize(substr(self::jpeg(200, 200), 0, 200));
    }

    /** A PNG whose top-left corner is fully transparent and the rest solid red. */
    private static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 255, 0, 0, 0));
        imagesetpixel($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        return (string) ob_get_clean();
    }

    private static function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagejpeg($image);
        return (string) ob_get_clean();
    }

    /** @return array{0: int, 1: int, 2: string} width, height, type */
    private static function dimensions(string $bytes): array
    {
        $info = getimagesizefromstring($bytes);
        return [(int) $info[0], (int) $info[1], (string) $info['mime']];
    }

    /** Insert a tEXt chunk after IHDR (which is always the first chunk, 25 bytes after the signature). */
    private static function withPngText(string $png, string $key, string $text): string
    {
        $data = $key . "\0" . $text;
        $chunk = pack('N', strlen($data)) . 'tEXt' . $data . pack('N', crc32('tEXt' . $data));
        return substr($png, 0, 33) . $chunk . substr($png, 33);
    }

    /** Insert a JPEG segment straight after the start-of-image marker. */
    private static function withJpegSegment(string $jpeg, string $marker, string $payload): string
    {
        return substr($jpeg, 0, 2) . $marker . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    /** Add a minimal EXIF block holding only the Orientation tag. */
    private static function withExifOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "II*\0" . pack('V', 8)           // little-endian TIFF header, first IFD at offset 8
            . pack('v', 1)                          // one entry
            . pack('vvVvv', 0x0112, 3, 1, $orientation, 0) // Orientation, SHORT, count 1, value (padded)
            . pack('V', 0);                         // no next IFD
        return self::withJpegSegment($jpeg, "\xFF\xE1", "Exif\0\0" . $tiff);
    }
}
