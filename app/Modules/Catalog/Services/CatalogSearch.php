<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Edition;
use App\Modules\Localization\SearchNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Catalog search over published editions.
 *
 * Tokens the database full-text index can handle go through MATCH … AGAINST.
 * Everything else (short words, stop words, engines without full-text) uses a
 * LIKE scan that is capped at `library.search.fallback_limit` matches, so a
 * query can never turn into an unbounded table scan result.
 */
class CatalogSearch
{
    public const SORTS = ['relevance', 'newest', 'title', 'year'];

    // InnoDB's default stop words: MATCH would silently never find these.
    private const STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in',
        'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who',
        'will', 'with', 'und', 'www',
    ];

    /**
     * @param array{q?:?string, language?:?string, author?:?string, translator?:?string, category?:?string,
     *              collection?:?string, format?:?string, sort?:?string} $filters
     */
    public function paginate(array $filters, ?int $perPage = null): LengthAwarePaginator
    {
        $perPage ??= config('library.search.per_page');
        $query = Edition::query()->published()->select('editions.*')
            ->with(['work.authors.translations', 'work.translations', 'translators.translations', 'currentFiles']);

        $this->applyFilters($query, $filters);
        $usedFullText = $this->applyText($query, $filters['q'] ?? null);

        $sort = in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : null;
        $sort ??= $usedFullText ? 'relevance' : 'newest';

        match ($sort) {
            'title' => $query->orderBy('editions.title'),
            'year' => $query->orderByDesc('editions.published_date')->orderBy('editions.title'),
            'relevance' => $usedFullText
                ? $query->orderByDesc('relevance')->orderByDesc('editions.published_at')
                : $query->orderByDesc('editions.published_at'),
            default => $query->orderByDesc('editions.published_at'),
        };
        $query->orderBy('editions.id');

        return $query->paginate($perPage)->withQueryString();
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if ($language = $filters['language'] ?? null) {
            $query->where('editions.language_tag', $language);
        }
        if ($author = $filters['author'] ?? null) {
            $query->whereHas('work.authors', fn (Builder $q) => $q->where('contributors.slug', $author));
        }
        if ($translator = $filters['translator'] ?? null) {
            $query->whereHas('translators', fn (Builder $q) => $q->where('contributors.slug', $translator));
        }
        if ($category = $filters['category'] ?? null) {
            $query->whereHas('work.categories', fn (Builder $q) => $q->where('categories.slug', $category));
        }
        if ($collection = $filters['collection'] ?? null) {
            $query->whereHas('work.collections', fn (Builder $q) => $q->where('collections.slug', $collection)->where('is_published', true));
        }
        if ($format = $filters['format'] ?? null) {
            $query->whereHas('currentFiles', fn (Builder $q) => $q->where('format', $format)
                ->where(fn (Builder $p) => $p->where('can_read', true)->orWhere('can_download', true)));
        }
    }

    /** @return bool whether a relevance column is available for sorting */
    private function applyText(Builder $query, ?string $text): bool
    {
        $tokens = SearchNormalizer::tokens($text);
        if ($tokens === []) {
            return false;
        }

        $fullText = [];
        $like = [];
        $supportsFullText = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        $minLength = $this->minTokenLength();

        foreach ($tokens as $token) {
            if ($supportsFullText && mb_strlen($token) >= $minLength && ! in_array($token, self::STOPWORDS, true)) {
                $fullText[] = $token;
            } else {
                $like[] = $token;
            }
        }

        if ($fullText !== []) {
            // Tokens are letters and digits only (see SearchNormalizer), so no
            // boolean-mode operator can be smuggled in; the value is still bound.
            $expression = implode(' ', array_map(fn (string $t) => '+'.$t.'*', $fullText));
            $query->whereRaw('MATCH(editions.search_text) AGAINST (? IN BOOLEAN MODE)', [$expression])
                ->selectRaw('MATCH(editions.search_text) AGAINST (? IN BOOLEAN MODE) AS relevance', [$expression]);
        }

        if ($like !== []) {
            $bounded = Edition::query()->published();
            if ($fullText !== []) {
                $bounded->whereRaw('MATCH(search_text) AGAINST (? IN BOOLEAN MODE)', [$expression]);
            }
            foreach ($like as $token) {
                $bounded->where('search_text', 'like', '%'.addcslashes($token, '%_\\').'%');
            }
            $ids = $bounded->orderByDesc('published_at')->limit(config('library.search.fallback_limit'))->pluck('id');
            $query->whereIn('editions.id', $ids);
        }

        return $fullText !== [];
    }

    private function minTokenLength(): int
    {
        return cache()->remember('search.ft_min_token', 86400, function () {
            try {
                $row = DB::selectOne("SHOW VARIABLES LIKE 'innodb_ft_min_token_size'");

                return (int) ($row->Value ?? 3);
            } catch (\Throwable) {
                return 3;
            }
        });
    }
}
