@extends('admin.layout')
@section('admin-title', 'Dashboard')

@section('admin')
    <ul class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-3 list-unstyled">
        @foreach (\App\Modules\Catalog\Models\Edition::STATUSES as $status)
            <li class="col"><div class="card card-body h-100"><strong class="fs-2 lh-1">{{ $counts[$status] ?? 0 }}</strong> <span class="text-body-secondary"><a href="{{ route('admin.editions.index', ['status' => $status]) }}">{{ $status }}</a></span></div></li>
        @endforeach
        <li class="col"><div class="card card-body h-100"><strong class="fs-2 lh-1">{{ $openReports }}</strong> <span class="text-body-secondary"><a href="{{ route('admin.reports.index') }}">open reports</a></span></div></li>
        <li class="col"><div class="card card-body h-100"><strong class="fs-2 lh-1">{{ $failedQueueJobs }}</strong> <span class="text-body-secondary">failed queue jobs</span></div></li>
    </ul>

    @if ($failedQueueJobs > 0)
        <p class="alert alert-warning">Some background jobs failed after all retries. Details are in the <code>failed_jobs</code> table and the application log.</p>
    @endif

    <h2>Waiting for review</h2>
    @if ($reviewQueue->isEmpty())
        <p class="text-body-secondary small">Nothing is waiting.</p>
    @else
        <ul class="list-unstyled">
            @foreach ($reviewQueue as $edition)
                <li><a href="{{ route('admin.editions.edit', $edition->id) }}"><bdi>{{ $edition->title }}</bdi></a> <span class="text-body-secondary small">({{ $edition->language_tag }})</span></li>
            @endforeach
        </ul>
    @endif

    <h2>Imports in progress</h2>
    @if ($activeImports->isEmpty())
        <p class="text-body-secondary small">No imports are running. Queued files are processed by the scheduled task.</p>
    @else
        <ul class="list-unstyled">
            @foreach ($activeImports as $import)
                <li>
                    <bdi>{{ $import->original_filename }}</bdi> — {{ $import->status }} / {{ $import->stage }}
                    @if ($import->edition) · <a href="{{ route('admin.editions.edit', $import->edition_id) }}"><bdi>{{ $import->edition->title }}</bdi></a>@endif
                    <span class="text-body-secondary small">({{ $import->updated_at->diffForHumans() }})</span>
                </li>
            @endforeach
        </ul>
    @endif

    <h2>Failed imports</h2>
    @if ($failedImports->isEmpty())
        <p class="text-body-secondary small">No failed imports.</p>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th scope="col">File</th><th scope="col">Edition</th><th scope="col">Reason</th><th scope="col">When</th></tr></thead>
                <tbody>
                    @foreach ($failedImports as $import)
                        <tr>
                            <td><bdi>{{ $import->original_filename }}</bdi> ({{ $import->type }})</td>
                            <td>@if ($import->edition)<a href="{{ route('admin.editions.edit', $import->edition_id) }}"><bdi>{{ $import->edition->title }}</bdi></a>@else — @endif</td>
                            <td>{{ $import->error }}</td>
                            <td>{{ $import->finished_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>Broken published files</h2>
    @if ($brokenFiles->isEmpty())
        <p class="text-body-secondary small">Every published file is present in storage.</p>
    @else
        <ul class="alert alert-danger">
            @foreach ($brokenFiles as $file)
                <li>File #{{ $file->id }} ({{ $file->format }}) of <a href="{{ route('admin.editions.edit', $file->edition_id) }}"><bdi>{{ $file->edition->title }}</bdi></a> is missing from storage.</li>
            @endforeach
        </ul>
    @endif
@endsection
