<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaff
{
    public function handle(Request $request, Closure $next, string $level = 'staff'): Response
    {
        $user = $request->user();

        abort_unless($user && ($level === 'admin' ? $user->isAdmin() : $user->isStaff()), 403);

        return $next($request);
    }
}
