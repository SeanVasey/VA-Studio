<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\Payments\ReceiveStripeWebhook;
use App\Domain\Commerce\Payments\StripeWebhookException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StripeWebhookController
{
    public function __invoke(Request $request, ReceiveStripeWebhook $receiver): JsonResponse
    {
        try {
            $receipt = $receiver->handle($request->attributes->get('_stripe_raw_body', ''), $request->header('Stripe-Signature', ''));
            app(\App\Domain\Commerce\Payments\DispatchStripeReceipt::class)->handle($receipt->id);
        } catch (StripeWebhookException $exception) {
            return response()->json(['code' => $exception->errorCode], $exception->status, $this->headers());
        }

        return response()->json(['received' => true], 200, $this->headers());
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
    }
}
