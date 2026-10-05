<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Administrators must have two-factor authentication switched on. */
class RequireAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isAdmin() && config('library.admin_require_2fa') && ! $user->hasTwoFactor()) {
            return redirect()->route('account.security')
                ->with('status', __('ui.account.two_factor_required'));
        }

        return $next($request);
    }
}
