<?php

namespace App\Modules\Administration\Http;

use App\Modules\Imports\ImportRejected;
use App\Modules\Imports\Models\AuditEvent;
use App\Modules\Imports\Models\ImportJob;
use App\Modules\Imports\Services\CsvMetadataImporter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CsvImportController
{
    public function create(): View
    {
        return view('admin.csv', [
            'columns' => CsvMetadataImporter::COLUMNS,
            'history' => ImportJob::query()->where('type', 'csv')->with('user')->latest()->limit(10)->get(),
        ]);
    }

    public function store(Request $request, CsvMetadataImporter $importer): RedirectResponse
    {
        $request->validate([
            'csv' => ['required', 'file', 'max:2048', 'extensions:csv,txt'],
            'dry_run' => ['nullable', 'boolean'],
        ]);
        $dryRun = $request->boolean('dry_run');

        try {
            $summary = $importer->import($request->file('csv')->getRealPath(), $dryRun);
        } catch (ImportRejected $e) {
            return back()->withErrors(['csv' => $e->getMessage()]);
        }

        $failed = $summary['errors'] !== [];
        ImportJob::create([
            'type' => 'csv',
            'user_id' => $request->user()->id,
            'status' => $failed ? 'failed' : 'completed',
            'stage' => $dryRun ? 'validation' : 'review',
            'original_filename' => Str::limit(basename($request->file('csv')->getClientOriginalName()), 200, ''),
            'report' => $summary + ['dry_run' => $dryRun],
            'error' => $failed ? count($summary['errors']).' row(s) failed validation; nothing was imported.' : null,
            'finished_at' => now(),
        ]);
        if (! $failed && ! $dryRun) {
            AuditEvent::record('csv.imported', null, collect($summary)->except('errors')->all());
        }

        return back()->with('csv_summary', $summary + ['dry_run' => $dryRun]);
    }

    public function template(): Response
    {
        $example = [
            '', 'A Sample Title', 'en', 'First Author; Second Author', '1900',
            '', 'en', 'ltr', 'A Sample Title', '', 'Short description.', '',
            'Example Press', '2024', '', '', 'Example source', 'https://example.org/source',
            'Text prepared by Example Press.', 'public_domain', '', '', '', '',
            'Fiction', 'sample; demo',
        ];
        $csv = implode(',', CsvMetadataImporter::COLUMNS)."\n"
            .implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $example))."\n";

        return response("\xEF\xBB\xBF".$csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="metadata-import-template.csv"',
        ]);
    }
}
