@extends('admin.layout')
@section('admin-title', 'Audit log')

@section('admin')
    <form method="get" class="d-inline-flex flex-wrap align-items-center gap-2" role="search">
        <label class="form-label mb-0" for="action">Action starts with</label>
        <input class="form-control w-auto" id="action" name="action" type="search" value="{{ request('action') }}" placeholder="edition. / file. / user." dir="ltr">
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Subject</th><th scope="col">Details</th></tr></thead>
            <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td><code>{{ $event->created_at->format('Y-m-d H:i:s') }}</code></td>
                        <td><bdi>{{ $event->user?->name ?? 'system' }}</bdi></td>
                        <td>{{ $event->action }}</td>
                        <td>{{ $event->subject_type ? $event->subject_type.' #'.$event->subject_id : '—' }}</td>
                        <td dir="ltr">{{ $event->data ? json_encode($event->data, JSON_UNESCAPED_UNICODE) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No events.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $events->links() }}
@endsection
