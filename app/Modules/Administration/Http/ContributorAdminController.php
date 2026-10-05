<?php

namespace App\Modules\Administration\Http;

use App\Modules\Catalog\Models\Contributor;
use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Services\CatalogWriter;
use App\Modules\Catalog\Services\SearchIndexer;
use App\Modules\Localization\Models\Language;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContributorAdminController
{
    public function __construct(private readonly CatalogWriter $writer, private readonly SearchIndexer $indexer) {}

    public function index(Request $request): View
    {
        $contributors = Contributor::query()
            ->when($request->string('q')->trim()->value(), fn ($q, $term) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->orderBy('sort_name')->paginate(50)->withQueryString();

        return view('admin.contributors.index', compact('contributors'));
    }

    public function create(): View
    {
        return view('admin.contributors.form', ['contributor' => new Contributor, 'languages' => Language::map()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $contributor = Contributor::create($this->attributes($data) + [
            'slug' => ($data['slug'] ?? null) ?: $this->writer->uniqueSlug(Contributor::class, $data['name']),
        ]);
        $contributor->syncTranslations(array_intersect_key($data['translations'] ?? [], Language::map()));

        return redirect()->route('admin.contributors.index')->with('status', 'Contributor created.');
    }

    public function edit(int $contributor): View
    {
        return view('admin.contributors.form', [
            'contributor' => Contributor::with('translations')->findOrFail($contributor),
            'languages' => Language::map(),
        ]);
    }

    public function update(Request $request, int $contributor): RedirectResponse
    {
        $contributor = Contributor::findOrFail($contributor);
        $data = $this->validated($request, $contributor);

        $contributor->update($this->attributes($data) + ['slug' => ($data['slug'] ?? null) ?: $contributor->slug]);
        $contributor->syncTranslations(array_intersect_key($data['translations'] ?? [], Language::map()));

        // Names are part of the search text of every edition they appear on.
        Edition::query()
            ->whereHas('contributors', fn ($q) => $q->where('contributors.id', $contributor->id))
            ->orWhereHas('work.authors', fn ($q) => $q->where('contributors.id', $contributor->id))
            ->get()->each(fn (Edition $edition) => $this->indexer->index($edition));

        return back()->with('status', 'Contributor saved.');
    }

    public function destroy(int $contributor): RedirectResponse
    {
        $contributor = Contributor::findOrFail($contributor);
        if (DB::table('contributions')->where('contributor_id', $contributor->id)->exists()) {
            return back()->withErrors(['contributor' => 'This contributor is still credited on a work or edition.']);
        }
        $contributor->delete();

        return redirect()->route('admin.contributors.index')->with('status', 'Contributor deleted.');
    }

    private function validated(Request $request, ?Contributor $contributor = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:160', Rule::unique('contributors', 'slug')->ignore($contributor?->id)],
            'name_language_tag' => ['nullable', Rule::in(array_keys(Language::map()))],
            'biography' => ['nullable', 'string', 'max:10000'],
            'born' => ['nullable', 'string', 'max:20'],
            'died' => ['nullable', 'string', 'max:20'],
            'translations' => ['array'],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.biography' => ['nullable', 'string', 'max:10000'],
        ]);
    }

    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'sort_name' => ($data['sort_name'] ?? null) ?: $data['name'],
            'name_language_tag' => $data['name_language_tag'] ?? null,
            'biography' => $data['biography'] ?? null,
            'born' => $data['born'] ?? null,
            'died' => $data['died'] ?? null,
        ];
    }
}
