@php
    use App\Modules\Localization\Locales;
    use App\Modules\Localization\Models\Language;

    $locale = app()->getLocale();
@endphp
@extends('layouts.app', ['noindex' => true])
@section('title', __('ui.settings.title'))

@section('content')
    <div class="settings-page">
        <h1>{{ __('ui.settings.title') }}</h1>
        <p class="lead">{{ __('ui.settings.intro') }}</p>

        <section class="card card-body mb-3" aria-labelledby="appearance-heading">
            <h2 id="appearance-heading">{{ __('ui.settings.appearance') }}</h2>
            <form method="post" action="{{ route('preferences.theme') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="theme">{{ __('ui.settings.theme') }}</label>
                    {{-- With script the page previews the choice at once; saving always goes through the server. --}}
                    <select class="form-select" id="theme" name="theme" data-theme-select aria-describedby="theme-hint">
                        @foreach ($themes as $key => $definition)
                            <option value="{{ $key }}" data-scheme="{{ $definition['scheme'] }}" @selected($key === $theme)>{{ __('ui.settings.themes.'.$key) }}</option>
                        @endforeach
                    </select>
                    <p class="text-body-secondary small" id="theme-hint">{{ __('ui.settings.theme_hint') }}</p>
                </div>
                <div><button type="submit" class="btn btn-primary">{{ __('ui.settings.save') }}</button></div>
            </form>
        </section>

        <section class="card card-body mb-3" aria-labelledby="languages-heading">
            <h2 id="languages-heading">{{ __('ui.settings.languages') }}</h2>

            <h3>{{ __('ui.site.interface_language') }}</h3>
            <ul class="list-unstyled d-flex flex-wrap gap-2 align-items-center">
                @foreach (Locales::supported() as $option)
                    <li>
                        <a class="btn btn-sm btn-outline-secondary rounded-pill @if($option === $locale) active @endif" href="{{ Locales::switchUrl($option) }}" lang="{{ $option }}" hreflang="{{ $option }}"
                           dir="{{ Locales::direction($option) }}" @if($option === $locale) aria-current="true" @endif>{{ Locales::nativeName($option) }}</a>
                    </li>
                @endforeach
            </ul>

            <form method="post" action="{{ route('preferences.metadata-language') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="meta-lang">{{ __('ui.site.metadata_language') }}</label>
                    <select class="form-select" id="meta-lang" name="language">
                        <option value="">{{ __('ui.site.metadata_same') }}</option>
                        @foreach (Language::map() as $tag => $language)
                            <option value="{{ $tag }}" lang="{{ $tag }}" @selected(request()->cookie('meta_lang') === $tag)>{{ $language['native_name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div><button type="submit" class="btn btn-outline-secondary">{{ __('ui.site.apply') }}</button></div>
            </form>
        </section>
    </div>
@endsection
