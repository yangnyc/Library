<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\Work;
use App\Modules\Localization\Locales;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Themes;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class SiteController
{
    public function root(Request $request): RedirectResponse
    {
        return redirect()->route('home', ['locale' => Locales::preferred($request)], 302)
            ->header('Vary', 'Accept-Language, Cookie');
    }

    /** Liveness for uptime monitors. Reports state only — no versions, paths or settings. */
    public function health(): JsonResponse
    {
        $checks = ['database' => false, 'storage' => false];

        try {
            DB::select('SELECT 1');
            $checks['database'] = true;
        } catch (\Throwable) {
        }
        try {
            $checks['storage'] = is_writable(Storage::disk(config('library.storage_disk'))->path(''));
        } catch (\Throwable) {
        }

        $ok = ! in_array(false, $checks, true);

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /** Catalog metadata language, kept separately from the interface language. */
    public function metadataLanguage(Request $request): RedirectResponse
    {
        $tag = (string) $request->input('language');
        $valid = $tag === '' || isset(Language::map()[$tag]);

        return back()->withCookie(
            $valid && $tag !== ''
                ? cookie('meta_lang', $tag, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax')
                : cookie()->forget('meta_lang')
        );
    }

    /** Interface preferences that live in cookies: colour theme and catalog metadata language. */
    public function settings(Request $request): View
    {
        return view('public.settings', ['theme' => Themes::current($request), 'themes' => Themes::all()]);
    }

    public function theme(Request $request): RedirectResponse
    {
        $theme = (string) $request->input('theme');
        $valid = $theme !== Themes::DEFAULT && isset(Themes::all()[$theme]);

        return back()->with('status', __('ui.settings.saved'))->withCookie(
            $valid
                ? cookie(Themes::COOKIE, $theme, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax')
                : cookie()->forget(Themes::COOKIE)
        );
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /api/',
            'Disallow: /content/',
            'Disallow: /download/',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /*/read/',
            'Disallow: /*/account',
            'Disallow: /*/offline',
            'Disallow: /*/settings',
            '',
            'Sitemap: '.route('sitemap'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function sitemap(): Response
    {
        $locales = Locales::supported();
        $entries = [];
        $add = function (string $route, array $parameters = [], $lastModified = null) use (&$entries, $locales) {
            $alternates = [];
            foreach ($locales as $locale) {
                $alternates[$locale] = route($route, ['locale' => $locale] + $parameters);
            }
            $entries[] = ['alternates' => $alternates, 'lastmod' => $lastModified?->toAtomString()];
        };

        $add('home');
        $add('catalog');
        $add('collections.index');

        Work::query()->whereHas('publishedEditions')->select('id', 'slug', 'updated_at')->orderBy('id')->limit(5000)->get()
            ->each(fn (Work $work) => $add('works.show', ['work' => $work->slug], $work->updated_at));
        Edition::query()->published()->select('id', 'slug', 'updated_at')->orderBy('id')->limit(10000)->get()
            ->each(fn (Edition $edition) => $add('editions.show', ['edition' => $edition->slug], $edition->updated_at));
        Collection::query()->where('is_published', true)->whereHas('works.publishedEditions')->select('id', 'slug', 'updated_at')->get()
            ->each(fn (Collection $collection) => $add('collections.show', ['collection' => $collection->slug], $collection->updated_at));

        return response()->view('public.sitemap', ['entries' => $entries, 'fallback' => Locales::fallback()])
            ->header('Content-Type', 'application/xml; charset=utf-8');
    }

    public function manifest(Request $request): JsonResponse
    {
        $locale = Locales::preferred($request);

        return response()->json([
            'name' => config('library.name'),
            'short_name' => config('library.short_name'),
            'description' => __('ui.site.tagline', [], $locale),
            'lang' => $locale,
            'dir' => Locales::direction($locale),
            'start_url' => route('home', ['locale' => $locale], false).'?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#0a0a0b',
            'theme_color' => config('library.theme_color'),
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ])->header('Content-Type', 'application/manifest+json')->header('Cache-Control', 'public, max-age=3600');
    }
}
