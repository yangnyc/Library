@php
    $translation = fn (string $tag, string $field) => old("translations.$tag.$field",
        $item->translations->where('language_tag', $tag)->firstWhere('field', $field)?->value);
@endphp
@extends('admin.layout')
@section('admin-title', 'Edit '.rtrim($type === 'categories' ? 'category' : $type, 's'))

@section('admin')
    <p><a href="{{ route('admin.taxonomy.index') }}">Back to the list</a></p>

    <form method="post" action="{{ route('admin.taxonomy.update', [$type, $item->id]) }}">
        @csrf @method('PUT')
        <div class="row row-cols-1 row-cols-md-2 gx-4">
            <div class="mb-3"><label class="form-label" for="name">Name</label><input class="form-control" id="name" name="name" type="text" value="{{ old('name', $item->name) }}" required dir="auto"></div>
            <div class="mb-3"><label class="form-label" for="slug">URL slug</label><input class="form-control" id="slug" name="slug" type="text" value="{{ old('slug', $item->slug) }}" required dir="ltr"></div>
            @if ($type !== 'tags')
                <div class="mb-3"><label class="form-label" for="position">Order (lower comes first)</label><input class="form-control" id="position" name="position" type="number" min="0" value="{{ old('position', $item->position) }}"></div>
            @endif
        </div>

        @if ($type === 'collections')
            <div class="mb-3"><label class="form-label" for="description">Description</label><textarea class="form-control" id="description" name="description" rows="3" dir="auto">{{ old('description', $item->description) }}</textarea></div>
            <div class="mb-3 form-check"><input class="form-check-input" id="is_published" type="checkbox" name="is_published" value="1" @checked(old('is_published', $item->is_published))><label class="form-check-label" for="is_published">Visible to the public</label></div>
            <div class="mb-3 form-check"><input class="form-check-input" id="is_featured" type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $item->is_featured))><label class="form-check-label" for="is_featured">Featured on the home page</label></div>
        @endif

        @foreach ($languages as $tag => $language)
            <fieldset>
                <legend>{{ $language['english_name'] }} catalog text</legend>
                <div class="mb-3">
                    <label class="form-label" for="tr-name-{{ $tag }}">Name</label>
                    <input class="form-control" id="tr-name-{{ $tag }}" name="translations[{{ $tag }}][name]" type="text" value="{{ $translation($tag, 'name') }}" lang="{{ $tag }}" dir="{{ $language['direction'] }}">
                </div>
                @if ($type === 'collections')
                    <div class="mb-3">
                        <label class="form-label" for="tr-desc-{{ $tag }}">Description</label>
                        <textarea class="form-control" id="tr-desc-{{ $tag }}" name="translations[{{ $tag }}][description]" rows="2" lang="{{ $tag }}" dir="{{ $language['direction'] }}">{{ $translation($tag, 'description') }}</textarea>
                    </div>
                @endif
            </fieldset>
        @endforeach

        @if ($type === 'collections' && $works->isNotEmpty())
            <fieldset>
                <legend>Order of works in this collection</legend>
                @foreach ($works as $work)
                    <div class="d-inline-flex flex-wrap align-items-center gap-2">
                        <input class="form-control" id="order-{{ $work->id }}" name="work_order[{{ $work->id }}]" type="number" min="0" value="{{ $work->pivot->position }}" style="inline-size: 6rem">
                        <label class="form-label" for="order-{{ $work->id }}"><bdi>{{ $work->original_title }}</bdi></label>
                    </div>
                @endforeach
            </fieldset>
        @endif

        <button type="submit" class="btn btn-primary">Save</button>
    </form>

    <form method="post" action="{{ route('admin.taxonomy.destroy', [$type, $item->id]) }}">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
    </form>
@endsection
