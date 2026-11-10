<?php

namespace App\Http\Controllers;

use App\Domain\Merch\Fulfillment\MerchFulfillmentException;
use App\Domain\Merch\Fulfillment\ReceivePrintfulWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PrintfulWebhookController
{
    public function __invoke(Request $request, ReceivePrintfulWebhook $receiver): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        try {
            $outcome = $receiver->handle((string) $request->attributes->get('_printful_raw_body', ''), $request->header('x-pf-webhook-signature'), $request->header('x-pf-webhook-public-key'));
        } catch (MerchFulfillmentException $exception) {
            // Disabled looks like an absent route, so an off deployment reveals nothing.
            return response()->json(['code' => $exception->errorCode], $exception->status, $headers);
        }

        return response()->json(['received' => true, 'outcome' => $outcome], 200, $headers);
    }
}
