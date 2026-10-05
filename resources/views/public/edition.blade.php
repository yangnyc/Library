@php
    use App\Modules\Localization\Models\Language;

    $workTitle = $work->localized('title') ?: $work->original_title;
    $readable = $files->filter(fn ($f) => $f->can_read && $f->isBrowserReadable());
    $downloadable = $files->filter(fn ($f) => $f->can_download);
    $epub = $files->firstWhere('format', 'epub');
    $translators = $edition->contributors->filter(fn ($c) => $c->pivot->role === 'translator');
    $editors = $edition->contributors->filter(fn ($c) => $c->pivot->role === 'editor');

    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Book',
        'name' => $edition->title,
        'inLanguage' => $edition->language_tag,
        'url' => route('editions.show', $edition),
        'author' => $work->authors->map(fn ($a) => ['@type' => 'Person', 'name' => $a->name])->values()->all(),
        'translator' => $translators->map(fn ($t) => ['@type' => 'Person', 'name' => $t->name])->values()->all(),
        'publisher' => $edition->publisher ? ['@type' => 'Organization', 'name' => $edition->publisher] : null,
        'datePublished' => $edition->published_date,
        'isbn' => $edition->isbn,
        'description' => $edition->description ? \Illuminate\Support\Str::limit($edition->description, 500) : null,
        'license' => $edition->license_url,
        'isAccessibleForFree' => true,
        'exampleOfWork' => ['@type' => 'Book', 'name' => $work->original_title, 'url' => route('works.show', $work)],
    ];
    $jsonLd = array_filter($jsonLd, fn ($v) => $v !== null && $v !== []);
@endphp
@extends('layouts.app', ['noindex' => $preview])
@section('title', $edition->title)
@section('body-class', 'page-edition')
@section('description', \Illuminate\Support\Str::limit((string) $edition->description, 155) ?: __('ui.site.tagline'))

@push('head')
    @unless ($preview)
        {{-- JSON data block, not executable script. JSON_HEX_TAG keeps "</script>" out of it. --}}
        <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    @endunless
@endpush

