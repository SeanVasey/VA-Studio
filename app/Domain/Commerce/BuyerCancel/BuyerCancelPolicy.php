<?php

namespace App\Domain\Commerce\BuyerCancel;

use App\Support\CanonicalJson;
use App\Support\Environment\TestEnvironment;
use RuntimeException;
use Throwable;

/**
 * Admission for the buyer's cancellation of a never-sent test order: test commerce only (local, testing, staging), Stripe
 * test mode, VASEY_TEST_BUYER_CANCEL_ENABLED exactly true and VASEY_TEST_BUYER_CANCEL_POLICY equal to CONTRACT.
 * Production refuses in code whatever the flags say; ops/production/validate-runtime.php also holds the flag false.
 */
final class BuyerCancelPolicy
{
    public const CONTRACT = ['schema_version' => 1, 'purpose' => 'test_buyer_order_cancellation',
        'version' => 'test-buyer-cancel-v1', 'mode' => 'test', 'actor' => 'order_owner',
        'proof' => 'no_checkout_intent_buyer_owner_v1', 'resources' => 'pending_to_released', 'provider_calls' => 'none'];

    /** @throws RuntimeException when the capability is not admitted */
    public function current(): array
    {
        try {
            $raw = config('buyer-cancel.policy');
            if (! TestEnvironment::admitsTestCommerce() || config('payments.stripe.mode') !== 'test'
                || config('buyer-cancel.enabled') !== true || ! is_string($raw) || strlen($raw) > 4096) {
                throw new RuntimeException;
            }

            return self::validate(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            throw new RuntimeException('Test buyer cancellation unavailable.');
        }
    }

    public function admitted(): bool
    {
        try {
            $this->current();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function validate(mixed $policy): array
    {
        if (! is_array($policy) || CanonicalJson::encode($policy) !== CanonicalJson::encode(self::CONTRACT)) {
            throw new RuntimeException('Test buyer cancellation evidence changed.');
        }

        return $policy;
    }
}
