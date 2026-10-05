@php
    $selectedAuthors = old('authors', $work->exists ? $work->authors->pluck('id')->all() : []);
    $selectedCategories = old('categories', $work->exists ? $work->categories->pluck('id')->all() : []);
    $selectedCollections = old('collections', $work->exists ? $work->collections->pluck('id')->all() : []);
@endphp
@extends('admin.layout')
@section('admin-title', $work->exists ? 'Edit work' : 'New work')

@section('admin')
    <p class="text-body-secondary small">A work is the underlying book. Its translations and published versions are added below as editions.</p>

    <form method="post" action="{{ $work->exists ? route('admin.works.update', $work->id) : route('admin.works.store') }}">
        @csrf
        @if ($work->exists) @method('PUT') @endif

        <div class="row row-cols-1 row-cols-md-2 gx-4">
            <div class="mb-3">
                <label class="form-label" for="original_title">Original title</label>
                <input class="form-control" id="original_title" name="original_title" type="text" value="{{ old('original_title', $work->original_title) }}" required maxlength="255" dir="auto">
            </div>
            <div class="mb-3">
                <label class="form-label" for="original_language_tag">Original language</label>
                <select class="form-select" id="original_language_tag" name="original_language_tag" required>
                    @foreach ($languages as $tag => $language)
                        <option value="{{ $tag }}" @selected(old('original_language_tag', $work->original_language_tag) === $tag)>{{ $language['english_name'] }} ({{ $tag }})</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="first_published">First published (year)</label>
                <input class="form-control" id="first_published" name="first_published" type="text" value="{{ old('first_published', $work->first_published) }}" maxlength="20">
            </div>
            <div class="mb-3">
                <label class="form-label" for="slug">URL slug (leave empty to generate)</label>
                <input class="form-control" id="slug" name="slug" type="text" value="{{ old('slug', $work->slug) }}" maxlength="160" dir="ltr">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="summary">Summary</label>
            <textarea class="form-control" id="summary" name="summary" rows="3" dir="auto">{{ old('summary', $work->summary) }}</textarea>
        </div>

        <fieldset>
            <legend>Title in other catalog languages</legend>
            <div class="row row-cols-1 row-cols-md-2 gx-4">
                @foreach ($languages as $tag => $language)
                    <div class="mb-3">
                        <label class="form-label" for="tr-{{ $tag }}">{{ $language['english_name'] }}</label>
                        <input class="form-control" id="tr-{{ $tag }}" name="translations[{{ $tag }}][title]" type="text" lang="{{ $tag }}" dir="{{ $language['direction'] }}"
                               value="{{ old("translations.$tag.title", $work->exists ? $work->translations->where('language_tag', $tag)->firstWhere('field', 'title')?->value : '') }}">
                    </div>
                @endforeach
            </div>
        </fieldset>

        <div class="row row-cols-1 row-cols-md-2 gx-4">
            <div class="mb-3">
                <label class="form-label" for="authors">Authors (hold Ctrl/Cmd to choose several)</label>
                <select class="form-select" id="authors" name="authors[]" multiple size="6">
                    @foreach ($contributors as $contributor)
                        <option value="{{ $contributor->id }}" @selected(in_array($contributor->id, $selectedAuthors))>{{ $contributor->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="new_authors">New authors (separate with ;)</label>
                <input class="form-control" id="new_authors" name="new_authors" type="text" value="{{ old('new_authors') }}" dir="auto">
            </div>
            <div class="mb-3">
                <label class="form-label" for="categories">Categories</label>
                <select class="form-select" id="categories" name="categories[]" multiple size="5">
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(in_array($category->id, $selectedCategories))>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="collections">Collections</label>
                <select class="form-select" id="collections" name="collections[]" multiple size="5">
                    @foreach ($collections as $collection)
                        <option value="{{ $collection->id }}" @selected(in_array($collection->id, $selectedCollections))>{{ $collection->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="tags">Tags (separate with ;)</label>
            <input class="form-control" id="tags" name="tags" type="text" value="{{ old('tags', $work->exists ? $work->tags->pluck('name')->implode('; ') : '') }}" dir="auto">
        </div>

        <button type="submit" class="btn btn-primary">Save work</button>
    </form>

    @if ($work->exists)
        <h2>Editions</h2>
        <p><a class="btn btn-primary btn-sm" href="{{ route('admin.editions.create', $work->id) }}">Add an edition or translation</a></p>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th scope="col">Title</th><th scope="col">Language</th><th scope="col">Status</th><th scope="col">Files</th></tr></thead>
                <tbody>
                    @forelse ($editions as $edition)
                        <tr>
                            <td><a href="{{ route('admin.editions.edit', $edition->id) }}"><bdi>{{ $edition->title }}</bdi></a></td>
                            <td>{{ $edition->language_tag }}</td>
                            <td><span class="badge rounded-pill status status--{{ $edition->status }}">{{ $edition->status }}</span></td>
                            <td>{{ $edition->files->where('is_current', true)->pluck('format')->implode(', ') ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No editions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <form method="post" action="{{ route('admin.works.destroy', $work->id) }}">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-danger btn-sm">Delete work</button>
        </form>
    @endif
@endsection
