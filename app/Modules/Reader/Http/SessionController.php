<?php

namespace App\Modules\Reader\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tells page scripts who is signed in. Kept out of page HTML so that pages can
 * be stored for offline use without containing anything personal. Never cached.
 */
class SessionController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'preferences' => $user->reader_preferences,
                'preferencesRevision' => $user->preferences_revision,
            ] : null,
            'csrf' => csrf_token(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
