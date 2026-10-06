@php
    use App\Modules\Localization\Locales;
    use App\Modules\Localization\Themes;

    $locale = app()->getLocale();
    $siteName = config('library.name');
    $pageTitle = trim($__env->yieldContent('title'));
    $routeName = request()->route()?->getName();
    $isLocalized = request()->route() && in_array('locale', request()->route()->parameterNames(), true);
    $noindex = $noindex ?? false;
    $theme = Themes::current();
    $bootstrap = Locales::direction($locale) === 'rtl' ? 'resources/css/bootstrap-rtl.css' : 'resources/css/bootstrap.css';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ Locales::direction($locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle !== '' ? $pageTitle.' — '.$siteName : $siteName }}</title>
    <meta name="description" content="@yield('description', __('ui.site.tagline'))">
    <meta name="theme-color" content="{{ Themes::color($theme) }}">
    <meta name="color-scheme" content="{{ Themes::all()[$theme]['scheme'] }}">
    <link rel="manifest" href="{{ route('manifest') }}">
    <link rel="icon" href="/icons/icon-192.png" type="image/png">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    @if ($noindex || ! $isLocalized)
        <meta name="robots" content="noindex">
    @elseif ($routeName)
        {{-- One canonical URL per locale, with the other locales as alternates. --}}
        <link rel="canonical" href="{{ url()->current() }}">
        @foreach (Locales::supported() as $alternate)
            <link rel="alternate" hreflang="{{ $alternate }}" href="{{ Locales::switchUrl($alternate) }}">
        @endforeach
        <link rel="alternate" hreflang="x-default" href="{{ Locales::switchUrl(Locales::fallback()) }}">
    @endif
    @stack('head')
    {{-- Bootstrap first (mirrored for right-to-left), then the site styles that theme it. --}}
    @vite([$bootstrap, 'resources/css/app.css', 'resources/js/app.ts', 'resources/js/ui.ts'])
