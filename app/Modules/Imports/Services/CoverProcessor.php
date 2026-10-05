<?php

namespace App\Modules\Imports\Services;

use App\Modules\Imports\ImportRejected;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns an uploaded or extracted cover into a re-encoded JPEG of bounded size.
 * Re-encoding drops metadata and anything that is not pixel data.
 */
class CoverProcessor
{
    private const MAX_WIDTH = 640;

    private const MAX_HEIGHT = 960;

    public function store(string $bytes): string
    {
        if (strlen($bytes) > config('library.imports.max_cover_bytes')) {
            throw new ImportRejected('The cover image is too large.');
        }

        // Read the dimensions before decoding, so a small file that declares a
        // huge canvas cannot exhaust memory.
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            throw new ImportRejected('The cover must be a JPEG, PNG, GIF or WebP image.');
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width * $height > config('library.imports.max_cover_pixels')) {
            throw new ImportRejected('The cover image dimensions are not acceptable.');
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new ImportRejected('The cover image could not be decoded.');
        }

        $scale = min(1, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($target, null, 84);
        $jpeg = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        $path = Str::random(40).'.jpg';
        Storage::disk('covers')->put($path, $jpeg);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('covers')->delete($path);
        }
    }
}
