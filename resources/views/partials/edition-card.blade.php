@php
    use App\Modules\Localization\Models\Language;
    /** @var \App\Modules\Catalog\Models\Edition $edition */
    $formats = $edition->currentFiles->filter(fn ($f) => $f->can_read || $f->can_download)->pluck('format')->unique();
@endphp
<article class="card">
    <a class="card__cover" href="{{ route('editions.show', $edition) }}" tabindex="-1" aria-hidden="true">
        @include('partials.cover', ['edition' => $edition])
    </a>
    <div class="card-body">
        <h3 class="card-title">
            <a href="{{ route('editions.show', $edition) }}"><x-bdi :lang="$edition->language_tag" :dir="$edition->direction">{{ $edition->title }}</x-bdi></a>
        </h3>
        @if ($edition->work->authors->isNotEmpty())
            <p class="card-subtitle">
                @foreach ($edition->work->authors as $author)
                    <x-bdi :lang="$author->localizedLanguage('name')">{{ $author->localized('name') }}</x-bdi>@if(! $loop->last), @endif
                @endforeach
            </p>
        @endif
        @if ($edition->translators->isNotEmpty())
            <p class="card-text small text-body-secondary mb-0">
                {{ __('ui.edition.translators') }}
                @foreach ($edition->translators as $translator)
                    <x-bdi :lang="$translator->localizedLanguage('name')">{{ $translator->localized('name') }}</x-bdi>@if(! $loop->last), @endif
                @endforeach
            </p>
        @endif
        <p class="card__meta">
            <span class="badge" lang="{{ $edition->language_tag }}">{{ Language::nativeName($edition->language_tag) }}</span>
            @foreach ($formats as $format)
                <span class="badge bg-transparent">{{ strtoupper($format) }}</span>
            @endforeach
        </p>
        @if (($showDescription ?? false) && $edition->description)
            <p class="card-text" lang="{{ $edition->language_tag }}" dir="{{ $edition->direction }}">{{ \Illuminate\Support\Str::limit($edition->description, 220) }}</p>
        @endif
    </div>
</article>
