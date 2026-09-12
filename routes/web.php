<?php

use App\Http\Controllers\PublicMediaController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\StorefrontController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::get('/', [StorefrontController::class, 'index'])->middleware('throttle:120,1')->name('home');
Route::get('/tracks/{slug}', [StorefrontController::class, 'index'])->middleware('throttle:120,1')->name('tracks.show');
Route::get('/api/catalog', [StorefrontController::class, 'json'])->middleware('throttle:120,1')->name('catalog.index');
Route::get('/media/{asset}', PublicMediaController::class)->middleware('throttle:240,1')->name('media.public');
// JSON quote boundaries retain web sessions/CSRF but do not pass through Inertia,
// whose response negotiation replaces the Cookie Vary header.
Route::withoutMiddleware(HandleInertiaRequests::class)->group(function (): void {
    Route::get('/tracks/{slug}/offers/{revision}/license', [StorefrontController::class, 'license'])->whereNumber('revision')->middleware('throttle:60,1')->name('tracks.license');
    Route::post('/catalog/selections', [StorefrontController::class, 'selections'])->middleware('throttle:60,1')->name('catalog.selections');
    Route::post('/quotes', [QuoteController::class, 'store'])->middleware('throttle:10,1,quotes-create')->block(120, 10)->name('quotes.store');
    Route::get('/quotes/{quote}', [QuoteController::class, 'show'])->middleware('throttle:60,1,quotes-read')->block(120, 10)->name('quotes.show');
    Route::get('/quotes/{quote}/offers/{revision}/license', [QuoteController::class, 'license'])->whereNumber('revision')->middleware('throttle:60,1,quotes-read')->block(120, 10)->name('quotes.license');
    Route::post('/quotes/{quote}/pricing', [QuoteController::class, 'price'])->middleware('throttle:10,1,quotes-create')->block(120, 10)->name('quotes.price');
    Route::get('/quotes/{quote}/pricing', [QuoteController::class, 'pricing'])->middleware('throttle:60,1,quotes-read')->block(120, 10)->name('quotes.pricing');
});
Route::post('/checkout', fn () => response()->json([
    'code' => 'COMMERCE_NOT_ENABLED',
    'message' => 'Checkout is being prepared. No payment has been taken.',
], 503))->middleware('throttle:10,1')->name('checkout.store');
