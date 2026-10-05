<?php

namespace App\Modules\Administration\Http;

use App\Modules\Catalog\Models\Contributor;
use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\Work;
use App\Modules\Catalog\Services\CatalogWriter;
use App\Modules\Imports\ImportRejected;
use App\Modules\Imports\Models\AuditEvent;
use App\Modules\Imports\Models\ImportJob;
use App\Modules\Imports\Services\CoverProcessor;
use App\Modules\Localization\Models\Language;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EditionAdminController
{
    public function __construct(private readonly CatalogWriter $writer) {}

    public function index(Request $request): View
    {
        $editions = Edition::query()->with('work')
            ->when(in_array($request->query('status'), Edition::STATUSES, true), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->string('q')->trim()->value(), fn ($q, $term) => $q->where('title', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->latest('updated_at')->paginate(40)->withQueryString();

        return view('admin.editions.index', compact('editions'));
    }

    public function create(int $work): View
    {
        $work = Work::findOrFail($work);

        return view('admin.editions.create', ['work' => $work, 'languages' => Language::map()]);
    }

    public function store(Request $request, int $work): RedirectResponse
    {
        $work = Work::findOrFail($work);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'language_tag' => ['required', Rule::in(array_keys(Language::map()))],
        ]);

        // Several editions may share a language; nothing here prevents that.
        $edition = $work->editions()->create($data + [
            'slug' => $this->writer->uniqueSlug(Edition::class, $data['title'].' '.$data['language_tag']),
            'direction' => Language::directionFor($data['language_tag']),
            'status' => 'draft',
        ]);
        $this->writer->reindex($edition);
        AuditEvent::record('edition.created', $edition, ['title' => $edition->title]);

        return redirect()->route('admin.editions.edit', $edition->id)->with('status', 'Draft edition created.');
    }

    public function edit(Edition $edition): View
    {
        $edition->load(['work', 'contributors', 'files' => fn ($q) => $q->orderBy('format')->orderByDesc('version'), 'files.edition']);

        return view('admin.editions.edit', [
            'edition' => $edition,
            'languages' => Language::map(),
            'contributors' => Contributor::query()->orderBy('sort_name')->get(['id', 'name']),
            'imports' => ImportJob::query()->where('edition_id', $edition->id)->latest()->limit(20)->get()->keyBy('edition_file_id'),
            'blockers' => $edition->publicationBlockers(),
            'audit' => AuditEvent::query()->with('user')
                ->where(fn ($q) => $q->where('subject_type', 'edition')->where('subject_id', $edition->id))
                ->orWhere(fn ($q) => $q->where('subject_type', 'edition_file')->whereIn('subject_id', $edition->files->pluck('id')))
                ->latest('created_at')->limit(30)->get(),
        ]);
    }

    public function update(Request $request, Edition $edition): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:190', Rule::unique('editions', 'slug')->ignore($edition->id)],
            'language_tag' => ['required', Rule::in(array_keys(Language::map()))],
            'direction' => ['required', 'in:ltr,rtl,auto'],
            'page_progression' => ['required', 'in:ltr,rtl,default'],
            'description' => ['nullable', 'string', 'max:10000'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'published_date' => ['nullable', 'string', 'max:20'],
            'isbn' => ['nullable', 'string', 'max:32'],
            'edition_statement' => ['nullable', 'string', 'max:255'],
            'source_name' => ['nullable', 'string', 'max:255'],
            // Stored and displayed as a link only; the server never requests it.
            'source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'attribution' => ['nullable', 'string', 'max:5000'],
            'rights_status' => ['required', Rule::in(Edition::RIGHTS)],
            'license_name' => ['nullable', 'string', 'max:255'],
            'license_url' => ['nullable', 'url:http,https', 'max:2048'],
            'rights_holder' => ['nullable', 'string', 'max:255'],
            'rights_notes' => ['nullable', 'string', 'max:5000'],
            'territory_notes' => ['nullable', 'string', 'max:5000'],
            'is_featured' => ['nullable', 'boolean'],
            'translators' => ['array'],
            'translators.*' => ['integer', 'exists:contributors,id'],
            'editors' => ['array'],
            'editors.*' => ['integer', 'exists:contributors,id'],
            'new_translators' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($edition, $data) {
            $edition->update(collect($data)->except(['translators', 'editors', 'new_translators'])->all()
                + ['is_featured' => false]);

            $translators = $data['translators'] ?? [];
            foreach (array_filter(array_map('trim', explode(';', $data['new_translators'] ?? ''))) as $name) {
                $translators[] = $this->writer->contributor($name)->id;
            }
            $this->writer->syncContributors($edition, 'translator', $translators);
            $this->writer->syncContributors($edition, 'editor', $data['editors'] ?? []);
            $this->writer->reindex($edition);
        });

        // A published edition must keep satisfying the publication rules.
        if ($edition->isPublished() && ($blockers = $edition->fresh()->publicationBlockers())) {
            $this->setStatus($edition, 'review', 'edition.unpublished', ['reason' => 'No longer meets publication requirements']);

            return back()->withErrors(['publish' => array_merge(['The edition was unpublished because it no longer meets the requirements:'], $blockers)]);
        }

        AuditEvent::record('edition.updated', $edition);
        Cache::forget('catalog.facets');

        return back()->with('status', 'Edition saved.');
    }

    public function publish(Edition $edition): RedirectResponse
    {
        if ($blockers = $edition->publicationBlockers()) {
            return back()->withErrors(['publish' => $blockers]);
        }

        $edition->forceFill(['published_at' => $edition->published_at ?? now(), 'withdrawn_at' => null, 'withdrawn_reason' => null]);
        $this->setStatus($edition, 'published', 'edition.published');

        return back()->with('status', 'Edition published.');
    }

    /** Reversible: the edition returns to review with everything intact. */
    public function unpublish(Edition $edition): RedirectResponse
    {
        abort_unless($edition->isPublished(), 422);
        $this->setStatus($edition, 'review', 'edition.unpublished');

        return back()->with('status', 'Edition unpublished. It is no longer public and can be published again.');
    }

    public function withdraw(Request $request, Edition $edition): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $edition->forceFill(['withdrawn_at' => now(), 'withdrawn_reason' => $data['reason']]);
        $this->setStatus($edition, 'withdrawn', 'edition.withdrawn', ['reason' => $data['reason']]);

        return back()->with('status', 'Edition withdrawn. Copies already downloaded or saved offline cannot be recalled.');
    }

    public function cover(Request $request, Edition $edition, CoverProcessor $covers): RedirectResponse
    {
        $request->validate(['cover' => ['required', 'file', 'max:'.(int) (config('library.imports.max_cover_bytes') / 1024)]]);

        try {
            $path = $covers->store((string) file_get_contents($request->file('cover')->getRealPath()));
        } catch (ImportRejected $e) {
            return back()->withErrors(['cover' => $e->getMessage()]);
        }

        $covers->delete($edition->cover_path);
        $edition->update(['cover_path' => $path]);
        AuditEvent::record('edition.cover_changed', $edition);

        return back()->with('status', 'Cover updated.');
    }

    public function destroy(Edition $edition, CoverProcessor $covers): RedirectResponse
    {
        if ($edition->published_at !== null) {
            return back()->withErrors(['publish' => 'An edition that has been public is withdrawn, not deleted, so its history is kept.']);
        }

        foreach ($edition->files as $file) {
            $disk = Storage::disk($file->storage_disk);
            $disk->delete($file->storage_path);
            if ($file->reading_path) {
                $disk->deleteDirectory($file->reading_path);
            }
        }
        $covers->delete($edition->cover_path);
        AuditEvent::record('edition.deleted', $edition, ['title' => $edition->title]);
        $workId = $edition->work_id;
        $edition->delete();

        return redirect()->route('admin.works.edit', $workId)->with('status', 'Draft edition deleted.');
    }

    private function setStatus(Edition $edition, string $status, string $action, array $data = []): void
    {
        $from = $edition->getOriginal('status');
        $edition->status = $status;
        $edition->save();
        AuditEvent::record($action, $edition, $data + ['from' => $from, 'to' => $status]);
        Cache::forget('catalog.facets');
    }
}
