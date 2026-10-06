<?php

use App\Http\Controllers\InquiryConversationController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::withoutMiddleware(HandleInertiaRequests::class)->group(function (): void {
    Route::get('/contact/inquiries/{receipt}/conversation', InquiryConversationController::class)
        ->middleware('throttle:60,1,inquiry-conversation-read')->block(20, 5)->name('inquiries.conversation');
    Route::post('/contact/inquiries/{receipt}/conversation', InquiryConversationController::class)
        ->middleware('throttle:10,1,inquiry-conversation-write')->block(20, 5)->name('inquiries.conversation.reply');
});
