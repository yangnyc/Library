<?php

namespace App\Modules\Reader\Http;

use App\Http\Middleware\SecurityHeaders;
use App\Modules\Catalog\Models\Edition;
use App\Modules\Downloads\ContentAccess;
use App\Modules\Localization\Models\Language;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReaderController
{
    public function __invoke(Request $request, Edition $edition, ContentAccess $access, ?string $format = null): Response
    {
        // The page is rendered identically for guests and signed-in readers:
        // identity is fetched separately from /api/session. That keeps this
        // HTML free of personal data, so it is safe to keep for offline use.
        $preview = ! $edition->isPublished();
        abort_if($preview && ! $request->user()?->isStaff(), 404);

        $edition->load(['work.authors.translations', 'work.translations', 'currentFiles']);

        if ($preview) {
            // Staff may preview any imported file, current or not, via ?file=.
            $file = $edition->files()->where('import_status', 'ready')
                ->when($request->integer('file'), fn ($q, $id) => $q->whereKey($id))
                ->when($format, fn ($q) => $q->where('format', $format))
                ->whereIn('format', ['epub', 'pdf'])->orderByDesc('is_current')->orderByDesc('version')->first();
            abort_unless($file && $file->isBrowserReadable(), 404);
        } else {
            $file = $edition->readableFile($format);
            abort_unless($file, 404);
        }
        $file->setRelation('edition', $edition);

        $segment = $access->accessSegment($file, $preview);
        $base = $access->baseUrl($file, $segment);
        $downloadable = $edition->currentFiles->filter(fn ($f) => $preview ? false : $f->publiclyDownloadable());

        $config = [
            'format' => $file->format,
            'fileId' => $file->id,
            'editionId' => $edition->id,
            'contentKey' => $file->contentKey(),
            'baseUrl' => $base,
            'opfPath' => $file->manifest['opf'] ?? null,
            'title' => $edition->title,
            'language' => $edition->language_tag,
            'direction' => $edition->direction,
            'pageProgression' => $edition->page_progression,
            'layout' => $file->layout,
            'hasTextLayer' => $file->has_text_layer,
            'preview' => $preview,
            'offlineAllowed' => ! $preview && $file->publiclyOfflineable(),
            'urls' => [
                'edition' => route('editions.show', $edition),
                'session' => route('api.session'),
                'sync' => route('api.sync'),
                'offlineManifest' => route('api.offline-manifest', $file->id),
                'download' => $downloadable->firstWhere('format', $file->format)
                    ? route('download', ['file' => $file->id, 'filename' => $file->asciiDownloadFilename()]) : null,
                'pdfAssets' => asset('vendor/pdfjs').'/',
            ],
            'strings' => trans('reader'),
        ];

        $contentOrigin = rtrim((string) config('library.content_origin'), '/');
        $sources = $contentOrigin ? [$contentOrigin] : [];

        return response()
            ->view('reader.show', [
                'edition' => $edition,
                'file' => $file,
                'config' => $config,
                'preview' => $preview,
                'editionDir' => $edition->direction === 'auto' ? Language::directionFor($edition->language_tag) : $edition->direction,
            ])
            ->header('X-Robots-Tag', 'noindex')
            ->header('Cache-Control', $preview ? 'private, no-store' : 'no-cache')
            // Book documents are written into sandboxed frames that inherit this
            // policy: script-src stays 'self', so markup from a book can never
            // run script even if a sanitizer rule were missed.
            ->header('Content-Security-Policy', SecurityHeaders::policy([
                'script-src' => $file->format === 'pdf' ? ["'wasm-unsafe-eval'"] : [],
                'img-src' => array_merge(['blob:'], $sources),
                'font-src' => array_merge(['data:'], $sources),
                'style-src' => array_merge(['blob:'], $sources),
                'connect-src' => $sources,
                'frame-src' => ["'self'", 'blob:'],
                'worker-src' => ['blob:'],
            ]));
    }
}
