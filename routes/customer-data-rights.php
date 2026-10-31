<?php

use App\Http\Controllers\CustomerDataRightsController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

// Lane CX (FP-082): customer data export and deletion requests. The controller answers 404 unless
// customer-data-rights.enabled and the customer access policy admit the request. CustomerPrivacy applies (/account/*).
Route::withoutMiddleware(HandleInertiaRequests::class)->group(function (): void {
    Route::get('/account/data-requests', [CustomerDataRightsController::class, 'index'])
        ->middleware('throttle:30,1,customer-data-requests')->block(20, 5)->name('customer.data-requests.index');
    Route::post('/account/data-requests/export', [CustomerDataRightsController::class, 'export'])
        ->middleware('throttle:3,60,customer-data-export')->block(20, 5)->name('customer.data-requests.export');
    Route::post('/account/data-requests/export/download', [CustomerDataRightsController::class, 'download'])
        ->middleware('throttle:10,10,customer-data-download')->block(20, 5)->name('customer.data-requests.download');
    Route::post('/account/data-requests/deletion', [CustomerDataRightsController::class, 'deletion'])
        ->middleware('throttle:3,60,customer-data-deletion')->block(20, 5)->name('customer.data-requests.deletion');
});
