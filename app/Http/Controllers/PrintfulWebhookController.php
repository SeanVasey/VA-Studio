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
        if ((int) $request->header('Content-Length', '0') > ReceivePrintfulWebhook::MAX_BODY_BYTES) {
            return response()->json(['code' => 'PRINTFUL_WEBHOOK_TOO_LARGE'], 413, $headers);
        }
        try {
            $outcome = $receiver->handle($request->getContent(), $request->header('x-pf-webhook-signature'), $request->header('x-pf-webhook-public-key'));
        } catch (MerchFulfillmentException $exception) {
            // Disabled looks like an absent route, so an off deployment reveals nothing.
            return response()->json(['code' => $exception->errorCode], $exception->status, $headers);
        }

        return response()->json(['received' => true, 'outcome' => $outcome], 200, $headers);
    }
}
