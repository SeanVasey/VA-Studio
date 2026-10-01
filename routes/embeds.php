<?php

use App\Http\Controllers\PublicTrackEmbedController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// Loaded outside the web group: no sessions, auth, cookies, CSRF or Inertia state.
// Explicit IP keys avoid the generic throttle's authenticated-user lookup and preserve separate budgets.
RateLimiter::for('public-track-embed', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
RateLimiter::for('public-track-embed-audio', fn (Request $request) => Limit::perMinute(240)->by($request->ip()));
Route::get('/embed/tracks/{slug}', [PublicTrackEmbedController::class, 'show'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->middleware('throttle:public-track-embed')->name('embeds.show');
Route::get('/embed/tracks/{slug}/preview/{asset}', [PublicTrackEmbedController::class, 'preview'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->where('asset', '[1-9][0-9]*')
    ->middleware('throttle:public-track-embed-audio')->name('embeds.preview');
