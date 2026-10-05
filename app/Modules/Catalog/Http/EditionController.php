<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Catalog\Models\Edition;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EditionController
{
    public function __invoke(Request $request, Edition $edition): Response
    {
        // Drafts, imports under review and withdrawn editions are visible to
        // staff only, as a preview. Everyone else gets a plain 404.
        $preview = ! $edition->isPublished();
        abort_if($preview && ! $request->user()?->isStaff(), 404);

        $edition->load([
            'work.translations', 'work.authors.translations', 'work.categories.translations',
            'contributors.translations', 'currentFiles',
            'work.publishedEditions' => fn ($q) => $q->where('editions.id', '!=', $edition->id)->orderBy('language_tag'),
            'work.publishedEditions.translators.translations',
        ]);

        $edition->currentFiles->each->setRelation('edition', $edition);

        $lists = $request->user()?->readingLists()->orderBy('name')->get() ?? collect();

        $response = response()->view('public.edition', [
            'edition' => $edition,
            'work' => $edition->work,
            'files' => $edition->currentFiles->sortBy(fn ($f) => array_search($f->format, ['epub', 'pdf', 'html', 'txt'], true))->values(),
            'otherEditions' => $edition->work->publishedEditions,
            'preview' => $preview,
            'lists' => $lists,
        ]);

        if ($preview) {
            $response->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
