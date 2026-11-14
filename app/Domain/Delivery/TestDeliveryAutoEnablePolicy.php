<?php

namespace App\Domain\Delivery;

use App\Support\CanonicalJson;
use App\Support\Environment\TestEnvironment;
use RuntimeException;
use Throwable;

/**
 * Optional automatic enabling of Stripe-test delivery (D-19 amendment, 2026-11-12, under Sean's delegation). Off by default. Admitted
 * only where {@see TestEnvironment::admitsTestCommerce()} admits test commerce (local, testing and the hosted staging rehearsal, never
 * production), in Stripe test mode with an `acct_` account, when `delivery.test_auto_enable_enabled` is exactly true and
 * `delivery.test_auto_enable_policy` is exactly {@see self::CONTRACT}. It changes nothing by itself: `vasey:auto-enable-test-delivery`
 * reads it and enables, through the same domain operations and audit events staff use, only orders that never had a delivery control
 * (so a block or enable a person recorded is final), never while a refund/dispute hold is active, and as the system (no actor).
 * Issuance and redemption keep every gate they have (owner, activation evidence, holds, fresh file verification).
 */
final class TestDeliveryAutoEnablePolicy
{
    public const VERSION = 'test-delivery-auto-enable-v1';

    public const CONTRACT = ['schema_version' => 1, 'purpose' => 'test_delivery_auto_enable', 'version' => 'test-delivery-auto-enable-v1',
        'provider' => 'stripe', 'mode' => 'test', 'scope' => 'ready_orders_never_controlled', 'lanes' => 'track_and_sound_kit',
        'holds' => 'skip_while_refund_dispute_held', 'staff_decisions' => 'final', 'actor' => 'system'];

    /** The reference whose SHA-256 the control audit events carry for an automatic change (the reference itself is never stored). */
    public const REFERENCE = 'auto.'.self::VERSION;

    public function account(): string
    {
        $account = config('payments.stripe.account_id');
        if (! TestEnvironment::admitsTestCommerce() || config('payments.stripe.mode') !== 'test'
            || ! is_string($account) || preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $account) !== 1) {
            throw new RuntimeException('Automatic test delivery enabling unavailable.');
        }

        return $account;
    }

    public function current(): array
    {
        $this->account();
        try {
            $raw = config('delivery.test_auto_enable_policy');
            if (config('delivery.test_auto_enable_enabled') !== true || ! is_string($raw) || strlen($raw) > 4096) {
                throw new RuntimeException;
            }

            return self::validate(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            throw new RuntimeException('Automatic test delivery enabling unavailable.');
        }
    }

    public function admitted(): bool
    {
        try {
            $this->current();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function validate(mixed $policy): array
    {
        if (! is_array($policy) || CanonicalJson::encode($policy) !== CanonicalJson::encode(self::CONTRACT)) {
            throw new RuntimeException('Automatic test delivery enabling policy changed.');
        }

        return $policy;
    }
}
