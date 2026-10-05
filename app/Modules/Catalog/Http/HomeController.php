<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Edition;
use Illuminate\Contracts\View\View;

class HomeController
{
    public function __invoke(): View
    {
        $collections = Collection::query()
            ->where('is_published', true)->where('is_featured', true)->orderBy('position')
            ->with(['translations', 'works' => fn ($q) => $q->whereHas('publishedEditions')->limit(12),
                'works.translations', 'works.authors.translations',
                'works.publishedEditions' => fn ($q) => $q->orderBy('language_tag'),
            ])
            ->limit(6)->get()
            ->filter(fn (Collection $c) => $c->works->isNotEmpty());

        $recent = Edition::query()->published()->orderByDesc('published_at')->limit(12)
            ->with(['work.authors.translations', 'work.translations', 'translators.translations', 'currentFiles'])
            ->get();

        return view('public.home', compact('collections', 'recent'));
    }
}
