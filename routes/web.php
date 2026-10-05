<?php

use App\Modules\Accounts\Http\AccountController;
use App\Modules\Accounts\Http\FavoriteController;
use App\Modules\Accounts\Http\ReadingListController;
use App\Modules\Administration\Http\AuditController;
use App\Modules\Administration\Http\ContributorAdminController;
use App\Modules\Administration\Http\CsvImportController;
use App\Modules\Administration\Http\DashboardController;
use App\Modules\Administration\Http\EditionAdminController;
use App\Modules\Administration\Http\FileAdminController;
use App\Modules\Administration\Http\LanguageAdminController;
use App\Modules\Administration\Http\ReportAdminController;
use App\Modules\Administration\Http\TaxonomyAdminController;
use App\Modules\Administration\Http\UserAdminController;
use App\Modules\Administration\Http\WorkAdminController;
use App\Modules\Catalog\Http\BrowseController;
use App\Modules\Catalog\Http\CatalogController;
use App\Modules\Catalog\Http\EditionController;
use App\Modules\Catalog\Http\HomeController;
use App\Modules\Catalog\Http\PageController;
use App\Modules\Catalog\Http\SiteController;
use App\Modules\Catalog\Http\SuggestController;
use App\Modules\Catalog\Http\WorkController;
use App\Modules\Downloads\Http\DownloadController;
use App\Modules\Localization\Locales;
use App\Modules\Reader\Http\OfflineController;
use App\Modules\Reader\Http\ReaderController;
use App\Modules\Reader\Http\SessionController;
use App\Modules\Reader\Http\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Site-wide, not localized
|--------------------------------------------------------------------------
*/

Route::get('/', [SiteController::class, 'root'])->name('root');
Route::get('/healthz', [SiteController::class, 'health'])->name('health');
Route::get('/sitemap.xml', [SiteController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SiteController::class, 'robots'])->name('robots');
Route::get('/manifest.webmanifest', [SiteController::class, 'manifest'])->name('manifest');
Route::post('/preferences/metadata-language', [SiteController::class, 'metadataLanguage'])->name('preferences.metadata-language');
Route::post('/preferences/theme', [SiteController::class, 'theme'])->name('preferences.theme');

// Public downloads: no account, modest per-IP limit against bulk scraping.
Route::match(['GET', 'HEAD'], '/download/{file}/{filename?}', DownloadController::class)
    ->whereNumber('file')->middleware('throttle:downloads')->name('download');

/*
|--------------------------------------------------------------------------
| Public pages, with an explicit interface locale: /en/…, /ru/…, /he/…
|--------------------------------------------------------------------------
*/

Route::prefix('{locale}')->where(['locale' => implode('|', array_map('preg_quote', Locales::supported()))])->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::get('/catalog', CatalogController::class)->name('catalog');
    Route::get('/catalog/suggest', SuggestController::class)->middleware('throttle:suggest')->name('catalog.suggest');
    Route::get('/works/{work}', WorkController::class)->name('works.show');
    Route::get('/editions/{edition}', EditionController::class)->name('editions.show');
    Route::get('/people/{contributor}', [BrowseController::class, 'contributor'])->name('contributors.show');
    Route::get('/collections', [BrowseController::class, 'collections'])->name('collections.index');
    Route::get('/collections/{collection}', [BrowseController::class, 'collection'])->name('collections.show');
    Route::get('/categories/{category}', [BrowseController::class, 'category'])->name('categories.show');

    Route::get('/help/{topic}', [PageController::class, 'help'])->whereIn('topic', PageController::HELP_TOPICS)->name('help');
    Route::get('/about', [PageController::class, 'page'])->defaults('page', 'about')->name('about');
    Route::get('/accessibility', [PageController::class, 'page'])->defaults('page', 'accessibility')->name('accessibility');
    Route::get('/privacy', [PageController::class, 'page'])->defaults('page', 'privacy')->name('privacy');
    Route::get('/contact', [PageController::class, 'contact'])->defaults('kind', 'contact')->name('contact');
    Route::get('/rights', [PageController::class, 'contact'])->defaults('kind', 'rights')->name('rights');
    Route::post('/reports', [PageController::class, 'storeReport'])->middleware('throttle:reports')->name('reports.store');

    Route::get('/read/{edition}/{format?}', ReaderController::class)->whereIn('format', ['epub', 'pdf'])->name('read');
    Route::get('/offline', [OfflineController::class, 'bookshelf'])->name('offline');
    Route::get('/settings', [SiteController::class, 'settings'])->name('settings');

    Route::middleware(['auth', 'verified'])->prefix('account')->name('account.')->group(function () {
        Route::get('/', [AccountController::class, 'show'])->name('show');
        Route::get('/security', [AccountController::class, 'security'])->name('security');
        Route::get('/export', [AccountController::class, 'export'])->name('export');
        Route::delete('/', [AccountController::class, 'destroy'])->name('destroy');
        Route::post('/favorites/{work}', [FavoriteController::class, 'toggle'])->name('favorites.toggle');
        Route::post('/lists', [ReadingListController::class, 'store'])->name('lists.store');
        Route::delete('/lists/{list}', [ReadingListController::class, 'destroy'])->name('lists.destroy');
        Route::post('/lists/{list}/items', [ReadingListController::class, 'addItem'])->name('lists.items.add');
        Route::delete('/lists/{list}/items/{edition}', [ReadingListController::class, 'removeItem'])->name('lists.items.remove');
    });
});

