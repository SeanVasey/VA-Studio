<?php

use App\Http\Controllers\CollectionsController;
use Illuminate\Support\Facades\Route;

// Always registered; every action answers 404 until CollectionsPolicy::enabled() (config collections.enabled === true).
Route::get('/collections', [CollectionsController::class, 'index'])->middleware('throttle:120,1')->name('collections.index');
Route::get('/collections/{slug}', [CollectionsController::class, 'show'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->middleware('throttle:120,1')->name('collections.show');
