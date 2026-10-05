@extends('admin.layout')
@section('admin-title', 'Reports and messages')

@section('admin')
    <p>
        <a href="{{ route('admin.reports.index') }}" @if($status === 'open') aria-current="page" @endif>Open</a> ·
        <a href="{{ route('admin.reports.index', ['status' => 'resolved']) }}" @if($status === 'resolved') aria-current="page" @endif>Resolved</a>
    </p>

    @forelse ($reports as $report)
        <article class="card card-body mb-3">
            <h2><span class="badge rounded-pill status">{{ $report->kind }}</span> <bdi>{{ $report->name }}</bdi> &lt;<bdi dir="ltr">{{ $report->email }}</bdi>&gt;</h2>
            <p class="text-body-secondary small">{{ $report->created_at->toDayDateTimeString() }}
                @if ($report->edition) · about <a href="{{ route('admin.editions.edit', $report->edition_id) }}"><bdi>{{ $report->edition->title }}</bdi></a>@endif
            </p>
            {{-- Visitor text: escaped, never rendered as HTML. --}}
            <p dir="auto" style="white-space: pre-wrap">{{ $report->message }}</p>
            <form method="post" action="{{ route('admin.reports.update', $report->id) }}">
                @csrf @method('PUT')
                <div class="mb-3">
                    <label class="form-label" for="resolution-{{ $report->id }}">What was done</label>
                    <textarea class="form-control" id="resolution-{{ $report->id }}" name="resolution" rows="2" dir="auto">{{ $report->resolution }}</textarea>
                </div>
                <div class="d-inline-flex flex-wrap align-items-center gap-2">
                    <button type="submit" name="status" value="{{ $status === 'open' ? 'resolved' : 'open' }}" class="btn btn-primary btn-sm">{{ $status === 'open' ? 'Mark resolved' : 'Reopen' }}</button>
                </div>
            </form>
        </article>
    @empty
        <p class="text-body-secondary small">Nothing here.</p>
    @endforelse
    {{ $reports->links() }}
@endsection
