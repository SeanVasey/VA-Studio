<?php

namespace App\Http\Controllers;

use App\Domain\Inquiries\InquiryConversation;
use App\Domain\Inquiries\InquiryException;
use App\Http\Requests\InquiryMessageRequest;
use App\Http\Responses\InquiryResponse;
use App\Support\InquiryOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class InquiryConversationController
{
    public function __invoke(Request $request, string $receipt, InquiryOwner $owner, InquiryConversation $conversation): Response
    {
        try {
            $ownerHash = $owner->forRequest($request);
            if ($request->isMethod('GET')) {
                return InquiryResponse::protect(response()->json($conversation->owner($receipt, $ownerHash)));
            }
            $result = $conversation->followUp($receipt, $ownerHash, InquiryMessageRequest::body($request));

            return InquiryResponse::protect(response()->json(['state' => 'saved', 'messageId' => $result['messageId']], $result['replayed'] ? 200 : 201));
        } catch (InquiryException $error) {
            return InquiryResponse::error($error->status, $error->errors);
        } catch (Throwable $error) {
            try {
                Log::error('Inquiry conversation failed.', ['exception_class' => $error::class]);
            } catch (Throwable) { /* Reporting must not expose a private message. */
            }

            return InquiryResponse::error(503);
        }
    }
}
