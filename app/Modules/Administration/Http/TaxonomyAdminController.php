<?php

namespace App\Modules\Administration\Http;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Catalog\Services\CatalogWriter;
use App\Modules\Localization\Models\Language;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/** Categories, curated collections and tags share one small editor. */
class TaxonomyAdminController
{
    private const MODELS = ['categories' => Category::class, 'collections' => Collection::class, 'tags' => Tag::class];

    public function __construct(private readonly CatalogWriter $writer) {}

    public function index(): View
    {
        return view('admin.taxonomy.index', [
            'categories' => Category::query()->withCount('works')->orderBy('position')->orderBy('name')->get(),
            'collections' => Collection::query()->withCount('works')->orderBy('position')->orderBy('name')->get(),
            'tags' => Tag::query()->withCount('works')->orderBy('name')->limit(500)->get(),
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $model = self::MODELS[$type];
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $item = $model::create(['name' => $data['name'], 'slug' => $this->writer->uniqueSlug($model, $data['name'])]);
        Cache::forget('catalog.facets');

        return redirect()->route('admin.taxonomy.edit', [$type, $item->id])->with('status', 'Created.');
    }

    public function edit(string $type, int $id): View
    {
        $model = self::MODELS[$type];
        $item = $model::with('translations')->findOrFail($id);

        return view('admin.taxonomy.edit', [
            'type' => $type,
            'item' => $item,
            'languages' => Language::map(),
            'works' => $type === 'collections' ? $item->works()->get(['works.id', 'works.original_title']) : collect(),
        ]);
    }

    public function update(Request $request, string $type, int $id): RedirectResponse
    {
        $model = self::MODELS[$type];
        $item = $model::findOrFail($id);
        $table = $item->getTable();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:160', Rule::unique($table, 'slug')->ignore($item->id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'is_featured' => ['nullable', 'boolean'],
            'is_published' => ['nullable', 'boolean'],
            'translations' => ['array'],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string', 'max:5000'],
            'work_order' => ['array'],
            'work_order.*' => ['integer', 'min:0', 'max:65000'],
        ]);

        $attributes = ['name' => $data['name'], 'slug' => $data['slug']];
        if ($type !== 'tags') {
            $attributes['position'] = (int) ($data['position'] ?? 0);
        }
        if ($type === 'collections') {
            $attributes += [
                'description' => $data['description'] ?? null,
                'is_featured' => (bool) ($data['is_featured'] ?? false),
                'is_published' => (bool) ($data['is_published'] ?? false),
            ];
            foreach ($data['work_order'] ?? [] as $workId => $position) {
                $item->works()->updateExistingPivot((int) $workId, ['position' => $position]);
            }
        }
        $item->update($attributes);
        $item->syncTranslations(array_intersect_key($data['translations'] ?? [], Language::map()));
        Cache::forget('catalog.facets');

        return back()->with('status', 'Saved.');
    }

    public function destroy(string $type, int $id): RedirectResponse
    {
        self::MODELS[$type]::findOrFail($id)->delete();
        Cache::forget('catalog.facets');

        return redirect()->route('admin.taxonomy.index')->with('status', 'Deleted.');
    }
}
