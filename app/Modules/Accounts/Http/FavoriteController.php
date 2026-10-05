<?php

namespace App\Modules\Accounts\Http;

use App\Modules\Catalog\Models\Work;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FavoriteController
{
    public function toggle(Request $request, Work $work): RedirectResponse
    {
        abort_unless($work->publishedEditions()->exists(), 404);

        $request->user()->favorites()->toggle($work->id);

        return back();
    }
}
