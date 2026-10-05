@extends('admin.layout')
@section('admin-title', 'Categories, collections and tags')

@section('admin')
    @foreach (['collections' => ['Curated collections', $collections], 'categories' => ['Categories', $categories], 'tags' => ['Tags', $tags]] as $type => [$heading, $items])
        <section aria-labelledby="{{ $type }}-heading">
            <h2 id="{{ $type }}-heading">{{ $heading }}</h2>
            <ul class="list-unstyled">
                @forelse ($items as $item)
                    <li>
                        <a href="{{ route('admin.taxonomy.edit', [$type, $item->id]) }}"><bdi>{{ $item->name }}</bdi></a>
                        <span class="text-body-secondary small">({{ $item->works_count }} works)</span>
                        @if ($type === 'collections')
                            @if ($item->is_featured)<span class="badge rounded-pill status status--published">featured</span>@endif
                            @unless ($item->is_published)<span class="badge rounded-pill status">hidden</span>@endunless
                        @endif
                    </li>
                @empty
                    <li class="text-body-secondary small">None yet.</li>
                @endforelse
            </ul>
            <form method="post" action="{{ route('admin.taxonomy.store', $type) }}" class="d-inline-flex flex-wrap align-items-center gap-2">
                @csrf
                <label class="form-label mb-0" for="new-{{ $type }}">New name</label>
                <input class="form-control w-auto" id="new-{{ $type }}" name="name" type="text" required maxlength="255" dir="auto">
                <button type="submit" class="btn btn-primary btn-sm">Add</button>
            </form>
        </section>
    @endforeach
    <p class="text-body-secondary small">Works are put into categories and collections on each work’s page.</p>
@endsection
