<?php

use App\Http\Controllers\ProductionCheckoutController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

// Root composition explicitly registers this file, provider and early privacy middleware.
Route::withoutMiddleware(HandleInertiaRequests::class)->prefix('production/checkout')->name('production.checkout.')->group(function (): void {
    Route::post('reviews', [ProductionCheckoutController::class, 'review'])->middleware('throttle:10,1,production-checkout-write')->block(20, 5)->name('reviews');
    Route::post('orders', [ProductionCheckoutController::class, 'accept'])->middleware('throttle:10,1,production-checkout-write')->block(20, 5)->name('orders');
    Route::prefix('orders/{order}')->where(['order' => '[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}'])->group(function (): void {
        Route::post('hosted', [ProductionCheckoutController::class, 'initiate'])->middleware('throttle:5,1,production-checkout-provider')->block(20, 5)->name('hosted');
        Route::post('reconcile', [ProductionCheckoutController::class, 'reconcile'])->middleware('throttle:5,1,production-checkout-provider')->block(20, 5)->name('reconcile');
        Route::get('status', [ProductionCheckoutController::class, 'status'])->middleware('throttle:30,1,production-checkout-read')->block(20, 5)->name('status');
        Route::get('return', [ProductionCheckoutController::class, 'returned'])->middleware('throttle:30,1,production-checkout-read')->block(20, 5)->name('return');
    });
});
