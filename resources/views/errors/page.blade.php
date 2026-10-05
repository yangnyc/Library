@php
    // Error pages can render before any middleware ran (e.g. an unknown URL),
    // so they pick the locale themselves and use no named routes.
    use App\Modules\Localization\Locales;
    $first = request()->segment(1);
    $locale = Locales::isSupported($first) ? $first : Locales::preferred(request());
    app()->setLocale($locale);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ Locales::direction($locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('ui.errors.'.$code.'_title') }} — {{ config('library.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 2rem 1rem; background: #faf7f0; color: #141213; line-height: 1.6; }
        main { max-inline-size: 36rem; margin-inline: auto; }
        a { color: #7a1f33; }
        @media (prefers-color-scheme: dark) { body { background: #0a0a0b; color: #ece6d8; } a { color: #d9b65c; } }
    </style>
</head>
<body>
    <main>
        <p>{{ config('library.name') }}</p>
        <h1>{{ __('ui.errors.'.$code.'_title') }}</h1>
        <p>{{ __('ui.errors.'.$code.'_text') }}</p>
        <p><a href="{{ url('/'.$locale) }}">{{ __('ui.errors.back_home') }}</a></p>
    </main>
</body>
</html>
