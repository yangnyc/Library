<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Services\CatalogSearch;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The first few catalog matches for what is being typed into a search box.
 * A convenience only: the search form itself works without it.
 */
class SuggestController
{
    private const LIMIT = 6;

    public function __invoke(Request $request, CatalogSearch $search): JsonResponse
    {
        $filters = $request->validate(['q' => ['required', 'string', 'min:2', 'max:200']]);

        try {
            $editions = $search->paginate($filters, self::LIMIT);
        } catch (QueryException $e) {
            report($e);

            return response()->json(['results' => [], 'total' => 0]);
        }

        return response()->json([
            'results' => $editions->getCollection()->map(fn (Edition $edition) => [
                'title' => $edition->title,
                'language' => $edition->language_tag,
                'direction' => $edition->direction,
                'people' => $edition->work->authors->map(fn ($author) => $author->localized('name'))->implode(', '),
                'url' => route('editions.show', $edition),
            ])->all(),
            'total' => $editions->total(),
        ]);
    }
}
