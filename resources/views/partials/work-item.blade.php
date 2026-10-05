@php
    use App\Modules\Localization\Models\Language;
    /** @var \App\Modules\Catalog\Models\Work $work */
    $title = $work->localized('title') ?: $work->original_title;
    $titleLang = $work->localizedLanguage('title') ?: $work->original_language_tag;
    $languages = $work->publishedEditions->pluck('language_tag')->unique();
@endphp
<li class="work-item card card-body">
    <h4 class="card-title">
        <a href="{{ route('works.show', $work) }}"><x-bdi :lang="$titleLang">{{ $title }}</x-bdi></a>
    </h4>
    @if ($work->authors->isNotEmpty())
        <p class="card-subtitle">
            @foreach ($work->authors as $author)
                <x-bdi :lang="$author->localizedLanguage('name')">{{ $author->localized('name') }}</x-bdi>@if(! $loop->last), @endif
            @endforeach
        </p>
    @endif
    <p class="card__meta">
        <span class="visually-hidden">{{ __('ui.collections.editions_available') }}</span>
        @foreach ($languages as $tag)
            <span class="badge" lang="{{ $tag }}">{{ Language::nativeName($tag) }}</span>
        @endforeach
    </p>
</li>
