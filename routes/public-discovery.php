<?php

use App\Http\Controllers\PublicDiscoveryController;
use App\Http\Controllers\PublicPagesSitemapController;
use Illuminate\Support\Facades\Route;

// No session, Inertia or cookies are needed for public URL discovery.
Route::get('/site-pages-sitemap.xml', PublicPagesSitemapController::class)
    ->withoutMiddleware('web')->middleware('throttle:60,1,public-discovery')->name('discovery.site-pages');

Route::get('/sitemap.xml', [PublicDiscoveryController::class, 'index'])
    ->withoutMiddleware('web')->middleware('throttle:60,1,public-discovery')->name('discovery.index');
Route::get('/robots.txt', [PublicDiscoveryController::class, 'robots'])
    ->withoutMiddleware('web')->middleware('throttle:60,1,public-discovery')->name('discovery.robots');
