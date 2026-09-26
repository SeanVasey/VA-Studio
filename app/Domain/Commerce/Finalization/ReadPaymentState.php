<?php

namespace App\Domain\Commerce\Finalization;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Payments\PaymentEvidence;

final class ReadPaymentState
{
    public function verify(VerifiedPayment $payment, Order $order, array $original): CheckoutIntent
    {
        $intent = CheckoutIntent::findOrFail($payment->checkout_intent_id);
        $session = CheckoutSession::findOrFail($payment->checkout_session_id);
        $request = app(CheckoutEvidence::class)->verifyIntent($intent, $order, $original);
        if ($payment->order_id !== $order->id || $payment->order_attempt_id !== $intent->order_attempt_id
            || $payment->confirmed_at->lessThan($intent->created_at)) { throw new FinalizationException('changed'); }
        app(PaymentEvidence::class)->verifyConfirmation($payment, $request, $session);

        return $intent;
    }

    /** Caller supplies an already-authorized, fully verified original order. No configuration or provider I/O. */
    public function projection(Order $order, array $original): array
    {
        $payment = VerifiedPayment::where('order_id', $order->id)->first();
        if (! $payment) {
            return ['status' => 'prepared',
                'paymentStatus' => CheckoutIntent::where('order_id', $order->id)->exists() ? 'not_verified' : 'not_started',
                'finalizationStatus' => 'not_started', 'fulfillmentStatus' => 'not_started'];
        }
        $this->verify($payment, $order, $original);
        $outcome = OrderFinalization::where('order_id', $order->id)->value('outcome');

        return ['status' => $outcome ?? 'prepared', 'paymentStatus' => 'verified',
            'finalizationStatus' => $outcome ?? 'awaiting_finalization',
            'fulfillmentStatus' => match ($outcome) { 'paid' => 'pending_contracts', 'paid_exception' => 'blocked', default => 'not_started' }];
    }
}
