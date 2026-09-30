<?php
/**
 * Crops the university logo out of the original letterhead strip.
 *
 * The grade sheet header is now built from HTML, but the logo still has to be a
 * real file. If you do not have a clean logo PNG, run this from a terminal:
 *
 *     php tools/extract_logo.php <source.png> [x] [y] [width] [height]
 *
 * Defaults crop the left edge of assets/images/header.png. Re-run with different
 * numbers until the output looks right, then keep the result as
 * assets/img/csu-logo.png. Simpler still: just save your own logo straight to
 * that path and skip this script.
 */

$root = dirname(__DIR__);
$defaultSource = $root . '/assets/images/header.png';

$source = $argv[1] ?? $defaultSource;
$x      = intval($argv[2] ?? 0);
$y      = intval($argv[3] ?? 0);
$width  = intval($argv[4] ?? 170);
$height = intval($argv[5] ?? 164);

if (!is_file($source)) {
    fwrite(STDERR, "Source not found: $source\n");
    exit(1);
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "The GD extension is required.\n"
        . "Open C:\\xampp\\php\\php.ini, uncomment extension=gd, then restart Apache.\n");
    exit(1);
}

$image = @imagecreatefrompng($source);
if (!$image) {
    fwrite(STDERR, "Could not read the PNG.\n");
    exit(1);
}

$srcW = imagesx($image);
$srcH = imagesy($image);

$x = max(0, min($x, $srcW - 1));
$y = max(0, min($y, $srcH - 1));
$width  = max(1, min($width, $srcW - $x));
$height = max(1, min($height, $srcH - $y));

$canvas = imagecreatetruecolor($width, $height);
// Preserve transparency if the source has an alpha channel.
imagealphablending($canvas, false);
imagesavealpha($canvas, true);
imagecopy($canvas, $image, 0, 0, $x, $y, $width, $height);

$target = $root . '/assets/img/csu-logo.png';
if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0777, true);
}
imagepng($canvas, $target);

imagedestroy($image);
imagedestroy($canvas);

echo "Wrote $target ({$width}x{$height})\n";
