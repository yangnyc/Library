@php
    use App\Modules\Localization\Models\Language;
    $title = $work->localized('title') ?: $work->original_title;
    $titleLang = $work->localizedLanguage('title') ?: $work->original_language_tag;
@endphp
@extends('layouts.app')
@section('title', $title)
@section('description', \Illuminate\Support\Str::limit((string) $work->summary, 155) ?: __('ui.site.tagline'))

@section('content')
    <article>
        <header class="mb-4">
            <h1><x-bdi :lang="$titleLang">{{ $title }}</x-bdi></h1>
            @if ($work->authors->isNotEmpty())
                <p class="mb-2">
                    {{ __('ui.work.by') }}
                    @foreach ($work->authors as $author)
                        <a href="{{ route('contributors.show', $author) }}"><x-bdi :lang="$author->localizedLanguage('name')">{{ $author->localized('name') }}</x-bdi></a>@if(! $loop->last), @endif
                    @endforeach
                </p>
            @endif

            @auth
                <form method="post" action="{{ route('account.favorites.toggle', $work) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary" aria-pressed="{{ $isFavorite ? 'true' : 'false' }}">
                        {{ $isFavorite ? __('ui.work.remove_favorite') : __('ui.work.add_favorite') }}
                    </button>
                </form>
            @endauth
        </header>

        <dl class="facts card card-body">
            @if ($title !== $work->original_title)
                <dt>{{ __('ui.work.original_title') }}</dt>
                <dd><x-bdi :lang="$work->original_language_tag">{{ $work->original_title }}</x-bdi></dd>
            @endif
            <dt>{{ __('ui.work.original_language') }}</dt>
            <dd><span lang="{{ $work->original_language_tag }}">{{ Language::nativeName($work->original_language_tag) }}</span></dd>
            @if ($work->first_published)
                <dt>{{ __('ui.work.first_published') }}</dt>
                <dd><bdi>{{ $work->first_published }}</bdi></dd>
            @endif
            @if ($work->series)
                <dt>{{ __('ui.work.series') }}</dt>
                <dd><bdi>{{ $work->series->localized('name') }}</bdi></dd>
            @endif
            @if ($work->categories->isNotEmpty())
                <dt>{{ __('ui.work.categories') }}</dt>
                <dd>
                    @foreach ($work->categories as $category)
                        <a href="{{ route('categories.show', $category) }}"><bdi>{{ $category->localized('name') }}</bdi></a>@if(! $loop->last), @endif
                    @endforeach
                </dd>
            @endif
            @if ($work->tags->isNotEmpty())
                <dt>{{ __('ui.work.tags') }}</dt>
                <dd>
                    @foreach ($work->tags as $tag)
                        <a href="{{ route('catalog', ['q' => $tag->name]) }}"><bdi>{{ $tag->localized('name') }}</bdi></a>@if(! $loop->last), @endif
                    @endforeach
                </dd>
            @endif
        </dl>

        @if ($work->summary)
            <p class="prose" dir="auto">{{ $work->summary }}</p>
        @endif

        <section aria-labelledby="editions-heading">
            <h2 id="editions-heading">{{ __('ui.work.editions') }}</h2>
            <p class="text-body-secondary small">{{ __('ui.work.editions_intro') }}</p>

            @foreach ($editionsByLanguage as $tag => $editions)
                <section class="edition-group" aria-labelledby="lang-{{ $tag }}">
                    <h3 id="lang-{{ $tag }}"><span lang="{{ $tag }}">{{ Language::nativeName($tag) }}</span></h3>
                    <div class="card-list">
                        @foreach ($editions as $edition)
                            @php $edition->setRelation('work', $work); @endphp
                            @include('partials.edition-card', ['edition' => $edition, 'showDescription' => true])
                        @endforeach
                    </div>
                </section>
            @endforeach
        </section>

        @if ($work->relatedWorks->isNotEmpty())
            <section aria-labelledby="related-heading">
                <h2 id="related-heading">{{ __('ui.work.related') }}</h2>
                <ul>
                    @foreach ($work->relatedWorks as $related)
                        <li><a href="{{ route('works.show', $related) }}"><x-bdi :lang="$related->localizedLanguage('title') ?: $related->original_language_tag">{{ $related->localized('title') ?: $related->original_title }}</x-bdi></a></li>
                    @endforeach
                </ul>
            </section>
        @endif
    </article>
@endsection
