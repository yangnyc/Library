<?php

namespace App\Modules\Localization\Http;

use App\Modules\Localization\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the interface locale. Localized routes carry it explicitly (/en/…);
 * other routes (sign-in, administration) use the remembered preference.
 * The interface locale never decides the language of a book or its metadata.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $fromRoute = $request->route('locale');
        $locale = is_string($fromRoute) && Locales::isSupported($fromRoute)
            ? $fromRoute
            : Locales::preferred($request);

        app()->setLocale($locale);
        URL::defaults(['locale' => $locale]);

        if ($fromRoute !== null) {
            $request->route()->forgetParameter('locale');
        }

        $response = $next($request);

        if ($fromRoute !== null && $request->cookie('ui_locale') !== $locale && method_exists($response, 'withCookie')) {
            $response->withCookie(cookie('ui_locale', $locale, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response;
    }
}
