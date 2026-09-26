<?php

namespace App\Domain\Commerce\Payments;

use App\Domain\Commerce\Models\StripeWebhookReceipt;
use Illuminate\Support\Facades\Crypt;
use stdClass;
use Throwable;

/** Authenticate retained evidence, without applying today's signature window to an old receipt. */
final class ReadStripeReceipt
{
    public function read(StripeWebhookReceipt $receipt): array
    {
        try {
            $body = Crypt::decryptString($receipt->payload_ciphertext);
            if (strlen($body) > VerifyStripeWebhook::MAX_BODY_BYTES
                || ! hash_equals($receipt->payload_sha256, hash('sha256', $body))
                || $receipt->fingerprint_version !== StripeEventFingerprint::VERSION) {
                throw new \UnexpectedValueException;
            }
            $event = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            if (! $event instanceof stdClass || ($event->object ?? null) !== 'event'
                || ! hash_equals($receipt->event_fingerprint, StripeEventFingerprint::hash($event))
                || ($event->id ?? null) !== $receipt->event_id || ($event->type ?? null) !== $receipt->event_type
                || ($event->livemode ?? null) !== false || $receipt->livemode !== false
                || ! property_exists($event, 'api_version') || $event->api_version !== $receipt->api_version
                || ! is_int($event->created ?? null) || $event->created !== $receipt->provider_created_at
                || ($event->account ?? null) !== null || ($event->context ?? null) !== null
                || ! ($event->data ?? null) instanceof stdClass || ! ($event->data->object ?? null) instanceof stdClass) {
                throw new \UnexpectedValueException;
            }
            $object = $event->data->object;
            if (($object->id ?? null) !== $receipt->object_id || ($object->object ?? null) !== $receipt->object_type
                || (property_exists($object, 'livemode') && $object->livemode !== false)
                || ($object->account ?? null) !== null || ($object->context ?? null) !== null) {
                throw new \UnexpectedValueException;
            }

            // Event payloads provide locators only. No payload amount or payment status is trusted.
            return ['event_type' => $receipt->event_type, 'object_type' => $receipt->object_type,
                'session_id' => $receipt->object_id];
        } catch (Throwable) {
            throw new PaymentVerificationException('receipt_changed');
        }
    }
}
