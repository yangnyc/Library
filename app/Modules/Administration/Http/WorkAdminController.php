<?php

namespace App\Modules\Administration\Http;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Contributor;
use App\Modules\Catalog\Models\Work;
use App\Modules\Catalog\Services\CatalogWriter;
use App\Modules\Imports\Models\AuditEvent;
use App\Modules\Localization\Models\Language;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkAdminController
{
    public function __construct(private readonly CatalogWriter $writer) {}

    public function index(Request $request): View
    {
        $works = Work::query()->withCount('editions')->with('authors')
            ->when($request->string('q')->trim()->value(), fn ($q, $term) => $q->where('original_title', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->orderBy('original_title')->paginate(40)->withQueryString();

        return view('admin.works.index', compact('works'));
    }

    public function create(): View
    {
        return view('admin.works.form', ['work' => new Work, 'editions' => collect()] + $this->options());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $work = DB::transaction(function () use ($data) {
            $work = Work::create([
                'slug' => ($data['slug'] ?? null) ?: $this->writer->uniqueSlug(Work::class, $data['original_title']),
            ] + $this->attributes($data));
            $this->syncRelations($work, $data);

            return $work;
        });
        AuditEvent::record('work.created', $work, ['title' => $work->original_title]);

        return redirect()->route('admin.works.edit', $work->id)->with('status', 'Work created. Add an edition next.');
    }

    public function edit(int $work): View
    {
        $work = Work::with(['authors', 'categories', 'collections', 'tags', 'translations'])->findOrFail($work);

        return view('admin.works.form', [
            'work' => $work,
            'editions' => $work->editions()->with('files')->orderBy('language_tag')->get(),
        ] + $this->options());
    }

    public function update(Request $request, int $work): RedirectResponse
    {
        $work = Work::findOrFail($work);
        $data = $this->validated($request, $work);

        DB::transaction(function () use ($work, $data) {
            $work->update(['slug' => ($data['slug'] ?? null) ?: $work->slug] + $this->attributes($data));
            $this->syncRelations($work, $data);
        });
        AuditEvent::record('work.updated', $work);

        return back()->with('status', 'Work saved.');
    }

    public function destroy(int $work): RedirectResponse
    {
        $work = Work::findOrFail($work);
        if ($work->editions()->exists()) {
            return back()->withErrors(['work' => 'Delete or move the editions of this work first.']);
        }
        AuditEvent::record('work.deleted', $work, ['title' => $work->original_title]);
        $work->delete();

        return redirect()->route('admin.works.index')->with('status', 'Work deleted.');
    }

    private function validated(Request $request, ?Work $work = null): array
    {
        $languages = array_keys(Language::map());

        return $request->validate([
            'original_title' => ['required', 'string', 'max:255'],
            'original_language_tag' => ['required', Rule::in($languages)],
            'slug' => ['nullable', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:160', Rule::unique('works', 'slug')->ignore($work?->id)],
            'first_published' => ['nullable', 'string', 'max:20'],
            'summary' => ['nullable', 'string', 'max:10000'],
            'authors' => ['array'],
            'authors.*' => ['integer', 'exists:contributors,id'],
            'new_authors' => ['nullable', 'string', 'max:1000'],
            'categories' => ['array'],
            'categories.*' => ['integer', 'exists:categories,id'],
            'collections' => ['array'],
            'collections.*' => ['integer', 'exists:collections,id'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'translations' => ['array'],
            'translations.*.title' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function attributes(array $data): array
    {
        return [
            'original_title' => $data['original_title'],
            'original_language_tag' => $data['original_language_tag'],
            'first_published' => $data['first_published'] ?? null,
            'summary' => $data['summary'] ?? null,
        ];
    }

    private function syncRelations(Work $work, array $data): void
    {
        $authorIds = $data['authors'] ?? [];
        foreach (array_filter(array_map('trim', explode(';', $data['new_authors'] ?? ''))) as $name) {
            $authorIds[] = $this->writer->contributor($name)->id;
        }
        $this->writer->syncContributors($work, 'author', $authorIds);

        $work->categories()->sync($data['categories'] ?? []);
        $work->collections()->sync($data['collections'] ?? []);
        $this->writer->syncTags($work, explode(';', $data['tags'] ?? ''));

        $translations = array_intersect_key($data['translations'] ?? [], Language::map());
        $work->syncTranslations($translations);

        $this->writer->reindex($work);
    }

    private function options(): array
    {
        return [
            'languages' => Language::map(),
            'contributors' => Contributor::query()->orderBy('sort_name')->get(['id', 'name']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'collections' => Collection::query()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
