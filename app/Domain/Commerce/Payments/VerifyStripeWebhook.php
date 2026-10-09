<?php

namespace App\Domain\Commerce\Payments;

use App\Support\Environment\TestEnvironment;
use JsonException;
use SensitiveParameter;
use stdClass;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;

final class VerifyStripeWebhook
{
    public const MAX_BODY_BYTES = 1_048_576;

    public function handle(#[SensitiveParameter] string $body, #[SensitiveParameter] string $signature): VerifiedStripeEvent
    {
        $account = config('payments.stripe.account_id');
        $secret = config('payments.stripe.webhook_secret');
        if (config('payments.stripe.webhook_enabled') !== true
            || ! TestEnvironment::admitsTestCommerce()
            || config('payments.stripe.mode') !== 'test'
            || ! is_string($account) || ! preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/', $account)
            || ! is_string($secret) || ! preg_match('/\Awhsec_[A-Za-z0-9]{8,200}\z/', $secret)) {
            throw new StripeWebhookException('STRIPE_WEBHOOK_UNAVAILABLE', 503);
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new StripeWebhookException('STRIPE_WEBHOOK_TOO_LARGE', 413);
        }
        // Bound and validate the header before the SDK parses it; malformed tokens must not
        // produce PHP warnings containing a signature. Multiple v1 signatures support rotation.
        if ($signature === '' || strlen($signature) > 4096) {
            throw new StripeWebhookException('STRIPE_WEBHOOK_INVALID', 400);
        }
        $timestamps = [];
        $parts = array_map('trim', explode(',', $signature));
        foreach ($parts as $part) {
            if (! preg_match('/\A([a-zA-Z0-9]+)=([a-zA-Z0-9]+)\z/', $part, $match)) {
                throw new StripeWebhookException('STRIPE_WEBHOOK_INVALID', 400);
            }
            if ($match[1] === 't') {
                if (! preg_match('/\A[0-9]{1,12}\z/', $match[2])) {
                    throw new StripeWebhookException('STRIPE_WEBHOOK_INVALID', 400);
                }
                $timestamps[] = (int) $match[2];
            }
        }
        if (count($timestamps) !== 1) {
            throw new StripeWebhookException('STRIPE_WEBHOOK_INVALID', 400);
        }
        try {
            // Always verify exact bytes, including a non-zero replay tolerance. No API call.
            WebhookSignature::verifyHeader($body, implode(',', $parts), $secret, 300);
        } catch (SignatureVerificationException) {
            throw new StripeWebhookException('STRIPE_WEBHOOK_INVALID', 400);
        }
        try {
            $event = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new StripeWebhookException('STRIPE_WEBHOOK_INVALID', 400);
        }
        if (! $event instanceof stdClass
            || ($event->object ?? null) !== 'event'
            || ! $this->identifier($event->id ?? null, 'evt_')
            || ! is_string($event->type ?? null) || strlen($event->type) > 160
            || ! preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z/', $event->type)
            || ($event->livemode ?? null) !== false
            || ! is_int($event->created ?? null) || $event->created < 0
            || ! property_exists($event, 'api_version')
            || ($event->api_version !== null && (! is_string($event->api_version) || strlen($event->api_version) > 80 || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}(?:\.[a-z0-9]+)?\z/', $event->api_version)))
            || ! ($event->data ?? null) instanceof stdClass
            || ! ($event->data->object ?? null) instanceof stdClass) {
            throw new StripeWebhookException('STRIPE_WEBHOOK_INVALID', 400);
        }
        // Own-account v1 snapshot events are bound to the configured account by its
        // endpoint secret. Connect and organization/thin contexts require a different adapter.
        if (($event->account ?? null) !== null || ($event->context ?? null) !== null) {
            throw new StripeWebhookException('STRIPE_WEBHOOK_INVALID', 400);
        }
        $object = $event->data->object;
        if (! is_string($object->object ?? null) || strlen($object->object) > 128
            || ! preg_match('/\A[a-z][a-z0-9_.]*\z/', $object->object)
            || (property_exists($object, 'livemode') && $object->livemode !== false)
            || (property_exists($object, 'id') && ! $this->identifier($object->id))) {
            throw new StripeWebhookException('STRIPE_WEBHOOK_INVALID', 400);
        }

        try {
            $fingerprint = StripeEventFingerprint::hash($event);
        } catch (JsonException) {
            // For example, an out-of-range JSON exponent cannot be retained canonically.
            throw new StripeWebhookException('STRIPE_WEBHOOK_INVALID', 400);
        }

        return new VerifiedStripeEvent($account, $event->id, $event->type, $object->id ?? null,
            $object->object, $event->api_version, $event->created, $timestamps[0],
            $fingerprint, $body);
    }

    private function identifier(mixed $value, string $prefix = ''): bool
    {
        return is_string($value) && strlen($value) <= 128
            && str_starts_with($value, $prefix)
            && preg_match('/\A[a-z][a-z0-9]*_[A-Za-z0-9_]+\z/', $value) === 1;
    }
}
