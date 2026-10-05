<?php

namespace App\Modules\Reader\Http;

use App\Modules\Catalog\Models\EditionFile;
use App\Modules\Downloads\ContentAccess;
use App\Modules\Localization\Locales;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

class OfflineController
{
    public function bookshelf(): View
    {
        return view('reader.offline');
    }

    /**
     * Everything a browser must store to read one edition offline. Only given
     * out for files whose rights allow offline saving.
     */
    public function manifest(EditionFile $file, ContentAccess $access): JsonResponse
    {
        $file->loadMissing('edition.work');

        if (! $file->publiclyOfflineable()) {
            // Tells an existing offline copy that this version is no longer
            // offered, and whether a newer one replaced it.
            $replacement = $file->edition?->isPublished()
                ? $file->edition->currentFiles()->where('format', $file->format)->get()
                    ->first(fn (EditionFile $f) => $f->setRelation('edition', $file->edition)->publiclyOfflineable())
                : null;

            return response()->json([
                'available' => false,
                'replacementFileId' => $replacement?->id,
            ], 410)->header('Cache-Control', 'no-store');
        }

        $edition = $file->edition;
        $base = $access->baseUrl($file, $file->contentKey());

        if ($file->format === 'pdf') {
            $resources = [['url' => $base.'book.pdf', 'bytes' => (int) $file->byte_size]];
        } else {
            $resources = array_map(fn (array $entry) => [
                'url' => $base.implode('/', array_map('rawurlencode', explode('/', $entry['path']))),
                'bytes' => (int) $entry['bytes'],
            ], $file->manifest['files'] ?? []);
        }

        // Page shells for every interface locale, so the saved book opens
        // whichever language the reader last used.
        $pages = [];
        foreach (Locales::supported() as $locale) {
            $pages[] = route('read', ['locale' => $locale, 'edition' => $edition->slug, 'format' => $file->format], false);
            $pages[] = route('offline', ['locale' => $locale], false);
        }

        return response()->json([
            'available' => true,
            'fileId' => $file->id,
            'contentKey' => $file->contentKey(),
            'format' => $file->format,
            'title' => $edition->title,
            'language' => $edition->language_tag,
            'direction' => $edition->direction,
            'editionSlug' => $edition->slug,
            'resources' => $resources,
            'pages' => $pages,
            'assets' => ViteAssets::forEntries(['resources/css/bootstrap.css', 'resources/css/bootstrap-rtl.css', 'resources/css/app.css', 'resources/js/app.ts', 'resources/js/ui.ts', 'resources/js/reader/index.ts', 'resources/js/offline-page.ts']),
            'totalBytes' => array_sum(array_column($resources, 'bytes')),
        ])->header('Cache-Control', 'no-store');
    }
}
