<?php

namespace App\Modules\Imports\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\EditionFile;
use App\Modules\Imports\ImportRejected;
use App\Modules\Imports\Jobs\ProcessImport;
use App\Modules\Imports\Models\AuditEvent;
use App\Modules\Imports\Models\ImportJob;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * quarantine → validation → extraction → review → (editor approves) → current.
 *
 * A file only becomes publicly reachable after it is `ready`, an editor has
 * made it current and granted a permission, and its edition is published.
 */
class ImportPipeline
{
    /** Seconds of work per queue run before the job yields and resumes later. */
    private const TIME_BUDGET = 20.0;

    public function __construct(
        private readonly EpubProcessor $epub,
        private readonly PdfInspector $pdf,
    ) {}

    public function disk(): Filesystem
    {
        return Storage::disk(config('library.storage_disk'));
    }

    /** @return ?EditionFile an existing file with identical content, if any */
    public function findDuplicate(string $sha256): ?EditionFile
    {
        return EditionFile::query()->where('sha256', $sha256)->where('import_status', '!=', 'failed')
            ->with('edition')->first();
    }

    /**
     * Accepts an upload into quarantine and queues it for processing.
     *
     * @throws ImportRejected
     */
    public function intake(UploadedFile $upload, Edition $edition, ?User $user, bool $allowDuplicate = false): ImportJob
    {
        if (! $upload->isValid()) {
            throw new ImportRejected('The upload did not complete: '.$upload->getErrorMessage());
        }
        if ($upload->getSize() > config('library.imports.max_upload_bytes')) {
            throw new ImportRejected('The file is larger than the configured upload limit.');
        }

        $format = strtolower($upload->getClientOriginalExtension());
        $format = $format === 'htm' ? 'html' : $format;
        if (! isset(EditionFile::FORMATS[$format])) {
            throw new ImportRejected('Only EPUB, PDF, TXT and HTML files are accepted.');
        }
        $this->checkSniffedType($upload->getRealPath(), $format);

        $sha256 = hash_file('sha256', $upload->getRealPath());
        $duplicate = $this->findDuplicate($sha256);
        if ($duplicate && ! $allowDuplicate) {
            throw new ImportRejected(sprintf(
                'This exact file is already stored for "%s" (file #%d). Tick "allow duplicate" to store it again.',
                $duplicate->edition->title, $duplicate->id
            ));
        }

        $quarantinePath = 'quarantine/'.Str::uuid().'.upload';
        $this->disk()->putFileAs('quarantine', $upload, basename($quarantinePath));

        return DB::transaction(function () use ($edition, $user, $upload, $format, $sha256, $quarantinePath) {
            $version = (int) $edition->files()->where('format', $format)->max('version') + 1;

            $file = $edition->files()->create([
                'format' => $format,
                'mime_type' => EditionFile::FORMATS[$format],
                'byte_size' => $upload->getSize(),
                'sha256' => $sha256,
                'storage_disk' => config('library.storage_disk'),
                'storage_path' => $quarantinePath,
                'version' => $version,
                'is_current' => false,
                'original_filename' => Str::limit(basename($upload->getClientOriginalName()), 200, ''),
                'import_status' => 'quarantined',
                'uploaded_by' => $user?->id,
            ]);

            $job = ImportJob::create([
                'type' => 'file',
                'edition_id' => $edition->id,
                'edition_file_id' => $file->id,
                'user_id' => $user?->id,
                'status' => 'queued',
                'stage' => 'quarantine',
                'original_filename' => $file->original_filename,
                'quarantine_path' => $quarantinePath,
            ]);

            if ($edition->status === 'draft') {
                $edition->update(['status' => 'processing']);
            }

            AuditEvent::record('file.uploaded', $file, ['format' => $format, 'version' => $version, 'sha256' => $sha256]);
            ProcessImport::dispatch($job->id)->afterCommit();

            return $job;
        });
    }

