@extends('admin.layout')
@section('admin-title', 'New edition')

@section('admin')
    <p>Edition of <a href="{{ route('admin.works.edit', $work->id) }}"><bdi>{{ $work->original_title }}</bdi></a>.</p>
    <p class="text-body-secondary small">An edition is one text in one language: the original, or one particular translation. A work may have several editions in the same language. The edition starts as a private draft.</p>

    <form method="post" action="{{ route('admin.editions.store', $work->id) }}" class="narrow">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="title">Title of this edition</label>
            <input class="form-control" id="title" name="title" type="text" value="{{ old('title') }}" required maxlength="255" dir="auto">
        </div>
        <div class="mb-3">
            <label class="form-label" for="language_tag">Language of the text</label>
            <select class="form-select" id="language_tag" name="language_tag" required>
                @foreach ($languages as $tag => $language)
                    <option value="{{ $tag }}" @selected(old('language_tag') === $tag)>{{ $language['english_name'] }} ({{ $tag }})</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Create draft</button>
    </form>
@endsection
