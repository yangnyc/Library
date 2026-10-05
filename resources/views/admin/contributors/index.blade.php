@extends('admin.layout')
@section('admin-title', 'Contributors')

@section('admin')
    <form method="get" class="d-inline-flex flex-wrap align-items-center gap-2" role="search">
        <label class="form-label mb-0" for="q">Search names</label>
        <input class="form-control w-auto" id="q" name="q" type="search" value="{{ request('q') }}" dir="auto">
        <button class="btn btn-primary btn-sm" type="submit">Search</button>
        <a class="btn btn-primary btn-sm" href="{{ route('admin.contributors.create') }}">New contributor</a>
    </form>

    <ul class="list-unstyled">
        @forelse ($contributors as $contributor)
            <li><a href="{{ route('admin.contributors.edit', $contributor->id) }}"><bdi>{{ $contributor->name }}</bdi></a></li>
        @empty
            <li>No contributors yet.</li>
        @endforelse
    </ul>
    {{ $contributors->links() }}
@endsection
