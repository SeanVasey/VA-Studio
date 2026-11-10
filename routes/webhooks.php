<?php

use App\Http\Controllers\PrintfulWebhookController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Stateless provider route. Browser routes retain their web/session/CSRF middleware.
Route::post('/webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');

// Printful (BL-63): default-off; refuses with 404 unless the merch fulfillment policy admits it. Signed events only.
Route::post('/webhooks/printful', PrintfulWebhookController::class)->middleware('throttle:printful-webhook')->name('webhooks.printful');
