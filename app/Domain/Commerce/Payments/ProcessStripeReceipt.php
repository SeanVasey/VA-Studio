<?php

namespace App\Domain\Commerce\Payments;

use App\Domain\Commerce\Models\StripeReceiptWork;
use App\Domain\Commerce\Models\StripeWebhookReceipt;
use App\Domain\Commerce\QuoteException;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

final class ProcessStripeReceipt
{
    public const EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed', 'checkout.session.expired'];

    public function handle(int $receiptId, bool $replay = false): string
    {
        $claim = null;
        try {
            $claim = app(PaymentWork::class)->claim($receiptId, $replay);
            if (! $claim) {
                $work = StripeReceiptWork::where('stripe_webhook_receipt_id', $receiptId)->first();

                return $work?->state === 'processing' ? 'busy' : ($work?->outcome ?? 'busy');
            }
            $receipt = StripeWebhookReceipt::findOrFail($receiptId);
            $locator = app(ReadStripeReceipt::class)->read($receipt);
            if (! in_array($locator['event_type'], self::EVENTS, true)) {
                return app(PaymentWork::class)->outcome($claim, 'unsupported', 'unsupported');
            }
            if ($locator['object_type'] !== 'checkout.session' || ! is_string($locator['session_id'])) {
                throw new PaymentVerificationException('receipt_changed');
            }
            $inspection = app(VerifyTestPayment::class)->inspect($locator['session_id']);

            return app(VerifyTestPayment::class)->commit($inspection, $claim);
        } catch (Throwable $error) {
            $outcome = $error instanceof PaymentVerificationException ? $error->reason :
                ($error instanceof QuoteException || $error instanceof UniqueConstraintViolationException ? 'payment_changed' : 'retry');
            if (! $claim) { return $outcome; }
            $state = in_array($outcome, ['receipt_changed', 'payment_changed'], true) ? 'quarantined' : 'retry';
            // Error bookkeeping is fenced too: an old failure cannot overwrite a new success.
            try { return app(PaymentWork::class)->outcome($claim, $state, $outcome); }
            catch (Throwable) { return 'retry'; } // Leave durable lease for recovery; no SQL/SDK exception escapes.
        }
    }
}
