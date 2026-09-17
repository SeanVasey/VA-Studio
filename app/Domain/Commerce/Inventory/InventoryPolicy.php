<?php

namespace App\Domain\Commerce\Inventory;

use App\Domain\Commerce\QuoteException;
use JsonException;

final class InventoryPolicy
{
    public static function requireTestEnvironment(): void
    {
        if (! app()->environment('local', 'testing')) { throw new QuoteException('INVENTORY_UNAVAILABLE', 503); }
    }

    public function current(): array
    {
        self::requireTestEnvironment();
        $raw = config('commerce.test_inventory_policy');
        try {
            $policy = is_string($raw) && strlen($raw) <= 2048 ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) { $policy = null; }
        if (! is_array($policy) || count($policy) !== 4 ||
            ($policy['schema_version'] ?? null) !== 1 || ($policy['purpose'] ?? null) !== 'test_inventory' ||
            ($policy['pending'] ?? null) !== 'retain_until_verified_resolution' ||
            ! is_int($policy['ttl_seconds'] ?? null) || $policy['ttl_seconds'] < 30 || $policy['ttl_seconds'] > 3600) {
            throw new QuoteException('INVENTORY_POLICY_UNAVAILABLE', 503);
        }

        return $policy;
    }
}
