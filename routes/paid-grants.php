<?php

use App\Http\Controllers\PaidGrantController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::get('/paid-grants', [PaidGrantController::class, 'page'])->middleware('throttle:60,1,paid-grants')->name('paid-grants.page');
Route::withoutMiddleware(HandleInertiaRequests::class)->group(function (): void {
    $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
    Route::get('/paid-grants/index', [PaidGrantController::class, 'index'])->middleware('throttle:60,1,paid-grants')->block(20, 5)->name('paid-grants.index');
    Route::post('/paid-grants/orders/{order}/finalize', [PaidGrantController::class, 'finalize'])->where('order', $uuid)->middleware('throttle:20,1,paid-grants')->block(20, 5)->name('paid-grants.finalize');
    Route::get('/paid-grants/origins/{batch}', [PaidGrantController::class, 'show'])->where('batch', $uuid)->middleware('throttle:60,1,paid-grants')->block(20, 5)->name('paid-grants.show');
    Route::get('/paid-grants/origins/{batch}/downloads', [PaidGrantController::class, 'downloads'])->where('batch', $uuid)->middleware('throttle:60,1,paid-grants')->block(20, 5)->name('paid-grants.downloads');
    Route::post('/paid-grants/origins/{batch}/document', [PaidGrantController::class, 'document'])->where('batch', $uuid)->middleware('throttle:6,1,paid-render')->block(20, 5)->name('paid-grants.document');
    Route::post('/paid-grants/origins/{batch}/lines/{line}/authorize', [PaidGrantController::class, 'authorize'])->where(['batch' => $uuid, 'line' => $uuid])->middleware('throttle:20,1,paid-grants')->block(20, 5)->name('paid-grants.authorize');
    Route::post('/paid-grants/authorizations/{authorization}/redeem', [PaidGrantController::class, 'redeem'])->where('authorization', $uuid)->middleware('throttle:20,1,paid-grants')->block(20, 5)->name('paid-grants.redeem');
});
