<?php

// Generates the placeholder app icons in public/icons (an open book on the
// theme colour). Replace the PNG files with real artwork when rebranding.
//   php scripts/make-icons.php

$target = __DIR__.'/../public/icons';
@mkdir($target, 0755, true);

function icon(int $size, float $padding): GdImage
{
    $image = imagecreatetruecolor($size, $size);
    imageantialias($image, true);
    $background = imagecolorallocate($image, 0x0A, 0x0A, 0x0B);
    $page = imagecolorallocate($image, 0xC9, 0xA4, 0x4C);
    $line = imagecolorallocate($image, 0x6D, 0x1A, 0x2D);
    imagefill($image, 0, 0, $background);

    $inset = (int) ($size * $padding);
    $left = $inset;
    $right = $size - $inset;
    $top = (int) ($size * 0.32);
    $bottom = (int) ($size * 0.70);
    $middle = (int) ($size / 2);
    $dip = (int) ($size * 0.05);

    // Two pages meeting at a spine.
    imagefilledpolygon($image, [$left, $top, $middle, $top + $dip, $middle, $bottom + $dip, $left, $bottom], $page);
    imagefilledpolygon($image, [$right, $top, $middle, $top + $dip, $middle, $bottom + $dip, $right, $bottom], $page);

    imagesetthickness($image, max(2, (int) ($size * 0.012)));
    imageline($image, $middle, $top + $dip, $middle, $bottom + $dip, $line);
    for ($i = 1; $i <= 3; $i++) {
        $y = $top + (int) (($bottom - $top) * $i / 4);
        imageline($image, $left + (int) ($size * 0.05), $y, $middle - (int) ($size * 0.04), $y + (int) ($dip * 0.8), $line);
        imageline($image, $right - (int) ($size * 0.05), $y, $middle + (int) ($size * 0.04), $y + (int) ($dip * 0.8), $line);
    }

    return $image;
}

imagepng(icon(192, 0.16), $target.'/icon-192.png');
imagepng(icon(512, 0.16), $target.'/icon-512.png');
imagepng(icon(512, 0.26), $target.'/icon-maskable-512.png'); // extra safe zone for masks
echo "Icons written to public/icons\n";
