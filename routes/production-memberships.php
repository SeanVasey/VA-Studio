<?php

use App\Http\Membership\MembershipController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

// Member HTTP (C4e). Required from routes/web.php only when MembershipHttpMount admits the literal
// `production-memberships.http_mounted`; the shipped value is false. MembershipPrivacy (prepended in bootstrap/app.php)
// repeats that check for every request, so a stale cached route table cannot reopen it. JSON only, no page and no
// Inertia props. The index is the expensive call (up to 101 audited periods, read twice for pre-bytes), so reads and
// writes have small budgets and their own limiter prefixes. The activation family is deliberately not mounted.
Route::withoutMiddleware(HandleInertiaRequests::class)->group(function (): void {
    $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    Route::get('/production-memberships/periods', [MembershipController::class, 'index'])
        ->middleware('throttle:10,1,production-memberships-reads')->block(20, 5)->name('production-memberships.index');
    Route::post('/production-memberships/periods/{period}/reservations', [MembershipController::class, 'reserve'])->where('period', $uuid)
        ->middleware('throttle:10,1,production-memberships-writes')->block(20, 5)->name('production-memberships.reserve');
    Route::post('/production-memberships/periods/{period}/reservations/{redemption}/release', [MembershipController::class, 'release'])
        ->where(['period' => $uuid, 'redemption' => $uuid])
        ->middleware('throttle:10,1,production-memberships-writes')->block(20, 5)->name('production-memberships.release');
    Route::post('/production-memberships/periods/{period}/expire', [MembershipController::class, 'expire'])->where('period', $uuid)
        ->middleware('throttle:10,1,production-memberships-writes')->block(20, 5)->name('production-memberships.expire');
});
