<?php

use App\Http\Controllers\FreeGrantController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::get('/free-grants', [FreeGrantController::class, 'page'])->middleware('throttle:60,1,free-grants')->name('free-grants.page');
Route::withoutMiddleware(HandleInertiaRequests::class)->group(function (): void {
    Route::get('/free-grants/index', [FreeGrantController::class, 'index'])->middleware('throttle:60,1,free-grants')->block(20, 5)->name('free-grants.index');
    $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
    Route::post('/free-grants/definitions/{definition}/review', [FreeGrantController::class, 'review'])->where('definition', $uuid)->middleware('throttle:30,1,free-grants')->block(20, 5)->name('free-grants.review');
    Route::post('/free-grants/definitions/{definition}/accept', [FreeGrantController::class, 'accept'])->where('definition', $uuid)->middleware('throttle:20,1,free-grants')->block(20, 5)->name('free-grants.accept');
    Route::get('/free-grants/origins/{origin}', [FreeGrantController::class, 'show'])->where('origin', $uuid)->middleware('throttle:60,1,free-grants')->block(20, 5)->name('free-grants.show');
    Route::get('/free-grants/origins/{origin}/downloads', [FreeGrantController::class, 'downloads'])->where('origin', $uuid)->middleware('throttle:60,1,free-grants')->block(20, 5)->name('free-grants.downloads');
    Route::post('/free-grants/origins/{origin}/document', [FreeGrantController::class, 'document'])->where('origin', $uuid)->middleware('throttle:6,1,free-render')->block(20, 5)->name('free-grants.document');
    Route::post('/free-grants/origins/{origin}/authorize', [FreeGrantController::class, 'authorize'])->where('origin', $uuid)->middleware('throttle:20,1,free-grants')->block(20, 5)->name('free-grants.authorize');
    Route::post('/free-grants/authorizations/{authorization}/redeem', [FreeGrantController::class, 'redeem'])->where('authorization', $uuid)->middleware('throttle:20,1,free-grants')->block(20, 5)->name('free-grants.redeem');
});