    /**
     * Runs (or resumes) one import.
     *
     * @return bool true when finished, false when more work remains
     *
     * @throws ImportRejected
     */
    public function process(ImportJob $job): bool
    {
        $file = $job->file;
        if (! $file || in_array($job->status, ['review', 'completed', 'failed', 'discarded'], true)) {
            return true;
        }

        $deadline = microtime(true) + self::TIME_BUDGET;
        $job->forceFill([
            'status' => 'running',
            'attempts' => $job->attempts + 1,
            'started_at' => $job->started_at ?? now(),
        ])->save();
        $file->update(['import_status' => 'processing']);

        $source = $this->disk()->path($job->quarantine_path);
        if (! is_file($source)) {
            throw new ImportRejected('The uploaded file is no longer in quarantine.');
        }
        if (! hash_equals($file->sha256, hash_file('sha256', $source))) {
            throw new ImportRejected('The quarantined file changed after upload.');
        }

        $report = $job->report ?? ['warnings' => [], 'notes' => [], 'files' => [], 'cursor' => 0];
        $attributes = [];

        if ($file->format === 'epub') {
            $job->update(['stage' => 'validation']);
            $package = $this->epub->inspect($source);
            $report['warnings'] = $package['warnings'];

            $job->update(['stage' => 'extraction']);
            $temporary = $this->temporaryDirectory($job);
            $state = ['files' => $report['files'], 'notes' => $report['notes']];
            $next = $this->epub->extract($source, $temporary, $package, (int) $report['cursor'], $deadline, $state);
            $report['files'] = $state['files'];
            $report['notes'] = $state['notes'];

            if ($next !== null) {
                $report['cursor'] = $next;
                $job->update(['report' => $report, 'status' => 'queued']);

                return false;
            }

            $readingPath = "reading/{$file->id}-v{$file->version}";
            $this->disk()->deleteDirectory($readingPath);
            File::ensureDirectoryExists(dirname($this->disk()->path($readingPath)));
            if (! @rename($temporary, $this->disk()->path($readingPath))) {
                throw new \RuntimeException('Could not move the reading copy into place.');
            }

            $attributes = [
                'reading_path' => $readingPath,
                'layout' => $package['layout'],
                'manifest' => ['opf' => $package['opf'], 'files' => $report['files'], 'cover' => $package['cover']],
            ];
            $job->extracted_metadata = $package['metadata'] + array_filter(['page_progression' => $package['direction']]);
        } elseif ($file->format === 'pdf') {
            $job->update(['stage' => 'validation']);
            $result = $this->pdf->inspect($source);
            $report['warnings'] = $result['warnings'];
            $attributes = ['page_count' => $result['page_count'], 'layout' => 'fixed'];
            $job->extracted_metadata = $result['metadata'];
        } else {
            $job->update(['stage' => 'validation']);
            $this->checkPlainFile($source, $file->format);
        }

        $final = sprintf('originals/%d/%d-v%d.%s', $file->edition_id, $file->id, $file->version, $file->format);
        $this->disk()->delete($final);
        if (! $this->disk()->move($job->quarantine_path, $final)) {
            throw new \RuntimeException('Could not move the file out of quarantine.');
        }

        unset($report['cursor']);
        DB::transaction(function () use ($file, $job, $attributes, $final, $report) {
            $file->update($attributes + [
                'storage_path' => $final,
                'import_status' => 'ready',
                'validation_report' => ['warnings' => $report['warnings'], 'notes' => array_slice($report['notes'], 0, 200)],
            ]);
            $job->fill([
                'status' => 'review', 'stage' => 'review', 'report' => $report,
                'quarantine_path' => null, 'finished_at' => now(), 'error' => null,
            ])->save();

            $edition = $file->edition;
            if ($edition->status === 'processing') {
                $edition->update(['status' => 'review']);
            }
        });

        return true;
    }

    /** Records a failure and removes everything the import left behind. */
    public function fail(ImportJob $job, string $message): void
    {
        $this->cleanTemporary($job);
        if ($job->quarantine_path) {
            $this->disk()->delete($job->quarantine_path);
        }

        $job->forceFill([
            'status' => 'failed', 'error' => Str::limit($message, 2000), 'finished_at' => now(), 'quarantine_path' => null,
        ])->save();

        if ($file = $job->file) {
            $file->update(['import_status' => 'failed', 'is_current' => false, 'can_read' => false, 'can_download' => false, 'can_offline' => false]);
            $edition = $file->edition;
            if ($edition && $edition->status === 'processing'
                && ! $edition->files()->whereIn('import_status', ['quarantined', 'processing'])->exists()) {
                $edition->update(['status' => $edition->files()->where('import_status', 'ready')->exists() ? 'review' : 'draft']);
            }
        }

        logger()->warning('Import failed', ['import_job' => $job->id, 'reason' => $message]);
    }

