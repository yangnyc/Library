<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Contributor;
use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Catalog\Models\Work;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared write helpers used by the admin screens, the CSV import and the seeder. */
class CatalogWriter
{
    public function __construct(private readonly SearchIndexer $indexer) {}

    /** Slug that is unique for the model's table; non-Latin titles keep a readable form where possible. */
    public function uniqueSlug(string $modelClass, string $text, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($text, 120, ''));
        if ($base === '') {
            $base = Str::lower(class_basename($modelClass)).'-'.Str::lower(Str::random(6));
        }

        $slug = $base;
        $suffix = 2;
        while ($modelClass::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    public function contributor(string $name): Contributor
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        return Contributor::query()->where('name', $name)->first()
            ?? Contributor::create([
                'name' => $name,
                'sort_name' => $name,
                'slug' => $this->uniqueSlug(Contributor::class, $name),
            ]);
    }

    /**
     * Replaces the contributors of one role on a work or edition.
     *
     * @param  iterable<int|string>  $contributorIds  in display order
     */
    public function syncContributors(Model $subject, string $role, iterable $contributorIds): void
    {
        $subject->morphToMany(Contributor::class, 'contributable', 'contributions')->wherePivot('role', $role)->detach();

        $position = 0;
        foreach (array_unique([...$contributorIds]) as $id) {
            DB::table('contributions')->insert([
                'contributor_id' => (int) $id,
                'contributable_type' => $subject->getMorphClass(),
                'contributable_id' => $subject->getKey(),
                'role' => $role,
                'position' => $position++,
            ]);
        }
    }

    /** @param list<string> $names */
    public function syncTags(Work $work, array $names): void
    {
        $ids = [];
        foreach (array_filter(array_map('trim', $names)) as $name) {
            $ids[] = (Tag::query()->where('name', $name)->first()
                ?? Tag::create(['name' => $name, 'slug' => $this->uniqueSlug(Tag::class, $name)]))->id;
        }
        $work->tags()->sync($ids);
    }

    /** @param list<string> $names */
    public function syncCategories(Work $work, array $names): void
    {
        $ids = [];
        foreach (array_filter(array_map('trim', $names)) as $name) {
            $ids[] = (Category::query()->where('name', $name)->orWhere('slug', $name)->first()
                ?? Category::create(['name' => $name, 'slug' => $this->uniqueSlug(Category::class, $name)]))->id;
        }
        $work->categories()->sync($ids);
    }

    public function reindex(Work|Edition $subject): void
    {
        $subject instanceof Work ? $this->indexer->indexWork($subject) : $this->indexer->index($subject);
    }
}
