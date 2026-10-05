<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Catalog\Models\Work;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class WorkController
{
    public function __invoke(Request $request, Work $work): View
    {
        $work->load([
            'translations', 'authors.translations', 'categories.translations', 'tags.translations', 'series.translations',
            'publishedEditions' => fn ($q) => $q->orderBy('language_tag')->orderBy('published_date'),
            'publishedEditions.translators.translations', 'publishedEditions.currentFiles',
            'relatedWorks' => fn ($q) => $q->whereHas('publishedEditions'),
            'relatedWorks.translations',
        ]);

        // A work exists publicly only through its published editions.
        abort_if($work->publishedEditions->isEmpty(), 404);

        $isFavorite = $request->user()?->favorites()->whereKey($work->id)->exists() ?? false;

        return view('public.work', [
            'work' => $work,
            'editionsByLanguage' => $work->publishedEditions->groupBy('language_tag'),
            'isFavorite' => $isFavorite,
        ]);
    }
}
