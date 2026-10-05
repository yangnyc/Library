<?php

namespace App\Modules\Downloads\Http;

use App\Modules\Catalog\Models\EditionFile;
use App\Modules\Downloads\ContentAccess;
use App\Modules\Downloads\FileStreamer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves reading-copy resources (sanitized EPUB parts, or the PDF itself) to
 * the browser reader. Runs without session, cookies or CSRF state.
 */
class ContentController
{
    private const TYPES = [
        'xhtml' => 'application/xhtml+xml', 'html' => 'application/xhtml+xml', 'htm' => 'application/xhtml+xml',
        'xml' => 'application/xml', 'opf' => 'application/oebps-package+xml', 'ncx' => 'application/x-dtbncx+xml',
        'css' => 'text/css; charset=utf-8', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
    ];

    // Applies if a resource is ever opened as a document on its own: nothing
    // runs, nothing remote loads, and the document is in an opaque origin.
    private const RESOURCE_CSP = "default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; sandbox";

    public function __invoke(Request $request, EditionFile $file, string $access, string $path, ContentAccess $guard, FileStreamer $streamer): Response
    {
        $contentOrigin = config('library.content_origin');
        if ($contentOrigin && ! hash_equals(parse_url($contentOrigin, PHP_URL_HOST) ?: '', $request->getHost())) {
            abort(404);
        }

        $file->loadMissing('edition');
        abort_unless($guard->allows($file, $access), 404);

        $headers = [
            'Content-Security-Policy' => self::RESOURCE_CSP,
            'Cross-Origin-Resource-Policy' => $contentOrigin ? 'cross-origin' : 'same-origin',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => $guard->isPreview($access) ? 'private, no-store' : 'public, max-age=86400',
        ];
        if ($contentOrigin) {
            $headers['Access-Control-Allow-Origin'] = rtrim(config('app.url'), '/');
            $headers['Vary'] = 'Origin';
        }

        $disk = Storage::disk($file->storage_disk);

        if ($file->format === 'pdf') {
            abort_unless($path === 'book.pdf', 404);

            return $streamer->stream($request, $disk->path($file->storage_path), 'application/pdf', $file->sha256, $headers + [
                'Content-Disposition' => 'inline; filename="book.pdf"',
            ]);
        }

        abort_unless($file->format === 'epub' && $file->reading_path, 404);

        // The path must name a file the import wrote. Matching against the
        // stored manifest means no user-supplied path ever reaches the disk.
        $known = collect($file->manifest['files'] ?? [])->firstWhere('path', $path);
        abort_unless($known, 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        abort_unless(isset(self::TYPES[$extension]), 404);

        return $streamer->stream(
            $request,
            $disk->path($file->reading_path.'/'.$known['path']),
            self::TYPES[$extension],
            substr($file->sha256, 0, 16).'-'.md5($known['path']),
            $headers
        );
    }
}
