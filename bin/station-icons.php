<?php
declare(strict_types=1);

/*
 * Draws the Station's home-screen icons with GD (docs/design/50-design-station.md D-09), once: the PNGs are committed.
 * Colours are the --brand (background) and --accent (paw) tokens of public/assets/css/app.css; the paw is
 * public/assets/img/paw.svg's five ellipses (viewBox 0 0 64 64). Drawn at 4x and resampled down, for smooth edges.
 *
 *   php bin/station-icons.php [output directory, default public/station/icons]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$out = rtrim($argv[1] ?? "$root/public/station/icons", '/\\');
$css = (string) @file_get_contents("$root/public/assets/css/app.css");

/** @return array{0: int, 1: int, 2: int} */
function colourToken(string $css, string $name): array
{
    if (!preg_match('/--' . preg_quote($name, '/') . ':\s*#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})\s*;/i', $css, $m)) {
        fwrite(STDERR, "The --$name colour was not found in public/assets/css/app.css\n");
        exit(1);
    }
    return [(int) hexdec($m[1]), (int) hexdec($m[2]), (int) hexdec($m[3])];
}

/** The paw's ellipses: cx, cy, rx, ry in the 64-unit box. Its bounding box is x 8..56, y 8..54 (48 x 46, centre 32, 31). */
const PAW = [[32, 42, 14, 12], [14, 28, 6, 8], [25, 16, 6, 8], [39, 16, 6, 8], [50, 28, 6, 8]];
/** file, size in px, paw width as a share of the icon. The maskable paw (0.50) stays inside the 80 % safe circle. */
const ICONS = [
    ['icon-192.png', 192, 0.62],
    ['icon-512.png', 512, 0.62],
    ['icon-maskable-512.png', 512, 0.50],
    ['apple-touch-icon.png', 180, 0.62],
];
const SUPERSAMPLE = 4;

$bg = colourToken($css, 'brand');
$paw = colourToken($css, 'accent');
if (!is_dir($out) && !mkdir($out, 0775, true)) {
    fwrite(STDERR, "Cannot create $out\n");
    exit(1);
}
foreach (ICONS as [$file, $size, $share]) {
    $big = $size * SUPERSAMPLE;
    $im = imagecreatetruecolor($big, $big);
    $small = imagecreatetruecolor($size, $size);
    if ($im === false || $small === false) {
        fwrite(STDERR, "GD could not create a {$big}px image\n");
        exit(1);
    }
    $background = imagecolorallocate($im, $bg[0], $bg[1], $bg[2]);
    $colour = imagecolorallocate($im, $paw[0], $paw[1], $paw[2]);
    if ($background === false || $colour === false) {
        fwrite(STDERR, "GD could not allocate the colours\n");
        exit(1);
    }
    imagefill($im, 0, 0, $background);
    $scale = $big * $share / 48;
    $ox = $big / 2 - 32 * $scale;
    $oy = $big / 2 - 31 * $scale;
    foreach (PAW as [$cx, $cy, $rx, $ry]) {
        imagefilledellipse($im, (int) round($ox + $cx * $scale), (int) round($oy + $cy * $scale),
            (int) round(2 * $rx * $scale), (int) round(2 * $ry * $scale), $colour);
    }
    imagecopyresampled($small, $im, 0, 0, 0, 0, $size, $size, $big, $big);
    if (!imagepng($small, "$out/$file", 9)) {
        fwrite(STDERR, "Cannot write $out/$file\n");
        exit(1);
    }
    echo "$out/$file ({$size}x$size)\n";
}