/*
|--------------------------------------------------------------------------
| JSON endpoints used by the reader and the offline bookshelf
|--------------------------------------------------------------------------
*/

Route::prefix('api')->name('api.')->group(function () {
    Route::get('/session', SessionController::class)->name('session');
    Route::get('/offline-manifest/{file}', [OfflineController::class, 'manifest'])->whereNumber('file')->name('offline-manifest');
    Route::post('/sync', SyncController::class)->middleware(['auth', 'verified', 'throttle:sync'])->name('sync');
});

/*
|--------------------------------------------------------------------------
| Administration (editors and administrators)
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->name('admin.')->middleware(['auth', 'verified', 'staff', 'admin2fa'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::resource('works', WorkAdminController::class)->except(['show']);
    Route::resource('contributors', ContributorAdminController::class)->except(['show']);

    Route::get('/works/{work}/editions/create', [EditionAdminController::class, 'create'])->name('editions.create');
    Route::post('/works/{work}/editions', [EditionAdminController::class, 'store'])->name('editions.store');
    Route::get('/editions', [EditionAdminController::class, 'index'])->name('editions.index');
    Route::get('/editions/{edition:id}', [EditionAdminController::class, 'edit'])->name('editions.edit');
    Route::put('/editions/{edition:id}', [EditionAdminController::class, 'update'])->name('editions.update');
    Route::delete('/editions/{edition:id}', [EditionAdminController::class, 'destroy'])->name('editions.destroy');
    Route::post('/editions/{edition:id}/publish', [EditionAdminController::class, 'publish'])->name('editions.publish');
    Route::post('/editions/{edition:id}/unpublish', [EditionAdminController::class, 'unpublish'])->name('editions.unpublish');
    Route::post('/editions/{edition:id}/withdraw', [EditionAdminController::class, 'withdraw'])->name('editions.withdraw');
    Route::post('/editions/{edition:id}/cover', [EditionAdminController::class, 'cover'])->name('editions.cover');

    Route::post('/editions/{edition:id}/files', [FileAdminController::class, 'store'])->name('files.store');
    Route::put('/files/{file}', [FileAdminController::class, 'update'])->name('files.update');
    Route::post('/files/{file}/approve', [FileAdminController::class, 'approve'])->name('files.approve');
    Route::post('/files/{file}/apply-metadata', [FileAdminController::class, 'applyMetadata'])->name('files.apply-metadata');
    Route::post('/files/{file}/use-cover', [FileAdminController::class, 'useCover'])->name('files.use-cover');
    Route::delete('/files/{file}', [FileAdminController::class, 'destroy'])->name('files.destroy');

    Route::get('/taxonomy', [TaxonomyAdminController::class, 'index'])->name('taxonomy.index');
    Route::post('/taxonomy/{type}', [TaxonomyAdminController::class, 'store'])->whereIn('type', ['categories', 'collections', 'tags'])->name('taxonomy.store');
    Route::get('/taxonomy/{type}/{id}', [TaxonomyAdminController::class, 'edit'])->whereIn('type', ['categories', 'collections', 'tags'])->whereNumber('id')->name('taxonomy.edit');
    Route::put('/taxonomy/{type}/{id}', [TaxonomyAdminController::class, 'update'])->whereIn('type', ['categories', 'collections', 'tags'])->whereNumber('id')->name('taxonomy.update');
    Route::delete('/taxonomy/{type}/{id}', [TaxonomyAdminController::class, 'destroy'])->whereIn('type', ['categories', 'collections', 'tags'])->whereNumber('id')->name('taxonomy.destroy');

    Route::get('/csv-import', [CsvImportController::class, 'create'])->name('csv.create');
    Route::post('/csv-import', [CsvImportController::class, 'store'])->name('csv.store');
    Route::get('/csv-import/template', [CsvImportController::class, 'template'])->name('csv.template');

    Route::get('/reports', [ReportAdminController::class, 'index'])->name('reports.index');
    Route::put('/reports/{report}', [ReportAdminController::class, 'update'])->name('reports.update');
    Route::get('/audit', AuditController::class)->name('audit');

    Route::middleware('staff:admin')->group(function () {
        Route::get('/languages', [LanguageAdminController::class, 'index'])->name('languages.index');
        Route::post('/languages', [LanguageAdminController::class, 'store'])->name('languages.store');
        Route::put('/languages/{language}', [LanguageAdminController::class, 'update'])->name('languages.update');
        Route::get('/users', [UserAdminController::class, 'index'])->name('users.index');
        Route::put('/users/{user}', [UserAdminController::class, 'update'])->name('users.update');
    });
});
