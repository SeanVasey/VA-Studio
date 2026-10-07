<?php

namespace App\Domain\Commerce\ProductionCheckout;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/** Inspect the already resolved default connection; terminal proof never invokes a connector. */
final class ResolvedConnection
{
    public static function current(string $reason = 'primary_changed'): Connection
    {
        $connection = DB::getConnections()[DB::getDefaultConnection()] ?? null;
        CheckoutException::require($connection instanceof Connection, $reason);

        return $connection;
    }
}