</head>
<body class="@yield('body-class')" data-ui-theme="{{ $theme }}">
    <a class="skip-link" href="#main">{{ __('ui.site.skip') }}</a>

    <header class="site-header">
        <div class="wrap site-header__inner navbar navbar-expand-lg">
            <a class="brand navbar-brand" href="{{ route('home') }}">
                <svg class="brand__mark" viewBox="0 0 32 32" width="32" height="32" aria-hidden="true" focusable="false">
                    <rect width="32" height="32" rx="9"/>
                    <path d="M7 10.2 15.2 12v11.4L7 21.6zM25 10.2 16.8 12v11.4l8.2-1.8z"/>
                </svg>
                <span>{{ $siteName }}</span>
            </a>

            @if ($routeName !== 'home')
                <form class="header-search" action="{{ route('catalog') }}" method="get" role="search">
                    <label for="header-q" class="visually-hidden">{{ __('ui.home.search_label') }}</label>
                    <div class="input-group">
                    <input class="form-control" id="header-q" name="q" type="search" maxlength="200" placeholder="{{ __('ui.home.search_placeholder') }}" dir="auto" autocomplete="off">
                    <button type="submit" class="btn btn-outline-secondary" aria-label="{{ __('ui.home.search') }}">
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="6" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m15 15 5 5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                    </button>
                    </div>
                    <div class="search-suggest" data-search-suggest data-url="{{ route('catalog.suggest') }}" data-label="{{ __('ui.home.suggestions') }}" data-all="{{ __('ui.home.all_results') }}"></div>
                </form>
            @else
                <p class="header-note">{{ __('ui.site.footer_note') }}</p>
            @endif

            {{-- Without script the menu simply stays open, so the button is only revealed by ui.ts. --}}
            <button type="button" class="nav-toggle navbar-toggler" hidden aria-expanded="false" aria-controls="site-nav"
                    data-nav-toggle data-bs-toggle="collapse" data-bs-target="#site-nav">
                {{ __('ui.site.menu') }}
            </button>

            <nav id="site-nav" class="site-nav collapse navbar-collapse show" aria-label="{{ __('ui.nav.main') }}">
                <ul class="navbar-nav">
                    <li class="nav-item"><a class="nav-link @if($routeName === 'home') active @endif" href="{{ route('home') }}" @if($routeName === 'home') aria-current="page" @endif>{{ __('ui.nav.home') }}</a></li>
                    <li class="nav-item"><a class="nav-link @if($routeName === 'catalog') active @endif" href="{{ route('catalog') }}" @if($routeName === 'catalog') aria-current="page" @endif>{{ __('ui.nav.catalog') }}</a></li>
                    <li class="nav-item"><a class="nav-link @if($routeName === 'collections.index') active @endif" href="{{ route('collections.index') }}" @if($routeName === 'collections.index') aria-current="page" @endif>{{ __('ui.nav.collections') }}</a></li>
                    <li class="nav-item"><a class="nav-link @if($routeName === 'help') active @endif" href="{{ route('help', ['topic' => 'reading']) }}" @if($routeName === 'help') aria-current="page" @endif>{{ __('ui.nav.help') }}</a></li>
                    <li class="nav-item"><a class="nav-link @if($routeName === 'offline') active @endif" href="{{ route('offline') }}" @if($routeName === 'offline') aria-current="page" @endif>{{ __('ui.nav.offline') }}</a></li>
                    <li class="nav-item"><a class="nav-link @if($routeName === 'settings') active @endif" href="{{ route('settings') }}" @if($routeName === 'settings') aria-current="page" @endif>{{ __('ui.nav.settings') }}</a></li>
                    @auth
                        @if (auth()->user()->isStaff())
                            <li class="nav-item"><a class="nav-link" href="{{ route('admin.dashboard') }}">{{ __('ui.nav.admin') }}</a></li>
                        @endif
                        <li class="nav-item"><a class="nav-link" href="{{ route('account.show') }}"><bdi>{{ auth()->user()->name }}</bdi></a></li>
                        <li class="nav-item">
                            <form method="post" action="{{ route('logout') }}" data-logout-form data-user-id="{{ auth()->id() }}">
                                @csrf
                                <button type="submit" class="nav-link">{{ __('ui.nav.sign_out') }}</button>
                            </form>
                        </li>
                    @else
                        <li class="nav-item"><a class="nav-link" href="{{ route('login') }}">{{ __('ui.nav.sign_in') }}</a></li>
                    @endauth
                </ul>

                {{-- Each language is named in itself and marked with its own lang. --}}
                <ul class="lang-switch nav nav-pills" aria-label="{{ __('ui.site.interface_language') }}">
                    @foreach (Locales::supported() as $option)
                        <li class="nav-item">
                            <a class="nav-link @if($option === $locale) active @endif" href="{{ Locales::switchUrl($option) }}" lang="{{ $option }}" hreflang="{{ $option }}"
                               dir="{{ Locales::direction($option) }}" @if($option === $locale) aria-current="true" @endif>{{ Locales::nativeName($option) }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </div>
    </header>

    <div class="offline-banner alert alert-warning rounded-0 border-0 text-center mb-0" role="status" hidden data-offline-banner>{{ __('ui.offline.you_are_offline') }}</div>

    <main id="main" class="wrap main" tabindex="-1">
        @if (session('status'))
            <div class="alert alert-success alert-dismissible fade show" role="status">
                {{ session('status') }}
                <button type="button" class="btn-close" hidden data-bs-dismiss="alert" aria-label="{{ __('ui.site.close') }}"></button>
            </div>
        @endif
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="wrap">
          <div class="row gy-4">
            <div class="col-md-4 site-footer__about">
                <p class="site-footer__brand">{{ $siteName }}</p>
                <p class="site-footer__note">{{ __('ui.site.footer_note') }}</p>
            </div>

            <nav class="col-md-8" aria-label="{{ __('ui.nav.footer') }}">
                <ul class="nav">
                    <li class="nav-item"><a class="nav-link" href="{{ route('about') }}">{{ __('ui.nav.about') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('help', ['topic' => 'kindle']) }}">{{ __('ui.help.kindle') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('accessibility') }}">{{ __('ui.nav.accessibility') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('privacy') }}">{{ __('ui.nav.privacy') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('rights') }}">{{ __('ui.nav.rights') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('contact') }}">{{ __('ui.nav.contact') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('settings') }}">{{ __('ui.nav.settings') }}</a></li>
                </ul>
            </nav>
          </div>
        </div>
    </footer>

    {{-- Asked before anything is deleted; filled in and opened by ui.ts. --}}
    <div class="modal fade" id="confirm-dialog" tabindex="-1" aria-labelledby="confirm-dialog-title" aria-hidden="true" data-confirm-dialog>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="confirm-dialog-title">{{ __('ui.site.confirm_title') }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('ui.site.close') }}"></button>
                </div>
                <div class="modal-body">
                    <p>{{ __('ui.site.confirm_text') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('ui.site.cancel') }}</button>
                    <button type="button" class="btn btn-danger" data-confirm-accept></button>
                </div>
            </div>
        </div>
    </div>

    @php
        $appConfig = ['userId' => auth()->id(), 'clearAccountStorage' => session('clear_account_storage'), 'swUrl' => '/sw.js'];
    @endphp
    <script type="application/json" id="app-config">{!! json_encode($appConfig, JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    @stack('scripts')
</body>
</html>
