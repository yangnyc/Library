@extends('admin.layout')
@section('admin-title', 'Languages')

@section('admin')
    <p>Languages that books and catalog details can be in. Adding one here needs no deployment. (The <em>interface</em> languages are separate: each needs translation files — see ARCHITECTURE.md.)</p>

    @foreach ($languages as $language)
        <form method="post" action="{{ route('admin.languages.update', $language->id) }}" class="d-inline-flex flex-wrap align-items-center gap-2 card card-body mb-3">
            @csrf @method('PUT')
            <strong><code>{{ $language->tag }}</code></strong>
            <label class="form-label mb-0" for="native-{{ $language->id }}">Own name</label>
            <input class="form-control w-auto" id="native-{{ $language->id }}" name="native_name" type="text" value="{{ $language->native_name }}" required lang="{{ $language->tag }}" dir="auto">
            <label class="form-label mb-0" for="english-{{ $language->id }}">English name</label>
            <input class="form-control w-auto" id="english-{{ $language->id }}" name="english_name" type="text" value="{{ $language->english_name }}" required>
            <label class="form-label mb-0" for="dir-{{ $language->id }}">Direction</label>
            <select class="form-select w-auto" id="dir-{{ $language->id }}" name="direction">
                <option value="ltr" @selected($language->direction === 'ltr')>Left to right</option>
                <option value="rtl" @selected($language->direction === 'rtl')>Right to left</option>
            </select>
            <label class="form-label mb-0" for="pos-{{ $language->id }}">Order</label>
            <input class="form-control w-auto" id="pos-{{ $language->id }}" name="position" type="number" min="0" value="{{ $language->position }}" style="inline-size: 5rem">
            <input class="form-check-input" id="active-{{ $language->id }}" type="checkbox" name="is_active" value="1" @checked($language->is_active) style="inline-size: 1.2rem">
            <label class="form-check-label" for="active-{{ $language->id }}">Active</label>
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
        </form>
    @endforeach

    <h2>Add a language</h2>
    <form method="post" action="{{ route('admin.languages.store') }}" class="narrow">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="tag">Language tag (BCP 47, e.g. <code>fr</code>, <code>ar</code>, <code>pt-BR</code>, <code>sr-Latn</code>)</label>
            <input class="form-control" id="tag" name="tag" type="text" value="{{ old('tag') }}" required maxlength="35" dir="ltr">
        </div>
        <div class="mb-3"><label class="form-label" for="native_name">Name in that language</label><input class="form-control" id="native_name" name="native_name" type="text" value="{{ old('native_name') }}" required dir="auto"></div>
        <div class="mb-3"><label class="form-label" for="english_name">English name</label><input class="form-control" id="english_name" name="english_name" type="text" value="{{ old('english_name') }}" required></div>
        <div class="mb-3">
            <label class="form-label" for="direction">Direction</label>
            <select class="form-select" id="direction" name="direction"><option value="ltr">Left to right</option><option value="rtl">Right to left</option></select>
        </div>
        <button type="submit" class="btn btn-primary">Add language</button>
    </form>
@endsection