    /**
     * Makes a reviewed file the current version of its format. The previous
     * version stays stored (for audit and for existing reading positions) but
     * is no longer offered.
     */
    public function approve(EditionFile $file): void
    {
        abort_unless($file->import_status === 'ready', 422, 'Only successfully imported files can be made current.');

        DB::transaction(function () use ($file) {
            EditionFile::query()->where('edition_id', $file->edition_id)->where('format', $file->format)
                ->where('id', '!=', $file->id)->where('is_current', true)
                ->update(['is_current' => false]);
            $file->update(['is_current' => true]);
            ImportJob::query()->where('edition_file_id', $file->id)->where('status', 'review')->update(['status' => 'completed']);
            AuditEvent::record('file.approved', $file, ['format' => $file->format, 'version' => $file->version]);
        });
    }

    /** Fails imports that never finished and deletes stray temporary data. */
    public function cleanup(): array
    {
        $cutoff = now()->subHours(config('library.imports.stale_hours'));
        $failed = 0;

        ImportJob::query()->whereIn('status', ['queued', 'running'])->where('updated_at', '<', $cutoff)
            ->each(function (ImportJob $job) use (&$failed) {
                $this->fail($job, 'The import did not finish and was cleaned up automatically.');
                $failed++;
            });

        $removed = 0;
        $disk = $this->disk();
        foreach ($disk->files('quarantine') as $path) {
            if ($disk->lastModified($path) < $cutoff->getTimestamp()
                && ! ImportJob::query()->where('quarantine_path', $path)->whereIn('status', ['queued', 'running'])->exists()) {
                $disk->delete($path);
                $removed++;
            }
        }
        foreach ($disk->directories('tmp') as $directory) {
            $id = (int) Str::after($directory, 'tmp/import-');
            if (! ImportJob::query()->whereKey($id)->whereIn('status', ['queued', 'running'])->exists()) {
                $disk->deleteDirectory($directory);
                $removed++;
            }
        }

        return ['failed' => $failed, 'removed' => $removed];
    }

    private function temporaryDirectory(ImportJob $job): string
    {
        $path = $this->disk()->path("tmp/import-{$job->id}");
        File::ensureDirectoryExists($path);

        return $path;
    }

    private function cleanTemporary(ImportJob $job): void
    {
        $this->disk()->deleteDirectory("tmp/import-{$job->id}");
    }

    private function checkSniffedType(string $path, string $format): void
    {
        $head = (string) file_get_contents($path, false, null, 0, 1024);
        $ok = match ($format) {
            'epub' => str_starts_with($head, "PK\x03\x04"),
            'pdf' => str_starts_with(ltrim($head), '%PDF-'),
            'txt', 'html' => ! str_contains($head, "\0") && mb_check_encoding($head === '' ? '' : mb_strcut($head, 0, 1000, 'UTF-8'), 'UTF-8'),
        };

        if (! $ok) {
            throw new ImportRejected('The file content does not match its extension.');
        }
    }

    private function checkPlainFile(string $path, string $format): void
    {
        $handle = fopen($path, 'rb');
        try {
            $carry = '';
            while (! feof($handle)) {
                $chunk = $carry.fread($handle, 1048576);
                if (str_contains($chunk, "\0")) {
                    throw new ImportRejected('The file contains binary data.');
                }
                // Keep a possibly split multi-byte character for the next chunk.
                $valid = mb_strcut($chunk, 0, strlen($chunk), 'UTF-8');
                $carry = substr($chunk, strlen($valid));
                if (strlen($carry) > 3 || ! mb_check_encoding($valid, 'UTF-8')) {
                    throw new ImportRejected('The file is not valid UTF-8 text.');
                }
            }
            if ($carry !== '') {
                throw new ImportRejected('The file is not valid UTF-8 text.');
            }
        } finally {
            fclose($handle);
        }
    }
}
