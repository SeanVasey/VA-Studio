<?php

use App\Http\Controllers\ServiceProjectController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ServiceProjectPrivacy;
use Illuminate\Support\Facades\Route;

Route::middleware([ServiceProjectPrivacy::class, 'throttle:60,1,service-project-pages'])->group(function (): void {
    Route::get('/services/projects', [ServiceProjectController::class, 'page'])->name('service-projects.page');
    Route::withoutMiddleware(HandleInertiaRequests::class)->group(function (): void {
        Route::get('/services/projects/data', [ServiceProjectController::class, 'index'])->name('service-projects.index');
        Route::get('/services/projects/{project}', [ServiceProjectController::class, 'show'])->name('service-projects.show');
        Route::post('/services/projects', [ServiceProjectController::class, 'store'])->middleware('throttle:10,1,service-project-writes')->block(20, 5)->name('service-projects.store');
        Route::post('/services/projects/{project}/commands', [ServiceProjectController::class, 'command'])->middleware('throttle:10,1,service-project-writes')->block(20, 5)->name('service-projects.command');
    });
});
