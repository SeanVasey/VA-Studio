<?php

use App\Http\Controllers\SupportAttachmentPageController;
use Illuminate\Support\Facades\Route;

foreach ([['inquiries', 'inquiry', 'visitor'], ['projects', 'project', 'customer'], ['operator/inquiries', 'inquiry', 'operator'], ['operator/projects', 'project', 'operator']] as [$segment, $kind, $audience]) {
    Route::get('/private-support/'.$segment.'/{source}/attachments/view', SupportAttachmentPageController::class)
        ->defaults('support_kind', $kind)->defaults('support_audience', $audience)
        ->middleware('throttle:60,1,private-support-page')->block(20, 5);
}
