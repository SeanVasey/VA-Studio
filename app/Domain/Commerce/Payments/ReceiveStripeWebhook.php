<?php

namespace App\Domain\Commerce\Payments;

use App\Domain\Commerce\Models\StripeWebhookReceipt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use SensitiveParameter;

final class ReceiveStripeWebhook
{
    public function __construct(private readonly VerifyStripeWebhook $verifier) {}

    public function handle(#[SensitiveParameter] string $body, #[SensitiveParameter] string $signature): StripeWebhookReceipt
    {
        $event = $this->verifier->handle($body, $signature);
        try {
            // One atomic insert commits before the HTTP response. Do not publish jobs,
            // interpret payment status or generate fulfillment from receipt alone.
            return StripeWebhookReceipt::query()->create([
                'account_id' => $event->accountId,
                'livemode' => false,
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'object_id' => $event->objectId,
                'object_type' => $event->objectType,
                'api_version' => $event->apiVersion,
                'provider_created_at' => $event->created,
                'signature_timestamp' => $event->signatureTimestamp,
                'payload_sha256' => hash('sha256', $body),
                'event_fingerprint' => $event->fingerprint,
                'fingerprint_version' => StripeEventFingerprint::VERSION,
                'payload_ciphertext' => Crypt::encryptString($body),
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // The database serializes first writers. A locking read also sees the winner
            // when this command is used inside an existing MySQL repeatable-read transaction.
            $receipt = StripeWebhookReceipt::query()
                ->where('account_id', $event->accountId)->where('livemode', false)
                ->where('event_id', $event->eventId)->lockForUpdate()->first();
            if (! $receipt) {
                throw new StripeWebhookException('STRIPE_WEBHOOK_UNAVAILABLE', 503);
            }
            if ($receipt->fingerprint_version !== StripeEventFingerprint::VERSION
                || ! hash_equals($receipt->event_fingerprint, $event->fingerprint)) {
                throw new StripeWebhookException('STRIPE_EVENT_CONFLICT', 409);
            }

            return $receipt;
        }
    }
}
