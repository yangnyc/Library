@extends('admin.layout')
@section('admin-title', 'Works')

@section('admin')
    <form method="get" class="d-inline-flex flex-wrap align-items-center gap-2" role="search">
        <label class="form-label mb-0" for="q">Search titles</label>
        <input class="form-control w-auto" id="q" name="q" type="search" value="{{ request('q') }}" dir="auto">
        <button class="btn btn-primary btn-sm" type="submit">Search</button>
        <a class="btn btn-primary btn-sm" href="{{ route('admin.works.create') }}">New work</a>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th scope="col">Original title</th><th scope="col">Authors</th><th scope="col">Language</th><th scope="col">Editions</th></tr></thead>
            <tbody>
                @forelse ($works as $work)
                    <tr>
                        <td><a href="{{ route('admin.works.edit', $work->id) }}"><bdi>{{ $work->original_title }}</bdi></a></td>
                        <td>@foreach ($work->authors as $author)<bdi>{{ $author->name }}</bdi>@if(! $loop->last), @endif @endforeach</td>
                        <td>{{ $work->original_language_tag }}</td>
                        <td>{{ $work->editions_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No works yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $works->links() }}
@endsection
