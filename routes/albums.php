<?php

use App\Http\Controllers\AlbumsController;
use Illuminate\Support\Facades\Route;

// Always registered; every action answers 404 until AlbumsPolicy::enabled() (config albums.enabled === true).
Route::get('/albums', [AlbumsController::class, 'index'])->middleware('throttle:120,1')->name('albums.index');
Route::get('/albums/{slug}', [AlbumsController::class, 'show'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->middleware('throttle:120,1')->name('albums.show');
