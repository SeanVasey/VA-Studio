<?php

use App\Http\Controllers\PaidTaxController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::withoutMiddleware(HandleInertiaRequests::class)->group(function (): void {
    $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
    Route::post('/paid-tax-grants/orders/{order}/finalize', [PaidTaxController::class, 'finalize'])->where('order', $uuid)->middleware('throttle:20,1,paid-tax-grants')->block(20, 5)->name('paid-tax-grants.finalize');
    Route::get('/paid-tax-grants/origins/{batch}', [PaidTaxController::class, 'show'])->where('batch', $uuid)->middleware('throttle:60,1,paid-tax-grants')->block(20, 5)->name('paid-tax-grants.show');
    Route::get('/paid-tax-grants/origins/{batch}/downloads', [PaidTaxController::class, 'downloads'])->where('batch', $uuid)->middleware('throttle:60,1,paid-tax-grants')->block(20, 5)->name('paid-tax-grants.downloads');
    Route::post('/paid-tax-grants/origins/{batch}/document', [PaidTaxController::class, 'document'])->where('batch', $uuid)->middleware('throttle:6,1,paid-render')->block(20, 5)->name('paid-tax-grants.document');
    Route::post('/paid-tax-grants/origins/{batch}/lines/{line}/authorize', [PaidTaxController::class, 'authorize'])->where(['batch' => $uuid, 'line' => $uuid])->middleware('throttle:20,1,paid-tax-grants')->block(20, 5)->name('paid-tax-grants.authorize');
    Route::post('/paid-tax-grants/origins/{batch}/authorizations/{authorization}/redeem', [PaidTaxController::class, 'redeem'])->where(['batch' => $uuid, 'authorization' => $uuid])->middleware('throttle:20,1,paid-tax-grants')->block(20, 5)->name('paid-tax-grants.redeem');
});
