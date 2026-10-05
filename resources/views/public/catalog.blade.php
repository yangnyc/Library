@php
    use App\Modules\Localization\Models\Language;
    $active = array_filter(\Illuminate\Support\Arr::only($filters, ['q', 'language', 'author', 'translator', 'category', 'collection', 'format']));
    $without = fn (string $key) => route('catalog', \Illuminate\Support\Arr::except(array_filter($filters), [$key]));
@endphp
@extends('layouts.app', ['noindex' => $active !== [] || $editions->currentPage() > 1])
@section('title', __('ui.catalog.title'))
@section('body-class', 'page-catalog')

@section('content')
    <header class="mb-4">
        <p class="eyebrow">{{ config('library.name') }}</p>
        <h1>{{ __('ui.catalog.title') }}</h1>
        <p class="lead">{{ __('ui.site.tagline') }}</p>
    </header>

    <div class="row g-4">
    <details class="catalog-filter-panel col-md-4 col-lg-3" open data-catalog-filters>
        <summary>{{ __('ui.catalog.filters') }}</summary>
    <form class="filters card card-body" action="{{ route('catalog') }}" method="get" role="search" aria-label="{{ __('ui.catalog.filters') }}">
        <h2 class="card-title">{{ __('ui.catalog.filters') }}</h2>
        <div class="mb-3">
            <label class="form-label" for="q">{{ __('ui.home.search_label') }}</label>
            <input class="form-control" id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" dir="auto" maxlength="200" placeholder="{{ __('ui.home.search_placeholder') }}">
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-language">{{ __('ui.catalog.language') }}</label>
            <select class="form-select" id="f-language" name="language">
                <option value="">{{ __('ui.catalog.any_language') }}</option>
                @foreach ($facets['languages'] as $tag)
                    <option value="{{ $tag }}" lang="{{ $tag }}" @selected(($filters['language'] ?? '') === $tag)>{{ Language::nativeName($tag) }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-category">{{ __('ui.catalog.category') }}</label>
            <select class="form-select" id="f-category" name="category">
                <option value="">{{ __('ui.catalog.any_category') }}</option>
                @foreach ($facets['categories'] as $category)
                    <option value="{{ $category->slug }}" @selected(($filters['category'] ?? '') === $category->slug)>{{ $category->localized('name') }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-collection">{{ __('ui.catalog.collection') }}</label>
            <select class="form-select" id="f-collection" name="collection">
                <option value="">{{ __('ui.catalog.any_collection') }}</option>
                @foreach ($facets['collections'] as $collection)
                    <option value="{{ $collection->slug }}" @selected(($filters['collection'] ?? '') === $collection->slug)>{{ $collection->localized('name') }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-format">{{ __('ui.catalog.format') }}</label>
            <select class="form-select" id="f-format" name="format">
                <option value="">{{ __('ui.catalog.any_format') }}</option>
                @foreach (['epub', 'pdf', 'txt', 'html'] as $format)
                    <option value="{{ $format }}" @selected(($filters['format'] ?? '') === $format)>{{ strtoupper($format) }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-sort">{{ __('ui.catalog.sort') }}</label>
            <select class="form-select" id="f-sort" name="sort">
                @foreach (['relevance', 'newest', 'title', 'year'] as $sort)
                    <option value="{{ $sort }}" @selected(($filters['sort'] ?? (($filters['q'] ?? '') !== '' ? 'relevance' : 'newest')) === $sort)>{{ __('ui.catalog.sort_'.$sort) }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-view">{{ __('ui.catalog.view') }}</label>
            <select class="form-select" id="f-view" name="view">
                <option value="grid" @selected($layout === 'grid')>{{ __('ui.catalog.grid') }}</option>
                <option value="list" @selected($layout === 'list')>{{ __('ui.catalog.list') }}</option>
            </select>
        </div>
        {{-- Person filters come from author and translator pages; keep them when refining. --}}
        @foreach (['author', 'translator'] as $person)
            @if (! empty($filters[$person]))
                <input type="hidden" name="{{ $person }}" value="{{ $filters[$person] }}">
            @endif
        @endforeach
        <div class="mb-3">
            <button type="submit" class="btn btn-primary w-100">{{ __('ui.catalog.apply') }}</button>
        </div>
    </form>
    </details>

    <section class="col-md-8 col-lg-9" aria-label="{{ __('ui.catalog.title') }}">
    @if ($active !== [])
        <ul class="list-unstyled d-flex flex-wrap gap-2 align-items-center" aria-label="{{ __('ui.catalog.filters') }}">
            @foreach ($active as $key => $value)
                @php
                    $label = match ($key) {
                        'q' => '“'.$value.'”',
                        'language' => Language::nativeName($value),
                        'author', 'translator' => __('ui.catalog.'.$key).': '.($people[$value]?->localized('name') ?? $value),
                        'category' => $facets['categories']->firstWhere('slug', $value)?->localized('name') ?? $value,
                        'collection' => $facets['collections']->firstWhere('slug', $value)?->localized('name') ?? $value,
                        'format' => strtoupper($value),
                    };
                @endphp
                <li><a class="btn btn-sm btn-outline-secondary rounded-pill" href="{{ $without($key) }}" aria-label="{{ __('ui.catalog.remove_filter', ['name' => $label]) }}"><bdi>{{ $label }}</bdi> <span aria-hidden="true">×</span></a></li>
            @endforeach
            <li><a href="{{ route('catalog') }}">{{ __('ui.catalog.clear') }}</a></li>
        </ul>
    @endif

    @if ($error)
        <div class="alert alert-danger" role="alert">{{ $error }}</div>
    @elseif ($editions->total() === 0)
        <div class="card card-body text-center text-body-secondary py-5" role="status">
            <h2>{{ __('ui.catalog.empty_title') }}</h2>
            <p>{{ __('ui.catalog.empty_text') }}</p>
            @if ($active !== [])
                <p><a class="btn btn-outline-secondary" href="{{ route('catalog') }}">{{ __('ui.catalog.clear') }}</a></p>
            @endif
        </div>
    @else
        <p class="fw-bold border-bottom pb-2 mb-3" role="status">{{ trans_choice('ui.catalog.results', $editions->total(), ['count' => $editions->total()]) }}</p>
        <div class="{{ $layout === 'list' ? 'card-list' : 'card-grid' }}">
            @foreach ($editions as $edition)
                @include('partials.edition-card', ['edition' => $edition, 'showDescription' => $layout === 'list'])
            @endforeach
        </div>
        {{ $editions->links() }}
    @endif
    </section>
    </div>
@endsection
