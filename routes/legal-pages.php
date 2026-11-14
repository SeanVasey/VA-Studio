<?php

use App\Http\Controllers\LegalPageController;
use Illuminate\Support\Facades\Route;

// Lane CX (FP-005): legal pages from the active published site release only. A release without legal text, an empty slot
// or an unpublished draft answers 404; staff review drafts through the private site release preview.
Route::get('/legal/{document}', LegalPageController::class)
    ->where('document', 'privacy|terms|licenses')
    ->middleware('throttle:120,1')->name('legal.show');
