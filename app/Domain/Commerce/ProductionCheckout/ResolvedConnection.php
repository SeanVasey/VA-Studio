<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityMetadataAdmission;
use Illuminate\Database\Connection;

/** Inspect the already resolved default connection; terminal proof never invokes a connector. */
final class ResolvedConnection
{
    public static function current(string $reason = 'primary_changed'): Connection
    {
        try {
            $connection = IdentityMetadataAdmission::resolvedConnectionMetadata();
        } catch (IdentityException) {
            throw new CheckoutException($reason);
        }
        CheckoutException::require($connection instanceof Connection, $reason);

        return $connection;
    }
}
