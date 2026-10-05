<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\Work;
use App\Modules\Localization\SearchNormalizer;

/** Maintains editions.search_text, the denormalized value catalog search runs on. */
class SearchIndexer
{
    public function index(Edition $edition): void
    {
        $edition->loadMissing([
            'work.authors.translations', 'work.tags.translations', 'work.categories.translations',
            'work.translations', 'contributors.translations',
        ]);
        $work = $edition->work;

        $parts = [
            $edition->title, $edition->subtitle, $edition->description, $edition->publisher,
            $edition->isbn, $edition->isbn ? preg_replace('/[^0-9Xx]/', '', $edition->isbn) : null,
            $edition->edition_statement,
            $work->original_title,
        ];

        foreach ($work->translations as $translation) {
            $parts[] = $translation->value;
        }
        foreach ([$work->authors, $edition->contributors, $work->tags, $work->categories] as $group) {
            foreach ($group as $item) {
                $parts[] = $item->name;
                foreach ($item->translations as $translation) {
                    if ($translation->field === 'name') {
                        $parts[] = $translation->value;
                    }
                }
            }
        }

        $text = SearchNormalizer::normalize(implode(' ', array_filter($parts)));

        // saveQuietly: indexing must not bump updated_at or re-trigger itself.
        $edition->forceFill(['search_text' => mb_substr($text, 0, 60000)])->saveQuietly();
    }

    public function indexWork(Work $work): void
    {
        $work->editions()->each(fn (Edition $edition) => $this->index($edition));
    }

    public function indexAll(): int
    {
        $count = 0;
        Edition::query()->chunkById(100, function ($editions) use (&$count) {
            foreach ($editions as $edition) {
                $this->index($edition);
                $count++;
            }
        });

        return $count;
    }
}
