<?php

use App\Http\Controllers\PublicPagesSitemapController;
use Illuminate\Support\Facades\Route;

// No session, Inertia or cookies are needed for public URL discovery.
Route::get('/site-pages-sitemap.xml', PublicPagesSitemapController::class)
    ->withoutMiddleware('web')->middleware('throttle:60,1,public-discovery')->name('discovery.site-pages');