@section('content')
    <nav aria-label="{{ __('ui.nav.main') }}">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('catalog') }}">{{ __('ui.nav.catalog') }}</a></li>
            <li class="breadcrumb-item"><a href="{{ route('works.show', $work) }}"><bdi>{{ $workTitle }}</bdi></a></li>
        </ol>
    </nav>
    @if ($preview)
        <div class="alert alert-warning" role="status">{{ __('ui.site.preview_banner') }} ({{ $edition->status }})</div>
    @endif

    <article class="edition row g-4 g-lg-5">
        <div class="col-md-4 col-lg-3"><div class="edition__cover card card-body">
            @include('partials.cover', ['edition' => $edition, 'decorative' => false])
            <p class="text-center mt-3 mb-0"><span class="badge" lang="{{ $edition->language_tag }}">{{ Language::nativeName($edition->language_tag) }}</span></p>
        </div></div>

        <div class="col-md-8 col-lg-9">
            <header>
                <h1 lang="{{ $edition->language_tag }}" dir="{{ $edition->direction }}">{{ $edition->title }}</h1>
                @if ($edition->subtitle)
                    <p class="fs-5 text-body-secondary" lang="{{ $edition->language_tag }}" dir="{{ $edition->direction }}">{{ $edition->subtitle }}</p>
                @endif
                @if ($work->authors->isNotEmpty())
                    <p class="mb-2">
                        @foreach ($work->authors as $author)
                            <a href="{{ route('contributors.show', $author) }}"><x-bdi :lang="$author->localizedLanguage('name')">{{ $author->localized('name') }}</x-bdi></a>@if(! $loop->last), @endif
                        @endforeach
                    </p>
                @endif
                <p class="text-body-secondary small">
                    {{ __('ui.edition.part_of') }}
                    <a href="{{ route('works.show', $work) }}"><x-bdi :lang="$work->localizedLanguage('title') ?: $work->original_language_tag">{{ $workTitle }}</x-bdi></a>
                </p>
            </header>

            <section class="actions card card-body shadow-sm my-4" aria-labelledby="actions-heading">
                <h2 id="actions-heading" class="card-title">{{ __('ui.edition.reading_modes') }} / {{ __('ui.edition.downloads') }}</h2>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    @forelse ($readable as $file)
                        <a class="btn btn-primary" href="{{ route('read', ['edition' => $edition, 'format' => $file->format]) }}">
                            {{ $readable->count() > 1 ? __('ui.edition.read_format', ['format' => strtoupper($file->format)]) : __('ui.edition.read') }}
                        </a>
                    @empty
                        <p class="text-body-secondary mb-0">{{ __('ui.edition.no_reading') }} {{ __('ui.edition.reason_rights') }}</p>
                    @endforelse
                </div>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    @forelse ($downloadable as $file)
                        <a class="btn btn-outline-secondary" href="{{ route('download', ['file' => $file->id, 'filename' => $file->asciiDownloadFilename()]) }}" download>
                            {{ __('ui.edition.download', ['format' => strtoupper($file->format)]) }}
                            <span class="fw-normal">(<span class="visually-hidden">{{ __('ui.edition.file_size') }} </span><bdi>{{ $file->humanSize() }}</bdi>)</span>
                        </a>
                    @empty
                        <p class="text-body-secondary mb-0">{{ __('ui.edition.no_download') }} {{ __('ui.edition.reason_rights') }}</p>
                    @endforelse
                </div>

                {{-- Say why, whenever a format exists but one of the actions is missing. --}}
                <ul class="reasons text-body-secondary small">
                    @foreach ($files as $file)
                        @if (! $file->can_download && $downloadable->isNotEmpty())
                            <li>{{ __('ui.edition.reason_format_download', ['format' => strtoupper($file->format)]) }}</li>
                        @endif
                        @if ($file->can_download && ! ($file->can_read && $file->isBrowserReadable()) && $readable->isNotEmpty())
                            <li>{{ __('ui.edition.reason_format_read', ['format' => strtoupper($file->format)]) }}</li>
                        @endif
                    @endforeach
                    @if (! $epub)
                        <li>{{ __('ui.edition.reason_no_epub') }}</li>
                    @endif
                </ul>

                @if ($epub && $epub->can_download)
                    <p class="text-body-secondary small">
                        <a href="{{ route('help', ['topic' => 'kindle']) }}">{{ __('ui.edition.kindle') }}</a> — {{ __('ui.edition.kindle_hint') }}
                    </p>
                @endif
            </section>

            @if ($readable->isNotEmpty())
                <section aria-labelledby="modes-heading">
                    <h2 id="modes-heading">{{ __('ui.edition.reading_modes') }}</h2>
                    <ul>
                        @foreach ($readable as $file)
                            <li>
                                <strong>{{ strtoupper($file->format) }}:</strong>
                                @if ($file->format === 'epub')
                                    {{ $file->layout === 'fixed' ? __('ui.edition.mode_epub_fixed') : __('ui.edition.mode_epub') }}
                                @else
                                    {{ __('ui.edition.mode_pdf') }}
                                    @if ($file->has_text_layer === false)
                                        {{ __('ui.edition.mode_pdf_scanned') }}
                                    @endif
                                @endif
                                @if ($file->can_offline)
                                    {{ __('ui.edition.offline_hint') }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($edition->description)
                <div class="prose" lang="{{ $edition->language_tag }}" dir="{{ $edition->direction }}">
                    @foreach (preg_split('/\R{2,}/u', trim($edition->description)) as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </div>
            @endif

            <div class="card card-body my-4">
<dl class="row mb-0">
                <dt class="col-sm-4 col-lg-3">{{ __('ui.edition.language') }}</dt>
                <dd class="col-sm-8 col-lg-9"><span lang="{{ $edition->language_tag }}">{{ Language::nativeName($edition->language_tag) }}</span></dd>
                @if ($translators->isNotEmpty())
                    <dt class="col-sm-4 col-lg-3">{{ __('ui.edition.translators') }}</dt>
                    <dd class="col-sm-8 col-lg-9">
                        @foreach ($translators as $person)
                            <a href="{{ route('contributors.show', $person) }}"><x-bdi :lang="$person->localizedLanguage('name')">{{ $person->localized('name') }}</x-bdi></a>@if(! $loop->last), @endif
                        @endforeach
                    </dd>
                @endif
                @if ($editors->isNotEmpty())
                    <dt class="col-sm-4 col-lg-3">{{ __('ui.edition.editors') }}</dt>
                    <dd class="col-sm-8 col-lg-9">
                        @foreach ($editors as $person)
                            <a href="{{ route('contributors.show', $person) }}"><x-bdi :lang="$person->localizedLanguage('name')">{{ $person->localized('name') }}</x-bdi></a>@if(! $loop->last), @endif
                        @endforeach
                    </dd>
                @endif
                @if ($edition->publisher)
                    <dt class="col-sm-4 col-lg-3">{{ __('ui.edition.publisher') }}</dt><dd class="col-sm-8 col-lg-9"><bdi>{{ $edition->publisher }}</bdi></dd>
                @endif
                @if ($edition->published_date)
                    <dt class="col-sm-4 col-lg-3">{{ __('ui.edition.published') }}</dt><dd class="col-sm-8 col-lg-9"><bdi>{{ $edition->published_date }}</bdi></dd>
                @endif
                @if ($edition->edition_statement)
                    <dt class="col-sm-4 col-lg-3">{{ __('ui.edition.edition_statement') }}</dt><dd class="col-sm-8 col-lg-9"><bdi>{{ $edition->edition_statement }}</bdi></dd>
                @endif
                @if ($edition->isbn)
                    <dt class="col-sm-4 col-lg-3">{{ __('ui.edition.isbn') }}</dt><dd class="col-sm-8 col-lg-9"><bdi dir="ltr">{{ $edition->isbn }}</bdi></dd>
                @endif
                @if ($edition->source_name || $edition->source_url)
                    <dt class="col-sm-4 col-lg-3">{{ __('ui.edition.source') }}</dt>
                    <dd class="col-sm-8 col-lg-9">
                        @if ($edition->source_url)
                            <a href="{{ $edition->source_url }}" rel="noopener noreferrer external"><bdi>{{ $edition->source_name ?: $edition->source_url }}</bdi></a>
                        @else
                            <bdi>{{ $edition->source_name }}</bdi>
                        @endif
                    </dd>
                @endif
                <dt class="col-sm-4 col-lg-3">{{ __('ui.edition.rights') }}</dt>
                <dd class="col-sm-8 col-lg-9">
                    {{ __('ui.edition.rights_'.$edition->rights_status) }}
                    @if ($edition->rights_holder) — <bdi>{{ $edition->rights_holder }}</bdi>@endif
                </dd>
                @if ($edition->license_name)
                    <dt class="col-sm-4 col-lg-3">{{ __('ui.edition.license') }}</dt>
                    <dd class="col-sm-8 col-lg-9">
                        @if ($edition->license_url)
                            <a href="{{ $edition->license_url }}" rel="license noopener noreferrer external"><bdi>{{ $edition->license_name }}</bdi></a>
                        @else
                            <bdi>{{ $edition->license_name }}</bdi>
                        @endif
                    </dd>
                @endif
                @if ($edition->attribution)
                    <dt class="col-sm-4 col-lg-3">{{ __('ui.edition.attribution') }}</dt><dd class="col-sm-8 col-lg-9" dir="auto">{{ $edition->attribution }}</dd>
                @endif
                @if ($edition->territory_notes)
                    <dt class="col-sm-4 col-lg-3">{{ __('ui.edition.territory') }}</dt><dd class="col-sm-8 col-lg-9" dir="auto">{{ $edition->territory_notes }}</dd>
                @endif
            </dl>
</div>

            @unless ($preview)
                <p><a href="{{ route('rights', ['edition' => $edition->slug]) }}">{{ __('ui.edition.report') }}</a></p>
            @endunless

            @auth
                @if (! $preview && $lists->isNotEmpty())
                    <form method="post" class="d-inline-flex flex-wrap align-items-center gap-2" data-list-form data-action-template="{{ route('account.lists.items.add', ['list' => '__LIST__']) }}">
                        @csrf
                        <input type="hidden" name="edition" value="{{ $edition->slug }}">
                        <label class="form-label mb-0" for="list-select">{{ __('ui.edition.add_to_list') }}</label>
                        <select class="form-select w-auto" id="list-select" data-list-select>
                            @foreach ($lists as $list)
                                <option value="{{ $list->id }}">{{ $list->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">{{ __('ui.edition.add') }}</button>
                    </form>
                @endif
            @endauth
        </div>
    </article>

    @if ($otherEditions->isNotEmpty())
        <section aria-labelledby="other-heading">
            <h2 id="other-heading">{{ __('ui.edition.other_editions') }}</h2>
            <p class="text-body-secondary small">{{ __('ui.edition.other_editions_note') }}</p>
            <ul class="list-group">
                @foreach ($otherEditions as $other)
                    <li class="list-group-item">
                        <a href="{{ route('editions.show', $other) }}">
                            <span class="badge" lang="{{ $other->language_tag }}">{{ Language::nativeName($other->language_tag) }}</span>
                            <x-bdi :lang="$other->language_tag" :dir="$other->direction">{{ $other->title }}</x-bdi>
                        </a>
                        @if ($other->translators->isNotEmpty())
                            <span class="text-body-secondary small">— {{ __('ui.edition.translators') }}
                                @foreach ($other->translators as $person)<bdi>{{ $person->localized('name') }}</bdi>@if(! $loop->last), @endif @endforeach
                            </span>
                        @endif
                        @if ($other->published_date)<span class="text-body-secondary small">(<bdi>{{ $other->published_date }}</bdi>)</span>@endif
                        @if ($other->language_tag === $edition->language_tag)
                            <span class="text-body-secondary small">· {{ __('ui.edition.same_language', ['language' => Language::nativeName($other->language_tag)]) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
