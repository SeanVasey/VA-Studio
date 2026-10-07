<?php

namespace App\Domain\Grants\Free\Production;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\FreeGrantRows;

/** Authenticate the original T23 observation prefix without minting current access or redirecting ownership. */
final class ProductionFreeGrantLineage
{
    public function buyer(ProductionCustomerPrincipal $principal, array $binding): array
    {
        $keys = array_keys($binding);
        sort($keys);
        FreeGrantException::require($keys === ['account_id', 'buyer_binding', 'family', 'family_schema_version', 'identity_version',
            'legal_identity_verified', 'provenance', 'purpose', 'user_id'] && $binding['family_schema_version'] === 1
            && $binding['family'] === ProductionFreeGrantIdentityPolicy::FAMILY
            && $binding['identity_version'] === ProductionFreeGrantIdentityPolicy::VERSION
            && $binding['purpose'] === ProductionFreeGrantIdentityPolicy::PURPOSE && $binding['legal_identity_verified'] === false
            && is_array($binding['buyer_binding']), 403);
        $current = $principal->durableBinding();
        FreeGrantException::require($binding['account_id'] === $principal->accountId && $binding['user_id'] === $principal->userId
            && $binding['provenance'] === $principal->provenance, 403);
        foreach (['origin_id', 'provenance', 'account_id', 'account_public_id', 'user_id'] as $key) {
            FreeGrantException::require(($binding['buyer_binding'][$key] ?? null) === $current[$key], 403);
        }

        return $binding['buyer_binding'];
    }

    public function lock(ProductionCustomerPrincipal $principal, array $binding, FreeGrantRows $rows): array
    {
        return (new ProductionCustomerAccess)->verifyHistoricalBinding($this->buyer($principal, $binding), $rows->current());
    }

    public function prove(ProductionCustomerPrincipal $principal, array $binding, FreeGrantRows $rows, array $expected): void
    {
        (new ProductionCustomerAccess)->proveHistoricalBindingCurrent($this->buyer($principal, $binding), $rows->current(), $expected);
    }
}
