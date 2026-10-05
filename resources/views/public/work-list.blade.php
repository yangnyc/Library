@extends('layouts.app')
@section('title', $title)

@section('content')
    <header class="mb-4">
        <p class="eyebrow">{{ $kind }}</p>
        <h1><x-bdi :lang="$titleLang">{{ $title }}</x-bdi></h1>
        @if ($description)
            <p class="lead" dir="auto">{{ $description }}</p>
        @endif
        <p><a href="{{ route('catalog', $catalogFilter) }}">{{ __('ui.collections.filter_catalog') }}</a></p>
    </header>

    <ul class="work-row work-row--wrap">
        @foreach ($works as $work)
            @include('partials.work-item', ['work' => $work])
        @endforeach
    </ul>
    {{ $works->links() }}
@endsection
