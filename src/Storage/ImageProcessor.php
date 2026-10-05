<?php
declare(strict_types=1);

namespace Pfpms\Storage;

use finfo;
use GdImage;
use InvalidArgumentException;

/**
 * Cleans an uploaded picture before it is stored (US-13 size band pictures, UC-05 §4.4 pet
 * photos). The picture is decoded and drawn again, so anything hidden in the file (EXIF data
 * such as the GPS position, comments, or a script disguised as an image) is left behind.
 * Large pictures are scaled down. PNG stays PNG (to keep transparency); JPEG and WebP
 * become JPEG. Needs ext-gd.
 */
final class ImageProcessor
{
    public const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];

    /** Refuse pictures whose pixels would not fit in memory once decoded (about 4 bytes each). */
    public const MAX_PIXELS = 40_000_000;

    private const JPEG_QUALITY = 85;

    /**
     * @return array{bytes: string, mime: string}
     * @throws InvalidArgumentException with a message that can be shown to the user
     */
    public static function sanitize(string $bytes, int $maxDimension = 1600): array
    {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, self::ACCEPTED, true)) {
            throw new InvalidArgumentException('That file is not a picture we can use. Choose a JPEG, PNG or WebP picture.');
        }
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new InvalidArgumentException('That picture could not be read. Try saving it again as a JPEG or PNG.');
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw new InvalidArgumentException('That picture is too large to process. Choose a smaller picture.');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new InvalidArgumentException('That picture could not be read. Try saving it again as a JPEG or PNG.');
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $keepPng = $mime === 'image/png';
        $image = self::fit($image, max(1, $maxDimension));
        if ($mime === 'image/jpeg') {
            $image = self::applyOrientation($image, $bytes); // after scaling, so the turn works on the small copy
        }

        ob_start();
        if ($keepPng) {
            imagesavealpha($image, true);
            imagepng($image, null, 6);
        } else {
            imagejpeg(self::onWhite($image), null, self::JPEG_QUALITY);
        }
        $out = (string) ob_get_clean();
        if ($out === '') {
            throw new InvalidArgumentException('That picture could not be processed. Try another picture.');
        }
        return ['bytes' => $out, 'mime' => $keepPng ? 'image/png' : 'image/jpeg'];
    }

    /**
     * Scale down, keeping the aspect ratio, so neither side is longer than $max. Transparency
     * is always kept here (a WebP can have it too); onWhite() flattens it for JPEG afterwards.
     */
    private static function fit(GdImage $image, int $max): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= $max && $height <= $max) {
            return $image;
        }
        $scale = $max / max($width, $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $scaled = self::canvas($newWidth, $newHeight);
        imagealphablending($scaled, false);
        imagefill($scaled, 0, 0, (int) imagecolorallocatealpha($scaled, 0, 0, 0, 127));
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        return $scaled;
    }

    /** JPEG has no transparency: draw the picture on white so see-through areas do not turn black. */
    private static function onWhite(GdImage $image): GdImage
    {
        $canvas = self::canvas(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        return $canvas;
    }

    private static function canvas(int $width, int $height): GdImage
    {
        return imagecreatetruecolor($width, $height)
            ?: throw new InvalidArgumentException('That picture could not be processed. Try another picture.');
    }

    /**
     * Phones store portrait photos sideways with an EXIF "orientation" tag. Re-encoding drops
     * the tag, so turn the pixels the right way up first. Skipped when ext-exif is missing.
     */
    private static function applyOrientation(GdImage $image, string $bytes): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return $image;
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $flip = match ($orientation) {
            2, 5, 7 => IMG_FLIP_HORIZONTAL,
            4 => IMG_FLIP_VERTICAL,
            default => null,
        };
        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,  // GD turns anticlockwise
            6, 7 => -90,
            default => 0,
        };
        if ($flip !== null) {
            imageflip($image, $flip);
        }
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated !== false) {
                $image = $rotated;
            }
        }
        return $image;
    }
}
