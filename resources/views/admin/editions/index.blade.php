@extends('admin.layout')
@section('admin-title', 'Editions')

@section('admin')
    <form method="get" class="d-inline-flex flex-wrap align-items-center gap-2" role="search">
        <label class="form-label mb-0" for="q">Search titles</label>
        <input class="form-control w-auto" id="q" name="q" type="search" value="{{ request('q') }}" dir="auto">
        <label class="form-label mb-0" for="status">Status</label>
        <select class="form-select w-auto" id="status" name="status">
            <option value="">Any</option>
            @foreach (\App\Modules\Catalog\Models\Edition::STATUSES as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
            @endforeach
        </select>
        <button class="btn btn-primary btn-sm" type="submit">Filter</button>
    </form>
    <p class="text-body-secondary small">New editions are created from a work’s page.</p>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th scope="col">Edition</th><th scope="col">Work</th><th scope="col">Language</th><th scope="col">Status</th><th scope="col">Updated</th></tr></thead>
            <tbody>
                @forelse ($editions as $edition)
                    <tr>
                        <td><a href="{{ route('admin.editions.edit', $edition->id) }}"><bdi>{{ $edition->title }}</bdi></a></td>
                        <td><a href="{{ route('admin.works.edit', $edition->work_id) }}"><bdi>{{ $edition->work->original_title }}</bdi></a></td>
                        <td>{{ $edition->language_tag }}</td>
                        <td><span class="badge rounded-pill status status--{{ $edition->status }}">{{ $edition->status }}</span></td>
                        <td>{{ $edition->updated_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No editions match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $editions->links() }}
@endsection
