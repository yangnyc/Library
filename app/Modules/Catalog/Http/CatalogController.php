<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Contributor;
use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Services\CatalogSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CatalogController
{
    public function __invoke(Request $request, CatalogSearch $search): View
    {
        // Filters live in the query string, so any result page can be shared.
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'language' => ['nullable', 'string', 'max:35'],
            'author' => ['nullable', 'string', 'max:160'],
            'translator' => ['nullable', 'string', 'max:160'],
            'category' => ['nullable', 'string', 'max:160'],
            'collection' => ['nullable', 'string', 'max:160'],
            'format' => ['nullable', 'in:epub,pdf,txt,html'],
            'sort' => ['nullable', 'in:'.implode(',', CatalogSearch::SORTS)],
            'view' => ['nullable', 'in:grid,list'],
        ]);

        $error = null;
        try {
            $editions = $search->paginate($filters);
        } catch (QueryException $e) {
            report($e);
            $editions = Edition::query()->whereRaw('1 = 0')->paginate();
            $error = __('ui.catalog.search_error');
        }

        // Three small indexed queries. Only the plain list of language tags is
        // cached: the framework's cache does not store model objects.
        $facets = [
            'languages' => Cache::remember('catalog.facets', 300, fn () => Edition::query()->published()->distinct()->orderBy('language_tag')->pluck('language_tag')->all()),
            'categories' => Category::query()->whereHas('works.publishedEditions')->with('translations')->orderBy('position')->orderBy('name')->get(),
            'collections' => Collection::query()->where('is_published', true)->whereHas('works.publishedEditions')->with('translations')->orderBy('position')->get(),
        ];

        $people = Contributor::query()->with('translations')
            ->whereIn('slug', array_filter([$filters['author'] ?? null, $filters['translator'] ?? null]))->get()->keyBy('slug');

        return view('public.catalog', [
            'editions' => $editions,
            'filters' => $filters,
            'facets' => $facets,
            'people' => $people,
            'layout' => ($filters['view'] ?? 'grid') === 'list' ? 'list' : 'grid',
            'error' => $error,
        ]);
    }
}
