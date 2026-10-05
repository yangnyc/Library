<?php

use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\RequireAdminTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Localization\Http\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            // Book content is served without sessions or cookies: untrusted
            // publication resources never share a response with credentials.
            Route::middleware([SubstituteBindings::class])
                ->group(base_path('routes/content.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetLocale::class, SecurityHeaders::class]);
        $middleware->alias([
            'staff' => EnsureStaff::class,
            'admin2fa' => RequireAdminTwoFactor::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
        $middleware->encryptCookies(except: ['ui_locale']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
