<?php

use App\Http\Controllers\PublicDiscoveryController;
use App\Http\Controllers\PublicPagesSitemapController;
use Illuminate\Support\Facades\Route;

// The public albums routes (BL-67) are mounted here: routes/web.php is shared and not yet Pint-clean, so it stays untouched.
require __DIR__.'/albums.php';
// The public collections routes (BL-54) are mounted here for the same reason.
require __DIR__.'/collections.php';

// No session, Inertia or cookies are needed for public URL discovery.
Route::get('/site-pages-sitemap.xml', PublicPagesSitemapController::class)
    ->withoutMiddleware('web')->middleware('throttle:60,1,public-discovery')->name('discovery.site-pages');

Route::get('/sitemap.xml', [PublicDiscoveryController::class, 'index'])
    ->withoutMiddleware('web')->middleware('throttle:60,1,public-discovery')->name('discovery.index');
Route::get('/robots.txt', [PublicDiscoveryController::class, 'robots'])
    ->withoutMiddleware('web')->middleware('throttle:60,1,public-discovery')->name('discovery.robots');
