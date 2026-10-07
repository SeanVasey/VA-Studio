<?php

use App\Http\Controllers\ProductionIdentity\CustomerIdentityController;
use App\Http\Middleware\ProductionIdentity\IdentityPrivacy;
use Illuminate\Support\Facades\Route;

// Root mounts inside web/CSRF and prepends privacy before body logging. No binding or mount is implied here.
Route::middleware([IdentityPrivacy::class])->group(function (): void {
    Route::get('/customer', [CustomerIdentityController::class, 'home']);
    foreach (['create', 'recover', 'access', 'sign-in'] as $page) {
        Route::get('/customer/'.$page, [CustomerIdentityController::class, 'page']);
    }
    Route::post('/customer/identity/request', [CustomerIdentityController::class, 'request'])->middleware('throttle:10,1');
    Route::post('/customer/identity/complete', [CustomerIdentityController::class, 'complete'])->middleware('throttle:10,1');
    Route::post('/customer/sign-in', [CustomerIdentityController::class, 'signIn'])->middleware('throttle:10,1');
    Route::post('/customer/sign-out', [CustomerIdentityController::class, 'signOut']);
});
