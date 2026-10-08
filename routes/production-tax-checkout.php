<?php

use App\Http\Controllers\ProductionTaxCheckoutController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

// Not mounted. Root composition registers this file, the provider and the early privacy middleware.
Route::withoutMiddleware(HandleInertiaRequests::class)->prefix('production/tax-checkout')->name('production.tax-checkout.')->group(function (): void {
    Route::post('previews', [ProductionTaxCheckoutController::class, 'preview'])->middleware('throttle:10,1,production-tax-checkout-write')->block(20, 5)->name('previews');
    Route::post('orders', [ProductionTaxCheckoutController::class, 'order'])->middleware('throttle:10,1,production-tax-checkout-write')->block(20, 5)->name('orders');
    Route::prefix('orders/{order}')->where(['order' => '[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}'])->group(function (): void {
        Route::post('hosted', [ProductionTaxCheckoutController::class, 'initiate'])->middleware('throttle:5,1,production-tax-checkout-provider')->block(20, 5)->name('hosted');
        Route::post('reconcile', [ProductionTaxCheckoutController::class, 'reconcile'])->middleware('throttle:5,1,production-tax-checkout-provider')->block(20, 5)->name('reconcile');
        Route::get('status', [ProductionTaxCheckoutController::class, 'status'])->middleware('throttle:30,1,production-tax-checkout-read')->block(20, 5)->name('status');
        Route::get('return', [ProductionTaxCheckoutController::class, 'returned'])->middleware('throttle:30,1,production-tax-checkout-read')->block(20, 5)->name('return');
    });
});
