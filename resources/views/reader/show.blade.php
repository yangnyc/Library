@php
    use App\Modules\Localization\Locales;
    $locale = app()->getLocale();
    $t = fn (string $key, array $replace = []) => __('reader.'.$key, $replace);
    $isPdf = $file->format === 'pdf';
@endphp
<!DOCTYPE html>
{{-- The page is in the interface language; the book area below declares the book's own language and direction. --}}
<html lang="{{ $locale }}" dir="{{ Locales::direction($locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="{{ config('library.theme_color') }}">
    <title>{{ $edition->title }} — {{ config('library.name') }}</title>
    <link rel="manifest" href="{{ route('manifest') }}">
    <link rel="icon" href="/icons/icon-192.png" type="image/png">
    @vite(['resources/css/app.css', 'resources/css/reader.css', 'resources/js/reader/index.ts'])
</head>
<body class="reader reader--{{ $file->format }}" data-theme="light">
    <a class="skip-link" href="#book">{{ __('ui.site.skip') }}</a>

    <header class="rbar" data-chrome>
        <a class="rbar__back" href="{{ route('editions.show', $edition) }}" aria-label="{{ $t('back') }}" title="{{ $t('back') }}">
            <span aria-hidden="true" class="icon icon--back"></span>
        </a>
        <h1 class="rbar__title"><bdi lang="{{ $edition->language_tag }}" dir="{{ $editionDir }}">{{ $edition->title }}</bdi></h1>

        <div class="rbar__tools" role="toolbar" aria-label="{{ $t('settings') }}">
            @unless ($isPdf)
                <button type="button" class="rbtn" data-open="toc" aria-haspopup="dialog">{{ $t('contents') }}</button>
            @endunless
            <button type="button" class="rbtn" data-open="search" aria-haspopup="dialog">{{ $t('search') }}</button>
            <button type="button" class="rbtn" data-action="bookmark">{{ $t('addBookmark') }}</button>
            <button type="button" class="rbtn" data-open="marks" aria-haspopup="dialog">{{ $isPdf ? $t('bookmarks') : $t('notes') }}</button>
            <button type="button" class="rbtn" data-open="settings" aria-haspopup="dialog">{{ $t('settings') }}</button>
            <button type="button" class="rbtn" data-open="more" aria-haspopup="dialog">{{ $t('more') }}</button>
            <button type="button" class="rbtn" data-action="fullscreen" hidden>{{ $t('fullscreen') }}</button>
        </div>
    </header>

    @if ($preview)
        <p class="rnotice rnotice--warn" role="status">{{ $t('previewNotice') }}</p>
    @endif
    <p class="rnotice" role="status" hidden data-notice></p>

    @if ($isPdf)
        <div class="pdfbar" role="toolbar" aria-label="PDF" data-chrome>
            <button type="button" class="rbtn" data-action="prev">{{ $t('previous') }}</button>
            <label class="form-label" for="pdf-page">{{ $t('pdfPage') }}</label>
            <input class="form-control" id="pdf-page" type="number" min="1" value="1" inputmode="numeric" data-pdf-page>
            <span data-pdf-total></span>
            <button type="button" class="rbtn" data-action="next">{{ $t('next') }}</button>
            <button type="button" class="rbtn" data-action="zoom-out" aria-label="{{ $t('zoomOut') }}">−</button>
            <button type="button" class="rbtn" data-action="zoom-in" aria-label="{{ $t('zoomIn') }}">+</button>
            <button type="button" class="rbtn" data-action="fit">{{ $t('fitWidth') }}</button>
        </div>
    @endif

    <main class="rstage">
        @unless ($isPdf)
            <button type="button" class="rnav rnav--prev" data-action="prev" aria-label="{{ $t('previous') }}"><span aria-hidden="true" class="icon icon--prev"></span></button>
        @endunless

        {{-- The book itself: its own language and direction, independent of the interface. --}}
        <div id="book" class="rbook" tabindex="-1" lang="{{ $edition->language_tag }}" dir="{{ $editionDir }}" aria-label="{{ $edition->title }}">
            <div class="rloading" role="status" data-loading>{{ $t('loading') }}</div>
            <div class="rerror" role="alert" hidden data-error>
                <p data-error-text>{{ $t('loadError') }}</p>
                <p>
                    <button type="button" class="btn btn-primary" data-action="reload">{{ $t('retry') }}</button>
                    @if ($config['urls']['download'])
                        <a class="btn btn-outline-secondary" href="{{ $config['urls']['download'] }}" download>{{ $t('download') }}</a>
                    @endif
                </p>
            </div>
            <div id="viewer" class="rviewer"></div>
        </div>

        @unless ($isPdf)
            <button type="button" class="rnav rnav--next" data-action="next" aria-label="{{ $t('next') }}"><span aria-hidden="true" class="icon icon--next"></span></button>
        @endunless
    </main>

    <footer class="rfoot" data-chrome>
        <span data-position></span>
        <span class="rfoot__sync" role="status" data-sync-status></span>
    </footer>

    <div class="visually-hidden" role="status" aria-live="polite" data-live></div>

    {{-- Selection toolbar (EPUB) --}}
    <div class="seltools" hidden data-selection-tools role="toolbar" aria-label="{{ $t('notes') }}">
        <button type="button" class="rbtn" data-action="highlight">{{ $t('highlight') }}</button>
        <button type="button" class="rbtn" data-action="note">{{ $t('addNote') }}</button>
    </div>

    <dialog class="panel" id="panel-toc" aria-labelledby="toc-title">
        <div class="panel__head"><h2 id="toc-title">{{ $t('contents') }}</h2><button type="button" class="rbtn" data-close>{{ $t('close') }}</button></div>
        <nav aria-labelledby="toc-title"><ol class="toc" data-toc lang="{{ $edition->language_tag }}" dir="{{ $editionDir }}"></ol></nav>
    </dialog>

    <dialog class="panel" id="panel-search" aria-labelledby="search-title">
        <div class="panel__head"><h2 id="search-title">{{ $t('search') }}</h2><button type="button" class="rbtn" data-close>{{ $t('close') }}</button></div>
        <form class="panel__form" data-search-form>
            <label for="search-q" class="visually-hidden">{{ $t('searchPlaceholder') }}</label>
            <input class="form-control" id="search-q" type="search" required minlength="2" maxlength="100" dir="auto" placeholder="{{ $t('searchPlaceholder') }}" lang="{{ $edition->language_tag }}">
            <button type="submit" class="btn btn-primary btn-sm">{{ $t('searchGo') }}</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" hidden data-search-stop>{{ $t('searchStop') }}</button>
        </form>
        <p role="status" data-search-status></p>
        <ol class="results" data-search-results lang="{{ $edition->language_tag }}" dir="{{ $editionDir }}"></ol>
    </dialog>

    <dialog class="panel" id="panel-marks" aria-labelledby="marks-title">
        <div class="panel__head"><h2 id="marks-title">{{ $t('bookmarks') }}</h2><button type="button" class="rbtn" data-close>{{ $t('close') }}</button></div>
        <ul class="marks" data-bookmarks></ul>
        <p class="text-body-secondary small" data-bookmarks-empty>{{ $t('noBookmarks') }}</p>
        @unless ($isPdf)
            <h2>{{ $t('notes') }}</h2>
            <ul class="marks" data-annotations></ul>
            <p class="text-body-secondary small" data-annotations-empty>{{ $t('noNotes') }}</p>
        @endunless
    </dialog>

    <dialog class="panel" id="panel-settings" aria-labelledby="settings-title">
        <div class="panel__head"><h2 id="settings-title">{{ $t('settings') }}</h2><button type="button" class="rbtn" data-close>{{ $t('close') }}</button></div>
        @if ($isPdf)
            <p class="text-body-secondary small">{{ $t('pdfTheme') }}</p>
        @else
            <p class="text-body-secondary small" hidden data-fixed-layout>{{ $t('fixedLayout') }}</p>
            <form class="settings" data-settings>
                <fieldset>
                    <legend>{{ $t('flow') }}</legend>
                    <label class="form-check-label"><input class="form-check-input" type="radio" name="flow" value="paginated"> {{ $t('flowPaginated') }}</label>
                    <label class="form-check-label"><input class="form-check-input" type="radio" name="flow" value="scrolled"> {{ $t('flowScrolled') }}</label>
                    <label class="form-check-label"><input class="form-check-input" type="checkbox" name="spread" value="1"> {{ $t('spread') }}</label>
                </fieldset>
                <fieldset>
                    <legend>{{ $t('theme') }}</legend>
                    @foreach (['light' => 'themeLight', 'sepia' => 'themeSepia', 'dark' => 'themeDark', 'contrast' => 'themeContrast'] as $value => $label)
                        <label class="form-check-label"><input class="form-check-input" type="radio" name="theme" value="{{ $value }}"> {{ $t($label) }}</label>
                    @endforeach
                </fieldset>
                <fieldset>
                    <legend>{{ $t('font') }}</legend>
                    @foreach (['publisher' => 'fontPublisher', 'serif' => 'fontSerif', 'sans' => 'fontSans'] as $value => $label)
                        <label class="form-check-label"><input class="form-check-input" type="radio" name="font" value="{{ $value }}"> {{ $t($label) }}</label>
                    @endforeach
                </fieldset>
                <div class="mb-3">
                    <label class="form-label" for="set-size">{{ $t('fontSize') }} <output data-out="fontSize"></output></label>
                    <input class="form-range" id="set-size" type="range" name="fontSize" min="80" max="240" step="10">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="set-line">{{ $t('lineHeight') }} <output data-out="lineHeight"></output></label>
                    <input class="form-range" id="set-line" type="range" name="lineHeight" min="120" max="220" step="10">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="set-margin">{{ $t('margins') }} <output data-out="margin"></output></label>
                    <input class="form-range" id="set-margin" type="range" name="margin" min="0" max="12" step="1">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="set-width">{{ $t('width') }} <output data-out="width"></output></label>
                    <input class="form-range" id="set-width" type="range" name="width" min="30" max="100" step="5">
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="reset-settings">{{ $t('reset') }}</button>
            </form>
        @endif
    </dialog>

    <dialog class="panel" id="panel-more" aria-labelledby="more-title">
        <div class="panel__head"><h2 id="more-title">{{ $t('more') }}</h2><button type="button" class="rbtn" data-close>{{ $t('close') }}</button></div>
        <p data-identity>{{ $t('guestNotice') }}</p>

        <h3>{{ $t('offlineTitle') }}</h3>
        <div data-offline>
            <p data-offline-status></p>
            <progress max="100" value="0" hidden data-offline-progress></progress>
            <p>
                <button type="button" class="btn btn-primary btn-sm" hidden data-offline-save>{{ $t('offlineSave') }}</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" hidden data-offline-cancel>{{ $t('offlineCancel') }}</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" hidden data-offline-remove>{{ $t('offlineRemove') }}</button>
            </p>
            <p class="text-body-secondary small">{{ $t('offlineEviction') }}</p>
            <p><a href="{{ route('offline') }}">{{ __('ui.nav.offline') }}</a></p>
        </div>

        @if ($config['urls']['download'])
            <p><a href="{{ $config['urls']['download'] }}" download>{{ $t('download') }}</a></p>
        @endif
        <p><a href="{{ route('help', ['topic' => 'reading']) }}">{{ __('ui.help.reading') }}</a></p>
    </dialog>

    <dialog class="panel panel--small" id="panel-note" aria-labelledby="note-title">
        <form method="dialog" data-note-form>
            <h2 id="note-title">{{ $t('noteLabel') }}</h2>
            <blockquote data-note-quote lang="{{ $edition->language_tag }}" dir="{{ $editionDir }}"></blockquote>
            <label for="note-text" class="visually-hidden">{{ $t('noteLabel') }}</label>
            <textarea class="form-control" id="note-text" rows="5" maxlength="10000" dir="auto"></textarea>
            <p>
                <button type="submit" class="btn btn-primary btn-sm" value="save">{{ $t('save') }}</button>
                <button type="submit" class="btn btn-outline-secondary btn-sm" value="cancel" formnovalidate>{{ $t('cancel') }}</button>
            </p>
        </form>
    </dialog>

    <dialog class="panel panel--small" id="panel-popup" aria-labelledby="popup-title">
        <div class="panel__head"><h2 id="popup-title" data-popup-title></h2><button type="button" class="rbtn" data-close>{{ $t('close') }}</button></div>
        <div data-popup-body lang="{{ $edition->language_tag }}" dir="{{ $editionDir }}"></div>
        <p data-popup-actions></p>
    </dialog>

    <dialog class="panel panel--small" id="panel-conflict" aria-labelledby="conflict-title">
        <h2 id="conflict-title" data-conflict-text></h2>
        <p>
            <button type="button" class="btn btn-primary btn-sm" data-conflict-go>{{ $t('conflictGo') }}</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-close>{{ $t('conflictStay') }}</button>
        </p>
    </dialog>

    <script type="application/json" id="reader-config">{!! json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
</body>
</html>
