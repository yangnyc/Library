@php
    $translation = fn (string $tag, string $field) => old("translations.$tag.$field",
        $contributor->exists ? $contributor->translations->where('language_tag', $tag)->firstWhere('field', $field)?->value : '');
@endphp
@extends('admin.layout')
@section('admin-title', $contributor->exists ? 'Edit contributor' : 'New contributor')

@section('admin')
    <form method="post" action="{{ $contributor->exists ? route('admin.contributors.update', $contributor->id) : route('admin.contributors.store') }}">
        @csrf
        @if ($contributor->exists) @method('PUT') @endif

        <div class="row row-cols-1 row-cols-md-2 gx-4">
            <div class="mb-3">
                <label class="form-label" for="name">Name (as usually written)</label>
                <input class="form-control" id="name" name="name" type="text" value="{{ old('name', $contributor->name) }}" required maxlength="255" dir="auto">
            </div>
            <div class="mb-3">
                <label class="form-label" for="sort_name">Sort name (e.g. “Surname, Given”)</label>
                <input class="form-control" id="sort_name" name="sort_name" type="text" value="{{ old('sort_name', $contributor->sort_name) }}" maxlength="255" dir="auto">
            </div>
            <div class="mb-3">
                <label class="form-label" for="name_language_tag">Language of that name</label>
                <select class="form-select" id="name_language_tag" name="name_language_tag">
                    <option value="">Not set</option>
                    @foreach ($languages as $tag => $language)
                        <option value="{{ $tag }}" @selected(old('name_language_tag', $contributor->name_language_tag) === $tag)>{{ $language['english_name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="slug">URL slug (leave empty to generate)</label>
                <input class="form-control" id="slug" name="slug" type="text" value="{{ old('slug', $contributor->slug) }}" maxlength="160" dir="ltr">
            </div>
            <div class="mb-3">
                <label class="form-label" for="born">Born</label>
                <input class="form-control" id="born" name="born" type="text" value="{{ old('born', $contributor->born) }}" maxlength="20">
            </div>
            <div class="mb-3">
                <label class="form-label" for="died">Died</label>
                <input class="form-control" id="died" name="died" type="text" value="{{ old('died', $contributor->died) }}" maxlength="20">
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="biography">Biography</label>
            <textarea class="form-control" id="biography" name="biography" rows="4" dir="auto">{{ old('biography', $contributor->biography) }}</textarea>
        </div>

        @foreach ($languages as $tag => $language)
            <fieldset>
                <legend>{{ $language['english_name'] }} catalog text</legend>
                <div class="mb-3">
                    <label class="form-label" for="tr-name-{{ $tag }}">Name</label>
                    <input class="form-control" id="tr-name-{{ $tag }}" name="translations[{{ $tag }}][name]" type="text" value="{{ $translation($tag, 'name') }}" lang="{{ $tag }}" dir="{{ $language['direction'] }}">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="tr-bio-{{ $tag }}">Biography</label>
                    <textarea class="form-control" id="tr-bio-{{ $tag }}" name="translations[{{ $tag }}][biography]" rows="3" lang="{{ $tag }}" dir="{{ $language['direction'] }}">{{ $translation($tag, 'biography') }}</textarea>
                </div>
            </fieldset>
        @endforeach

        <button type="submit" class="btn btn-primary">Save</button>
    </form>

    @if ($contributor->exists)
        <form method="post" action="{{ route('admin.contributors.destroy', $contributor->id) }}">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-danger btn-sm">Delete contributor</button>
        </form>
    @endif
@endsection
