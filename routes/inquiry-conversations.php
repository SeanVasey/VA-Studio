<?php

use App\Http\Controllers\InquiryConversationController;
use App\Http\Controllers\InquiryHistoryController;
use App\Http\Controllers\OrderInquiryController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::withoutMiddleware(HandleInertiaRequests::class)->group(function (): void {
    Route::get('/contact/inquiries/for-order/{order}', [OrderInquiryController::class, 'setup'])
        ->middleware('throttle:60,1,inquiry-conversation-read')->block(20, 5)->name('inquiries.order.setup');
    Route::post('/contact/inquiries/for-order/{order}', [OrderInquiryController::class, 'store'])
        ->middleware('throttle:customer-inquiries')->block(20, 5)->name('inquiries.order.store');
    Route::get('/contact/inquiries/{receipt}/order-context', [OrderInquiryController::class, 'context'])
        ->middleware('throttle:60,1,inquiry-conversation-read')->block(20, 5)->name('inquiries.order.context');
    Route::get('/contact/inquiries/history', InquiryHistoryController::class)
        ->middleware('throttle:60,1,inquiry-conversation-read')->block(20, 5)->name('inquiries.history');
    Route::get('/contact/inquiries/history/before/{before}', InquiryHistoryController::class)
        ->middleware('throttle:60,1,inquiry-conversation-read')->block(20, 5)->name('inquiries.history.before');
    Route::get('/contact/inquiries/{receipt}/conversation', InquiryConversationController::class)
        ->middleware('throttle:60,1,inquiry-conversation-read')->block(20, 5)->name('inquiries.conversation');
    Route::post('/contact/inquiries/{receipt}/conversation', InquiryConversationController::class)
        ->middleware('throttle:10,1,inquiry-conversation-write')->block(20, 5)->name('inquiries.conversation.reply');
});
