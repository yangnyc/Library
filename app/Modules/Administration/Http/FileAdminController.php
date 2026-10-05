<?php

namespace App\Modules\Administration\Http;

use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\EditionFile;
use App\Modules\Catalog\Services\CatalogWriter;
use App\Modules\Imports\ImportRejected;
use App\Modules\Imports\Models\AuditEvent;
use App\Modules\Imports\Models\ImportJob;
use App\Modules\Imports\Services\CoverProcessor;
use App\Modules\Imports\Services\EpubProcessor;
use App\Modules\Imports\Services\ImportPipeline;
use App\Modules\Localization\Models\Language;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class FileAdminController
{
    public function __construct(private readonly ImportPipeline $pipeline) {}

    public function store(Request $request, Edition $edition): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) (config('library.imports.max_upload_bytes') / 1024)],
            'allow_duplicate' => ['nullable', 'boolean'],
        ]);

        try {
            $this->pipeline->intake($request->file('file'), $edition, $request->user(), $request->boolean('allow_duplicate'));
        } catch (ImportRejected $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('status', 'File received and queued. It stays private until it has been processed, reviewed and approved.');
    }

    /** Access controls and review notes. These are permissions, not DRM. */
    public function update(Request $request, EditionFile $file): RedirectResponse
    {
        $data = $request->validate([
            'can_read' => ['nullable', 'boolean'],
            'can_download' => ['nullable', 'boolean'],
            'can_offline' => ['nullable', 'boolean'],
            'has_text_layer' => ['nullable', 'in:unknown,yes,no'],
            'license_name' => ['nullable', 'string', 'max:255'],
            'rights_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($file->import_status !== 'ready') {
            return back()->withErrors(['file' => 'Permissions can only be set on a successfully imported file.']);
        }

        $canRead = (bool) ($data['can_read'] ?? false) && $file->isBrowserReadable();
        $file->update([
            'can_read' => $canRead,
            'can_download' => (bool) ($data['can_download'] ?? false),
            // Offline saving is a kind of reading; it cannot outlive that permission.
            'can_offline' => $canRead && (bool) ($data['can_offline'] ?? false),
            'has_text_layer' => match ($data['has_text_layer'] ?? 'unknown') {
                'yes' => true, 'no' => false, default => null
            },
            'license_name' => $data['license_name'] ?? null,
            'rights_notes' => $data['rights_notes'] ?? null,
        ]);
        AuditEvent::record('file.permissions_changed', $file, $file->only(['can_read', 'can_download', 'can_offline']));

        return back()->with('status', 'File settings saved.');
    }

    public function approve(EditionFile $file): RedirectResponse
    {
        $this->pipeline->approve($file);

        return back()->with('status', "Version {$file->version} is now the current {$file->format} file. Positions and notes made on the earlier version stay attached to that version.");
    }

    /** Copies reviewed (and possibly edited) extracted metadata onto the edition. */
    public function applyMetadata(Request $request, EditionFile $file, CatalogWriter $writer): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'language_tag' => ['nullable', Rule::in(array_keys(Language::map()))],
            'publisher' => ['nullable', 'string', 'max:255'],
            'published_date' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:10000'],
            'page_progression' => ['nullable', 'in:ltr,rtl,default'],
        ]);

        $edition = $file->edition;
        $changes = array_filter($data, fn ($value) => $value !== null && $value !== '');
        if (isset($changes['language_tag'])) {
            $changes['direction'] = Language::directionFor($changes['language_tag']);
        }
        $edition->update($changes);
        $writer->reindex($edition);
        AuditEvent::record('edition.metadata_applied', $edition, ['fields' => array_keys($changes), 'file' => $file->id]);

        return back()->with('status', 'Metadata applied to the edition.');
    }

    public function useCover(EditionFile $file, EpubProcessor $epub, CoverProcessor $covers): RedirectResponse
    {
        abort_unless($file->format === 'epub' && $file->import_status === 'ready', 422);

        try {
            $bytes = $epub->coverBytes(Storage::disk($file->storage_disk)->path($file->storage_path), $file->manifest['cover'] ?? null);
            if ($bytes === null) {
                return back()->withErrors(['cover' => 'This EPUB does not declare a usable cover image.']);
            }
            $path = $covers->store($bytes);
        } catch (ImportRejected $e) {
            return back()->withErrors(['cover' => $e->getMessage()]);
        }

        $covers->delete($file->edition->cover_path);
        $file->edition->update(['cover_path' => $path]);

        return back()->with('status', 'Cover taken from the EPUB.');
    }

    public function destroy(EditionFile $file): RedirectResponse
    {
        $file->load('edition');
        if ($file->is_current && $file->edition->isPublished()) {
            return back()->withErrors(['file' => 'Unpublish the edition or approve another version before deleting the current file.']);
        }

        $disk = Storage::disk($file->storage_disk);
        $disk->delete($file->storage_path);
        if ($file->reading_path) {
            $disk->deleteDirectory($file->reading_path);
        }
        ImportJob::query()->where('edition_file_id', $file->id)->whereIn('status', ['queued', 'running', 'review'])->update(['status' => 'discarded']);
        AuditEvent::record('file.deleted', $file->edition, ['file' => $file->id, 'format' => $file->format, 'version' => $file->version]);
        $file->delete();

        return back()->with('status', 'File deleted.');
    }
}
