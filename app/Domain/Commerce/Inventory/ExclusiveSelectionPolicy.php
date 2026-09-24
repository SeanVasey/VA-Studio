<?php

namespace App\Domain\Commerce\Inventory;

use App\Domain\Commerce\QuoteException;
use InvalidArgumentException;
use Throwable;

/** An explicit development policy, never an approval of production exclusivity terms. */
final class ExclusiveSelectionPolicy
{
    public function current(): array
    {
        InventoryPolicy::requireTestEnvironment();
        try {
            $raw = config('commerce.test_exclusive_selection_policy');
            if (! is_string($raw) || strlen($raw) > 2048) { throw new InvalidArgumentException; }

            return $this->validate(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            throw new QuoteException('EXCLUSIVE_POLICY_UNAVAILABLE', 503);
        }
    }

    public function validate(mixed $policy): array
    {
        if (! is_array($policy) || count($policy) !== 5 ||
            ($policy['schema_version'] ?? null) !== 1 || ($policy['purpose'] ?? null) !== 'test_exclusive_selection' ||
            ($policy['non_exclusive_cutoff'] ?? null) !== 'block_while_reserved' ||
            ($policy['existing_pending'] ?? null) !== 'retain_until_verified_resolution' ||
            ($policy['discounts'] ?? null) !== 'explicit_revision_only') {
            throw new InvalidArgumentException('Unsupported exclusive selection policy.');
        }

        return $policy;
    }
}
