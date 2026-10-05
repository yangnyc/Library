<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Contributor;
use App\Modules\Catalog\Models\Edition;
use Illuminate\Contracts\View\View;

class BrowseController
{
    private const EDITION_RELATIONS = ['work.authors.translations', 'work.translations', 'translators.translations', 'currentFiles'];

    public function contributor(Contributor $contributor): View
    {
        $contributor->load('translations');

        $authored = Edition::query()->published()->with(self::EDITION_RELATIONS)
            ->whereHas('work.authors', fn ($q) => $q->where('contributors.id', $contributor->id))
            ->orderBy('title')->paginate(24, ['*'], 'authored')->withQueryString();

        $contributed = Edition::query()->published()->with(self::EDITION_RELATIONS)
            ->whereHas('contributors', fn ($q) => $q->where('contributors.id', $contributor->id))
            ->orderBy('title')->paginate(24, ['*'], 'contributed')->withQueryString();

        abort_if($authored->total() === 0 && $contributed->total() === 0, 404);

        return view('public.contributor', compact('contributor', 'authored', 'contributed'));
    }

    public function collections(): View
    {
        $collections = Collection::query()->where('is_published', true)
            ->whereHas('works.publishedEditions')->with('translations')
            ->withCount(['works' => fn ($q) => $q->whereHas('publishedEditions')])
            ->orderBy('position')->orderBy('name')->paginate(30);

        return view('public.collections', compact('collections'));
    }

    public function collection(Collection $collection): View
    {
        abort_unless($collection->is_published, 404);
        $collection->load('translations');

        $works = $this->worksWithEditions($collection->works())->paginate(24);

        return view('public.work-list', [
            'title' => $collection->localized('name'),
            'titleLang' => $collection->localizedLanguage('name'),
            'description' => $collection->localized('description'),
            'kind' => __('ui.nav.collections'),
            'works' => $works,
            'catalogFilter' => ['collection' => $collection->slug],
        ]);
    }

    public function category(Category $category): View
    {
        $category->load('translations');

        $works = $this->worksWithEditions($category->works())->orderBy('works.original_title')->paginate(24);

        abort_if($works->total() === 0, 404);

        return view('public.work-list', [
            'title' => $category->localized('name'),
            'titleLang' => $category->localizedLanguage('name'),
            'description' => null,
            'kind' => __('ui.catalog.category'),
            'works' => $works,
            'catalogFilter' => ['category' => $category->slug],
        ]);
    }

    private function worksWithEditions($query)
    {
        return $query->whereHas('publishedEditions')->with([
            'translations', 'authors.translations',
            'publishedEditions' => fn ($q) => $q->orderBy('language_tag'),
            'publishedEditions.currentFiles',
        ]);
    }
}
