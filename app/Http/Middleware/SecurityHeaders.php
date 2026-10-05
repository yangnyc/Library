<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::policy());
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        // Personalized or administrative pages must not be stored by shared caches.
        if ($request->user() || $request->is('admin', 'admin/*', 'api/*')) {
            $headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    /**
     * No inline scripts anywhere. The reader page widens this for book content
     * only (see ReaderController), never for scripts from a book.
     *
     * @param  array<string, list<string>>  $extra  additional sources per directive
     */
    public static function policy(array $extra = []): string
    {
        $policy = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'"],
            'connect-src' => ["'self'"],
            'worker-src' => ["'self'"],
            'manifest-src' => ["'self'"],
            'frame-src' => ["'none'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        foreach ($extra as $directive => $sources) {
            $current = array_diff($policy[$directive] ?? [], ["'none'"]);
            $policy[$directive] = array_values(array_unique(array_merge($current, $sources)));
        }

        return implode('; ', array_map(
            fn (string $directive, array $sources) => $directive.' '.implode(' ', $sources),
            array_keys($policy), $policy
        ));
    }
}
