@extends('admin.layout')
@section('admin-title', 'CSV metadata import')

@section('admin')
    <p>Creates or updates works and <strong>draft</strong> editions from a spreadsheet. It never publishes anything and never attaches files. Every row is validated first; if any row is wrong, nothing is imported.</p>
    <p><a href="{{ route('admin.csv.template') }}">Download a template</a> · Limit: {{ \App\Modules\Imports\Services\CsvMetadataImporter::MAX_ROWS }} rows, UTF-8, comma-separated. Use <code>;</code> between several authors, translators, categories or tags.</p>

    @if ($summary = session('csv_summary'))
        @if ($summary['errors'])
            <div class="alert alert-danger" role="alert">
                <p><strong>{{ count($summary['errors']) }} row(s) have problems. Nothing was imported.</strong></p>
                <ul>
                    @foreach ($summary['errors'] as $error)
                        <li>Line {{ $error['line'] }}: {{ implode(' ', $error['messages']) }}</li>
                    @endforeach
                </ul>
            </div>
        @else
            <div class="alert alert-success" role="status">
                @if ($summary['dry_run'])
                    Check passed: {{ $summary['rows'] }} row(s) are valid. Nothing was changed.
                @else
                    Imported {{ $summary['rows'] }} row(s): {{ $summary['created_works'] }} new works, {{ $summary['created_editions'] }} new draft editions, {{ $summary['updated_editions'] }} editions updated.
                @endif
            </div>
        @endif
    @endif

    <form method="post" action="{{ route('admin.csv.store') }}" enctype="multipart/form-data" class="narrow">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="csv">CSV file</label>
            <input class="form-control" id="csv" name="csv" type="file" required accept=".csv,text/csv">
        </div>
        <div class="mb-3 form-check">
            <input class="form-check-input" id="dry_run" type="checkbox" name="dry_run" value="1" checked>
            <label class="form-check-label" for="dry_run">Check only — do not import yet</label>
        </div>
        <button type="submit" class="btn btn-primary">Upload</button>
    </form>

    <details>
        <summary>Columns</summary>
        <p><code dir="ltr">{{ implode(', ', $columns) }}</code></p>
        <p>Required: original_title, original_language, language, title. A row whose <code>edition_slug</code> matches an existing edition updates it (its publication status is never changed).</p>
    </details>

    <h2>Recent CSV imports</h2>
    <ul class="list-unstyled">
        @forelse ($history as $import)
            <li><code>{{ $import->created_at->format('Y-m-d H:i') }}</code> <bdi>{{ $import->original_filename }}</bdi> — <span class="badge rounded-pill status status--{{ $import->status === 'failed' ? 'failed' : 'ready' }}">{{ $import->status }}</span>
                {{ ($import->report['dry_run'] ?? false) ? '(check only)' : '' }} {{ $import->error }} <span class="text-body-secondary small">by <bdi>{{ $import->user?->name }}</bdi></span></li>
        @empty
            <li class="text-body-secondary small">None yet.</li>
        @endforelse
    </ul>
@endsection
