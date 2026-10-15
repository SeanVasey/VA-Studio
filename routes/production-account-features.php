<?php

use App\Http\AccountFeatures\AccountFeatureController;
use App\Http\AccountFeatures\AccountFeatureMount;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

if (AccountFeatureMount::admitted()) {
    Route::get('/customer/features', [AccountFeatureController::class, 'page'])->middleware('throttle:60,1,account-features-page');
    Route::withoutMiddleware(HandleInertiaRequests::class)->middleware('throttle:60,1,account-features')->group(function (): void {
        Route::get('/customer/features/listening', [AccountFeatureController::class, 'listeningRead']);
        Route::post('/customer/features/listening/initialize', [AccountFeatureController::class, 'listeningInitialize']);
        Route::post('/customer/features/listening/change', [AccountFeatureController::class, 'listeningChange']);
        Route::post('/customer/features/listening/export', [AccountFeatureController::class, 'listeningExport']);
        Route::get('/customer/features/preferences', [AccountFeatureController::class, 'preferencesRead']);
        Route::post('/customer/features/preferences/initialize', [AccountFeatureController::class, 'preferencesInitialize']);
        Route::post('/customer/features/preferences/change', [AccountFeatureController::class, 'preferencesChange']);
    });
}
