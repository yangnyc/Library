<?php

namespace App\Modules\Downloads\Http;

use App\Modules\Catalog\Models\EditionFile;
use App\Modules\Downloads\FileStreamer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class DownloadController
{
    /** Public download of an authorized, published file. No account needed. */
    public function __invoke(Request $request, EditionFile $file, FileStreamer $streamer): Response
    {
        $file->loadMissing('edition');

        // Staff may fetch any successfully imported file to review it.
        $staffReview = $request->user()?->isStaff() && $file->import_status === 'ready';
        abort_unless($file->publiclyDownloadable() || $staffReview, 404);

        $unicode = $file->downloadFilename();
        $ascii = $file->asciiDownloadFilename();

        $response = $streamer->stream(
            $request,
            Storage::disk($file->storage_disk)->path($file->storage_path),
            $file->mime_type,
            $file->sha256,
            [
                // filename= is the ASCII fallback; filename*= carries the real title (RFC 6266).
                'Content-Disposition' => 'attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($unicode),
                'Cache-Control' => $file->publiclyDownloadable() ? 'public, max-age=3600' : 'private, no-store',
                'X-Robots-Tag' => 'noindex',
            ]
        );

        // Count a download once: on the request that starts at the beginning.
        $range = (string) $request->headers->get('Range');
        if ($request->isMethod('GET') && $file->publiclyDownloadable() && ($range === '' || str_starts_with($range, 'bytes=0-'))
            && $response->getStatusCode() < 300) {
            EditionFile::query()->whereKey($file->id)->increment('download_count');
        }

        return $response;
    }
}
