<?php

use App\Http\Controllers\DiscoveryTrackSitemapController;
use Illuminate\Support\Facades\Route;

// Root bootstrap registration is deliberately separate: no sessions, Inertia, authentication or private build endpoint.
Route::get('/track-sitemaps/{generation}/{slot}.xml', DiscoveryTrackSitemapController::class)
    ->name('discovery.tracks')->middleware('throttle:120,1,public-discovery');
