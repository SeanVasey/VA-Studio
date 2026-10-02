<?php

use App\Http\Controllers\PublicTrackEmbedController;
use Illuminate\Support\Facades\Route;

// Loaded outside the web group: no sessions, auth, cookies, CSRF or Inertia state.
// Named IP-only budgets are registered by AppServiceProvider on cached and uncached boots.
Route::get('/embed/tracks/{slug}', [PublicTrackEmbedController::class, 'show'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->middleware('throttle:public-track-embed')->name('embeds.show');
Route::get('/embed/tracks/{slug}/preview/{asset}', [PublicTrackEmbedController::class, 'preview'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->where('asset', '[1-9][0-9]*')
    ->middleware('throttle:public-track-embed-audio')->name('embeds.preview');
