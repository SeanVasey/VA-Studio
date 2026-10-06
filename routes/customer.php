<?php

use App\Http\Controllers\CustomerSessionController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::get('/account/sign-in', [CustomerSessionController::class, 'signIn'])->middleware('throttle:60,1,customer-pages')->name('customer.sign-in');
Route::get('/account', [CustomerSessionController::class, 'library'])->middleware('throttle:60,1,customer-pages')->name('customer.library');
Route::withoutMiddleware(HandleInertiaRequests::class)->group(function (): void {
    Route::post('/account/sign-in', [CustomerSessionController::class, 'store'])->middleware('throttle:10,1,customer-auth')->block(20, 5)->name('customer.sign-in.store');
    Route::post('/account/sign-out', [CustomerSessionController::class, 'destroy'])->middleware('throttle:20,1,customer-auth')->block(20, 5)->name('customer.sign-out');
});
