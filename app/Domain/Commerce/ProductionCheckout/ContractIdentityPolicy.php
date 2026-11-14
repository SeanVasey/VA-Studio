<?php

namespace App\Domain\Commerce\ProductionCheckout;

/**
 * D-30 contract identity capture for the V1 checkout, separate from the tax checkout's own gate. The shipped value is the
 * literal `false` in config/production_checkout.php (no environment read); only an exact `true` in local or testing admits.
 */
final class ContractIdentityPolicy
{
    public static function contractIdentityV2(): bool
    {
        return app()->environment('local', 'testing') && config('production_checkout.contract_identity_v2') === true;
    }
}
