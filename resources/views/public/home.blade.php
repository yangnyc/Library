@extends('layouts.app')
@section('body-class', 'page-home')

@section('content')
    <section class="hero">
        <div class="hero__text">
            <p class="eyebrow">{{ config('library.name') }}</p>
            <h1>{{ __('ui.home.title') }}</h1>
            <p class="lead">{{ __('ui.home.intro') }}</p>
            <form class="search-form input-group input-group-lg" action="{{ route('catalog') }}" method="get" role="search">
                <label for="home-q" class="visually-hidden">{{ __('ui.home.search_label') }}</label>
                <input class="form-control" id="home-q" type="search" name="q" placeholder="{{ __('ui.home.search_placeholder') }}" dir="auto" autocomplete="off">
                <button type="submit" class="btn btn-primary">{{ __('ui.home.search') }}</button>
                <div class="search-suggest" data-search-suggest data-url="{{ route('catalog.suggest') }}" data-label="{{ __('ui.home.suggestions') }}" data-all="{{ __('ui.home.all_results') }}"></div>
            </form>
            <p class="hero__links">
                <a class="btn btn-outline-secondary" href="{{ route('catalog') }}">{{ __('ui.home.browse_catalog') }}</a>
                <a class="btn btn-outline-secondary" href="{{ route('collections.index') }}">{{ __('ui.nav.collections') }}</a>
            </p>
        </div>
        @if ($recent->isNotEmpty())
            <aside class="hero__spotlight" aria-labelledby="spotlight-heading">
                <p id="spotlight-heading" class="eyebrow">{{ __('ui.home.recent') }}</p>
                @include('partials.edition-card', ['edition' => $recent->first()])
                <a class="spotlight-link" href="{{ route('editions.show', $recent->first()) }}">{{ __('ui.edition.edition_statement') }} <span aria-hidden="true">↗</span></a>
            </aside>
        @else
        <aside class="hero__guide" aria-labelledby="reading-guide-heading">
            <svg class="hero__book" viewBox="0 0 160 120" width="160" height="120" aria-hidden="true" focusable="false">
                <path d="M80 28C60 15 35 15 15 21v72c24-7 45-4 65 8 20-12 41-15 65-8V21c-20-6-45-6-65 7Z" fill="var(--surface)" stroke="currentColor" stroke-width="2"/>
                <path d="M80 28v73M29 36c13-2 25 0 37 5M29 50c13-2 25 0 37 5M29 64c13-2 25 0 37 5M94 41c12-5 24-7 37-5M94 55c12-5 24-7 37-5M94 69c12-5 24-7 37-5" fill="none" stroke="currentColor" stroke-width="2"/>
            </svg>
            <h2 id="reading-guide-heading">{{ __('ui.nav.help') }}</h2>
            <p class="text-body-secondary small">{{ __('ui.site.footer_note') }}</p>
            <ul class="hero__paths list-group list-group-flush">
                <li class="list-group-item"><a href="{{ route('help', ['topic' => 'reading']) }}">{{ __('ui.help.reading') }} <span aria-hidden="true">↗</span></a></li>
                <li class="list-group-item"><a href="{{ route('help', ['topic' => 'offline']) }}">{{ __('ui.help.offline') }} <span aria-hidden="true">↗</span></a></li>
                <li class="list-group-item"><a href="{{ route('offline') }}">{{ __('ui.nav.offline') }} <span aria-hidden="true">↗</span></a></li>
            </ul>
        </aside>
        @endif
    </section>

    <nav class="discovery-paths list-group list-group-horizontal-md" aria-label="{{ __('ui.help.topics') }}">
        <a class="list-group-item list-group-item-action" href="{{ route('collections.index') }}"><span class="path-number" aria-hidden="true">01</span><span>{{ __('ui.nav.collections') }}</span><span aria-hidden="true">↗</span></a>
        <a class="list-group-item list-group-item-action" href="{{ route('help', ['topic' => 'reading']) }}"><span class="path-number" aria-hidden="true">02</span><span>{{ __('ui.help.reading') }}</span><span aria-hidden="true">↗</span></a>
        <a class="list-group-item list-group-item-action" href="{{ route('offline') }}"><span class="path-number" aria-hidden="true">03</span><span>{{ __('ui.nav.offline') }}</span><span aria-hidden="true">↗</span></a>
    </nav>

    @if ($collections->isEmpty() && $recent->isEmpty())
        <p class="card card-body text-center text-body-secondary py-5">{{ __('ui.home.empty') }}</p>
    @endif

    @if ($collections->isNotEmpty())
        <section aria-labelledby="featured-heading">
            <h2 id="featured-heading">{{ __('ui.home.featured') }}</h2>
            @foreach ($collections as $collection)
                <section class="shelf card card-body" aria-labelledby="collection-{{ $collection->id }}">
                    <div class="shelf__head">
                        <h3 id="collection-{{ $collection->id }}">
                            <a href="{{ route('collections.show', $collection) }}"><x-bdi :lang="$collection->localizedLanguage('name')">{{ $collection->localized('name') }}</x-bdi></a>
                        </h3>
                        <a href="{{ route('collections.show', $collection) }}" aria-label="{{ __('ui.home.see_all') }}: {{ $collection->localized('name') }}">{{ __('ui.home.see_all') }}</a>
                    </div>
                    @if ($collection->localized('description'))
                        <p class="shelf__description" dir="auto">{{ $collection->localized('description') }}</p>
                    @endif
                    <ul class="work-row">
                        @foreach ($collection->works as $work)
                            @include('partials.work-item', ['work' => $work])
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </section>
    @endif

    @if ($recent->isNotEmpty())
        <section aria-labelledby="recent-heading">
            <div class="shelf__head">
                <h2 id="recent-heading">{{ __('ui.home.recent') }}</h2>
                <a href="{{ route('catalog') }}">{{ __('ui.home.browse_catalog') }}</a>
            </div>
            <div class="card-grid">
                @foreach ($recent as $edition)
                    @include('partials.edition-card', ['edition' => $edition])
                @endforeach
            </div>
        </section>
    @endif
@endsection
