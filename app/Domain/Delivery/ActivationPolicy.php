<?php

namespace App\Domain\Delivery;

use App\Support\CanonicalJson;
use App\Support\Environment\TestEnvironment;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ActivationPolicy
{
    public const CONTRACT = [
        'schema_version' => 1, 'purpose' => 'test_fulfillment_activation', 'version' => 'test-fulfillment-activation-v1',
        'scope' => 'complete_paid_order', 'storage' => 'private_local', 'verification' => 'fresh_sha256',
        'max_verification_age_seconds' => 300, 'pending_entitlements' => 'preserve',
        'buyer_identity' => 'unverified_guest', 'download_access' => 'disabled',
    ];

    public function account(): string
    {
        $account = config('payments.stripe.account_id');
        if (config('delivery.test_activation_enabled') !== true || ! TestEnvironment::admitsTestCommerce()
            || config('payments.stripe.mode') !== 'test' || ! is_string($account)
            || ! preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $account)) {
            throw new DeliveryException('unavailable');
        }
        return $account;
    }

    public function current(): array
    {
        $this->account(); $raw = config('delivery.test_activation_policy');
        if (! is_string($raw) || strlen($raw) > 4096) { throw new DeliveryException('unavailable'); }
        try { return self::validate(json_decode($raw, true, 8, JSON_THROW_ON_ERROR)); }
        catch (Throwable) { throw new DeliveryException('unavailable'); }
    }

    /** Historical evidence remains readable after configuration is withdrawn. */
    public static function validate(array $policy): array
    {
        if (CanonicalJson::encode($policy) !== CanonicalJson::encode(self::CONTRACT)) { throw new DeliveryException('changed'); }
        return $policy;
    }

    public static function outsideTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) { throw new DeliveryException('unavailable'); }
        }
    }
}
