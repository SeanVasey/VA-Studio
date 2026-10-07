<?php

use App\Http\Controllers\CustomerIdentityController;
use App\Http\Controllers\CustomerListeningLibraryController;
use App\Http\Controllers\CustomerMembershipHistoryController;
use App\Http\Controllers\CustomerPurchaseClaimController;
use App\Http\Controllers\CustomerSessionController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::get('/account/sign-in', [CustomerSessionController::class, 'signIn'])->middleware('throttle:60,1,customer-pages')->name('customer.sign-in');
Route::get('/account', [CustomerSessionController::class, 'library'])->middleware('throttle:60,1,customer-pages')->name('customer.library');
foreach (['create', 'recover', 'access'] as $page) {
    Route::get('/account/'.$page, [CustomerIdentityController::class, 'page'])->middleware('throttle:60,1,customer-pages')->name('customer.identity.'.$page);
}
Route::withoutMiddleware(HandleInertiaRequests::class)->group(function (): void {
    Route::post('/account/identity/request', [CustomerIdentityController::class, 'request'])->middleware('throttle:5,1,customer-identity')->block(20, 5)->name('customer.identity.request');
    Route::post('/account/identity/complete', [CustomerIdentityController::class, 'complete'])->middleware('throttle:10,1,customer-identity-complete')->block(20, 5)->name('customer.identity.complete');
    Route::post('/account/purchase-claim/stage', [CustomerPurchaseClaimController::class, 'stage'])->middleware('throttle:5,1,customer-purchase-claim')->block(20, 5)->name('customer.purchase-claim.stage');
    Route::post('/account/purchase-claim/complete', [CustomerPurchaseClaimController::class, 'complete'])->middleware('throttle:10,1,customer-purchase-claim-complete')->block(20, 5)->name('customer.purchase-claim.complete');
    Route::post('/account/sign-in', [CustomerSessionController::class, 'store'])->middleware('throttle:10,1,customer-auth')->block(20, 5)->name('customer.sign-in.store');
    Route::post('/account/sign-out', [CustomerSessionController::class, 'destroy'])->middleware('throttle:20,1,customer-auth')->block(20, 5)->name('customer.sign-out');
});

Route::withoutMiddleware(HandleInertiaRequests::class)->group(function (): void {
    Route::get('/account/listening-library', [CustomerListeningLibraryController::class, 'index'])
        ->middleware('throttle:60,1,customer-pages')->block(20, 5)->name('customer.listening-library.index');
    Route::post('/account/listening-library', [CustomerListeningLibraryController::class, 'store'])
        ->middleware('throttle:30,1,customer-listening')->block(20, 5)->name('customer.listening-library.store');
    Route::get('/account/membership-credits', [CustomerMembershipHistoryController::class, 'index'])
        ->middleware('throttle:60,1,customer-pages')->block(20, 5)->name('customer.membership-credits.index');
    Route::get('/account/membership-credits/{bucket}', [CustomerMembershipHistoryController::class, 'show'])
        ->where('bucket', '[1-9][0-9]{0,17}')->middleware('throttle:60,1,customer-pages')->block(20, 5)->name('customer.membership-credits.show');
});
