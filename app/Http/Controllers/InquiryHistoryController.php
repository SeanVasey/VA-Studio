<?php

namespace App\Http\Controllers;

use App\Domain\Inquiries\InquiryException;
use App\Domain\Inquiries\ReadOwnedInquiries;
use App\Http\Responses\InquiryResponse;
use App\Support\InquiryOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class InquiryHistoryController
{
    public function __invoke(Request $request, InquiryOwner $owner, ReadOwnedInquiries $history, ?string $before = null): Response
    {
        try {
            return InquiryResponse::protect(response()->json(['history' => $history->handle($owner->forRequest($request), $before)]));
        } catch (InquiryException $error) {
            return InquiryResponse::error($error->status, $error->errors);
        } catch (Throwable $error) {
            try {
                Log::error('Inquiry history failed.', ['exception_class' => $error::class]);
            } catch (Throwable) { /* A failed logger must not expose retained private values. */ }

            return InquiryResponse::error(503);
        }
    }
}
