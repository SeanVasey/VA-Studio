<?php

use App\Http\Controllers\PublicMediaController;
use App\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StorefrontController::class, 'index'])->middleware('throttle:120,1')->name('home');
Route::get('/tracks/{slug}', [StorefrontController::class, 'index'])->middleware('throttle:120,1')->name('tracks.show');
Route::get('/api/catalog', [StorefrontController::class, 'json'])->middleware('throttle:120,1')->name('catalog.index');
Route::get('/media/{asset}', PublicMediaController::class)->middleware('throttle:240,1')->name('media.public');
Route::post('/checkout', fn () => response()->json([
    'code' => 'COMMERCE_NOT_ENABLED',
    'message' => 'Checkout is being prepared. No payment has been taken.',
], 503))->middleware('throttle:10,1')->name('checkout.store');
