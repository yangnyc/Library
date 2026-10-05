@extends('layouts.app')
@section('title', $contributor->localized('name'))

@section('content')
    <header class="mb-4">
        <h1><x-bdi :lang="$contributor->localizedLanguage('name') ?: $contributor->name_language_tag">{{ $contributor->localized('name') }}</x-bdi></h1>
        @if ($contributor->localized('name') !== $contributor->name)
            <p class="fs-5 text-body-secondary"><x-bdi :lang="$contributor->name_language_tag">{{ $contributor->name }}</x-bdi></p>
        @endif
        @if ($contributor->born || $contributor->died)
            <p class="text-body-secondary small"><bdi>{{ __('ui.contributor.born_died', ['born' => $contributor->born ?: '?', 'died' => $contributor->died ?: '']) }}</bdi></p>
        @endif
    </header>

    @if ($contributor->localized('biography'))
        <p class="prose" dir="auto">{{ $contributor->localized('biography') }}</p>
    @endif

    @if ($authored->total() > 0)
        <section aria-labelledby="authored-heading">
            <div class="shelf__head">
                <h2 id="authored-heading">{{ __('ui.contributor.author_of') }}</h2>
                <a href="{{ route('catalog', ['author' => $contributor->slug]) }}">{{ __('ui.collections.filter_catalog') }}</a>
            </div>
            <div class="card-grid">
                @foreach ($authored as $edition)
                    @include('partials.edition-card', ['edition' => $edition])
                @endforeach
            </div>
            {{ $authored->links() }}
        </section>
    @endif

    @if ($contributed->total() > 0)
        <section aria-labelledby="contributed-heading">
            <div class="shelf__head">
                <h2 id="contributed-heading">{{ __('ui.contributor.contributed_to') }}</h2>
                <a href="{{ route('catalog', ['translator' => $contributor->slug]) }}">{{ __('ui.collections.filter_catalog') }}</a>
            </div>
            <div class="card-grid">
                @foreach ($contributed as $edition)
                    @include('partials.edition-card', ['edition' => $edition])
                @endforeach
            </div>
            {{ $contributed->links() }}
        </section>
    @endif
@endsection
