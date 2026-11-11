<?php

use App\Http\Controllers\SoundKitPurchaseController;
use App\Http\Controllers\SoundKitStorefrontController;
use App\Http\Middleware\CustomerCommerceAccess;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

// Always registered; every page answers 404 until SoundKitStorefrontPolicy::enabled() (the production literal, or the admitted
// Stripe-test purchase lane in local/testing/staging).
Route::get('/sound-kits', [SoundKitStorefrontController::class, 'index'])->middleware('throttle:120,1')->name('sound-kits.index');
Route::get('/sound-kits/{slug}', [SoundKitStorefrontController::class, 'show'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->middleware('throttle:120,1')->name('sound-kits.show');
// The tagged demo preview of a listed kit's approved demo; never the kit archive. Same throttle as the track preview route.
// No middleware is removed: the pages and this route share the web stack, as the album cover route does.
Route::get('/sound-kits/{slug}/demo/{demo}', [SoundKitStorefrontController::class, 'demo'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->whereUuid('demo')->middleware('throttle:240,1')->name('sound-kits.demo');

// BL-13: the Stripe TEST purchase lane of a kit. Every action answers 404 unless SoundKitPurchasePolicy admits it (local, testing
// or staging with VASEY_SOUND_KIT_TEST_CHECKOUT_ENABLED=true); production never does. JSON boundaries keep web sessions and CSRF
// but skip Inertia, as the track quote and order routes do.
Route::withoutMiddleware(HandleInertiaRequests::class)->middleware(CustomerCommerceAccess::class)->group(function (): void {
    Route::post('/sound-kits/{slug}/orders', [SoundKitPurchaseController::class, 'store'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->middleware('throttle:10,1,sound-kit-orders')->name('sound-kits.orders.store');
    Route::prefix('/sound-kit-orders/{order}')->whereUuid('order')->name('sound-kit-orders.')->group(function (): void {
        Route::get('status', [SoundKitPurchaseController::class, 'status'])->middleware('throttle:60,1,sound-kit-orders-read')->name('status');
        Route::post('checkout', [SoundKitPurchaseController::class, 'checkout'])->middleware('throttle:10,1,sound-kit-orders')->name('checkout');
        Route::post('checkout/reconcile', [SoundKitPurchaseController::class, 'reconcile'])->middleware('throttle:10,1,sound-kit-orders')->name('reconcile');
        Route::post('delivery/authorizations', [SoundKitPurchaseController::class, 'authorizeDownload'])->middleware('throttle:10,1,sound-kit-orders')->name('authorizations');
        Route::post('delivery/download', [SoundKitPurchaseController::class, 'download'])->middleware('throttle:10,1,sound-kit-orders')->name('download');
    });
});
// The owner's order page, also Stripe's return address. Arrival proves nothing.
Route::get('/sound-kit-orders/{order}', [SoundKitPurchaseController::class, 'show'])->whereUuid('order')
    ->middleware([CustomerCommerceAccess::class, 'throttle:60,1,sound-kit-orders-read'])->name('sound-kit-orders.show');
