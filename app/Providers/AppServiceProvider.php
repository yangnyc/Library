<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Contributor;
use App\Modules\Catalog\Models\Edition;
use App\Modules\Catalog\Models\EditionFile;
use App\Modules\Catalog\Models\Series;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Catalog\Models\Work;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Stable names in polymorphic columns, independent of class locations.
        Relation::enforceMorphMap([
            'work' => Work::class,
            'edition' => Edition::class,
            'edition_file' => EditionFile::class,
            'contributor' => Contributor::class,
            'category' => Category::class,
            'collection' => Collection::class,
            'tag' => Tag::class,
            'series' => Series::class,
            'user' => User::class,
        ]);

        Model::preventLazyLoading(! $this->app->isProduction());

        Route::model('file', EditionFile::class);

        Paginator::defaultView('partials.pagination');

        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        $this->configureRateLimits();
    }

    private function configureRateLimits(): void
    {
        RateLimiter::for('downloads', fn (Request $request) => Limit::perMinute(config('library.downloads.per_minute'))->by($request->ip()));

        // A single book can be many small resources, and saving one for
        // offline use fetches all of them, so this is deliberately generous.
        RateLimiter::for('content', fn (Request $request) => Limit::perMinute(1500)->by($request->ip()));

        RateLimiter::for('reports', fn (Request $request) => [
            Limit::perMinute(3)->by($request->ip()),
            Limit::perDay(20)->by($request->ip()),
        ]);

        // One request per pause in typing; well above what a person produces.
        RateLimiter::for('suggest', fn (Request $request) => Limit::perMinute(90)->by($request->ip()));

        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
    }
}
