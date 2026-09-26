<?php

namespace App\Domain\Delivery;

use App\Support\CanonicalJson;
use Throwable;

/** Technical limits for internal synthetic delivery tests; never a license usage budget. */
final class TestAccessPolicy
{
    public const CONTRACT = [
        'schema_version' => 1, 'purpose' => 'test_owner_delivery', 'version' => 'test-owner-delivery-v1',
        'scope' => 'activated_order_owner', 'storage' => 'private_local', 'verification' => 'fresh_sha256',
        'token_bytes' => 32, 'authorization_ttl_seconds' => 60, 'new_authorizations_per_order60_seconds' => 3,
        'stream_attempts' => 1, 'ranges' => 'disabled', 'pending_entitlements' => 'preserve',
        'buyer_identity' => 'unverified_guest',
    ];

    /** Blocking remains available after access policy withdrawal, within the same test environment/account. */
    public function environmentAccount(): string
    {
        $account = config('payments.stripe.account_id');
        if (! app()->environment('local', 'testing') || config('payments.stripe.mode') !== 'test'
            || ! is_string($account) || preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $account) !== 1) {
            throw new DeliveryException('unavailable');
        }
        return $account;
    }

    public function account(): string
    {
        if (config('delivery.test_access_enabled') !== true) { throw new DeliveryException('unavailable'); }
        return $this->environmentAccount();
    }

    public function current(): array
    {
        $this->account(); $raw = config('delivery.test_access_policy');
        if (! is_string($raw) || strlen($raw) > 4096) { throw new DeliveryException('unavailable'); }
        try { return self::validate(json_decode($raw, true, 8, JSON_THROW_ON_ERROR)); }
        catch (Throwable) { throw new DeliveryException('unavailable'); }
    }

    public static function validate(array $policy): array
    {
        if (CanonicalJson::encode($policy) !== CanonicalJson::encode(self::CONTRACT)) { throw new DeliveryException('changed'); }
        return $policy;
    }
}
