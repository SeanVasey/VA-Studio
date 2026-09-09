<?php

use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Stateless provider route. Browser routes retain their web/session/CSRF middleware.
Route::post('/webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');
