<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\ProductionFree\ProductionFreeController;
use Illuminate\Support\Facades\Route;

// Family 256 (production free grants). Required from routes/web.php only when ProductionFreeHttpMount admits the
// literal `production-free-grants.http_mounted`; the shipped value is false. ProductionFreePrivacy (prepended in
// bootstrap/app.php) repeats that check for every request, so a stale cached route table cannot reopen it.
// JSON plus one native form, no page and no Inertia props; no token or other private value in any URL.
// One rate-limit prefix per tier, so library views cannot exhaust the review, write or redemption budget.
Route::withoutMiddleware(HandleInertiaRequests::class)->group(function (): void {
    $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    Route::get('/production-free-grants/library', [ProductionFreeController::class, 'index'])
        ->middleware('throttle:60,1,production-free-grants-reads')->block(20, 5)->name('production-free-grants.index');
    Route::get('/production-free-grants/origins/{origin}', [ProductionFreeController::class, 'show'])->where('origin', $uuid)
        ->middleware('throttle:60,1,production-free-grants-reads')->block(20, 5)->name('production-free-grants.show');
    Route::post('/production-free-grants/definitions/{definition}/review', [ProductionFreeController::class, 'review'])->where('definition', $uuid)
        ->middleware('throttle:30,1,production-free-grants-review')->block(20, 5)->name('production-free-grants.review');
    Route::post('/production-free-grants/definitions/{definition}/accept', [ProductionFreeController::class, 'accept'])->where('definition', $uuid)
        ->middleware('throttle:20,1,production-free-grants-writes')->block(20, 5)->name('production-free-grants.accept');
    Route::post('/production-free-grants/origins/{origin}/authorizations', [ProductionFreeController::class, 'authorize'])->where('origin', $uuid)
        ->middleware('throttle:20,1,production-free-grants-writes')->block(20, 5)->name('production-free-grants.authorize');
    Route::post('/production-free-grants/authorizations/{authorization}/redeem', [ProductionFreeController::class, 'redeem'])->where('authorization', $uuid)
        ->middleware('throttle:20,1,production-free-grants-redeem')->block(20, 5)->name('production-free-grants.redeem');
});
