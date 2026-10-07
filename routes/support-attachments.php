<?php

use App\Http\Controllers\SupportAttachmentController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

// Root registers under web and prepends SupportAttachmentPrivacy before CSRF/exception rendering.
Route::withoutMiddleware(HandleInertiaRequests::class)->group(function (): void {
    foreach ([['inquiries', 'inquiry', 'visitor'], ['projects', 'project', 'customer'], ['operator/inquiries', 'inquiry', 'operator'], ['operator/projects', 'project', 'operator']] as [$segment, $kind, $audience]) {
        $base = '/private-support/'.$segment.'/{source}/attachments';
        Route::get($base, SupportAttachmentController::class)->defaults('support_kind', $kind)->defaults('support_audience', $audience)->defaults('support_action', 'list')->middleware('throttle:60,1,private-support-read')->block(20, 5);
        Route::post($base.'/upload', SupportAttachmentController::class)->defaults('support_kind', $kind)->defaults('support_audience', $audience)->defaults('support_action', 'upload')->middleware('throttle:5,1,private-support-upload')->block(20, 5);
        foreach (['process', 'download', 'delete'] as $action) {
            Route::post($base.'/{attachment}/'.$action, SupportAttachmentController::class)->defaults('support_kind', $kind)->defaults('support_audience', $audience)->defaults('support_action', $action)->middleware('throttle:10,1,private-support-action')->block(20, 5);
        }
    }
});
