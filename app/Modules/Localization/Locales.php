<?php

namespace App\Modules\Localization;

use Illuminate\Http\Request;

class Locales
{
    /** @return list<string> */
    public static function supported(): array
    {
        return array_values(config('library.ui_locales'));
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::supported(), true);
    }

    public static function fallback(): string
    {
        $fallback = config('app.fallback_locale');

        return self::isSupported($fallback) ? $fallback : self::supported()[0];
    }

    /** Remembered preference, then the browser's Accept-Language, then the fallback. */
    public static function preferred(Request $request): string
    {
        $cookie = $request->cookie('ui_locale');
        if (is_string($cookie) && self::isSupported($cookie)) {
            return $cookie;
        }

        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(strtok(str_replace('_', '-', $language), '-'));
            // Browsers still send the legacy code "iw" for Hebrew.
            $primary = $primary === 'iw' ? 'he' : $primary;
            if (self::isSupported($primary)) {
                return $primary;
            }
        }

        return self::fallback();
    }

    public static function direction(?string $languageTag = null): string
    {
        $languageTag ??= app()->getLocale();

        return in_array(strtolower(strtok($languageTag, '-')), config('library.rtl_locales'), true) ? 'rtl' : 'ltr';
    }

    /**
     * Language used for catalog metadata (names, descriptions). Follows the
     * interface locale unless the visitor chose a different one.
     */
    public static function metadataLanguage(): string
    {
        $chosen = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->cookie('meta_lang');

        return is_string($chosen) && isset(Models\Language::map()[$chosen]) ? $chosen : app()->getLocale();
    }

    /** The language's own name for itself. */
    public static function nativeName(string $locale): string
    {
        return config("library.locale_names.$locale", $locale);
    }

    /** URL of the current page in another interface locale. */
    public static function switchUrl(string $locale): string
    {
        $route = request()->route();
        if ($route && $route->getName() && in_array('locale', $route->parameterNames(), true)) {
            return route($route->getName(), array_merge($route->originalParameters(), ['locale' => $locale]) + request()->query());
        }

        return route('home', ['locale' => $locale]);
    }
}
