<?php

namespace App\Http\Controllers;

use App\Domain\Inquiries\InquiryException;
use App\Domain\Inquiries\SubmitInquiry;
use App\Http\Requests\InquiryRequest;
use App\Http\Responses\InquiryResponse;
use App\Support\InquiryOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class InquiryController
{
    public function __invoke(Request $request, InquiryOwner $owner, SubmitInquiry $submit): Response
    {
        try {
            $saved = $submit->handle(InquiryRequest::body($request), $owner->forRequest($request));

            return InquiryResponse::protect(response()->json(['state' => 'saved', 'receipt' => $saved['receipt']], $saved['replayed'] ? 200 : 201));
        } catch (InquiryException $error) {
            return InquiryResponse::error($error->status, $error->errors);
        } catch (Throwable $error) {
            try {
                Log::error('Inquiry request failed.', ['exception_class' => $error::class]);
            } catch (Throwable) { /* Reporting failure must preserve the generic private response. */
            }

            return InquiryResponse::error(503);
        }
    }
}
