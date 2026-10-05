<?php

use App\Modules\Downloads\Http\ContentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Book content
|--------------------------------------------------------------------------
| Loaded outside the `web` group on purpose: no session, no cookies, no CSRF
| token. See ContentAccess for how access is decided without credentials.
*/

Route::match(['GET', 'HEAD'], '/content/{file}/{access}/{path}', ContentController::class)
    ->whereNumber('file')
    ->where('access', '[A-Za-z0-9\-]+')
    ->where('path', '.*')
    ->middleware('throttle:content')
    ->name('content');
