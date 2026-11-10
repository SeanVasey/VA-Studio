<?php

use App\Http\Controllers\SoundKitStorefrontController;
use Illuminate\Support\Facades\Route;

// Always registered; every action answers 404 until SoundKitStorefrontPolicy::enabled() (config sound-kits.storefront_enabled === true).
// There is deliberately no checkout, cart or download route for kits (docs/sound-kit-storefront.md).
Route::get('/sound-kits', [SoundKitStorefrontController::class, 'index'])->middleware('throttle:120,1')->name('sound-kits.index');
Route::get('/sound-kits/{slug}', [SoundKitStorefrontController::class, 'show'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->middleware('throttle:120,1')->name('sound-kits.show');
