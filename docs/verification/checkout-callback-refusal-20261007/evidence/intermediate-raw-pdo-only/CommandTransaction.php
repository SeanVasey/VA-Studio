<?php

namespace App\Domain\Commerce\ProductionCheckout;

use Closure;
use Illuminate\Support\Facades\DB;

final class CommandTransaction
{
    public static function run(Closure $command): mixed
    {
        $connection = DB::connection();
        CheckoutException::require($connection->transactionLevel() === 0 && in_array($connection->getDriverName(), ['sqlite', 'mysql'], true));
        $primary = $connection->getPdo();
        CheckoutException::require(! $primary->inTransaction(), 'outer_transaction');
        $driver = $connection->getDriverName();

        $result = $connection->transaction(function () use ($command, $connection, $primary, $driver): mixed {
            $rows = new Records($primary, $driver);
            $result = $command($rows);
            $rows->provePrimary();
            CheckoutException::require($connection->getRawPdo() === $primary && $primary->inTransaction());

            return $result;
        });
        // Framework commit listeners run after the closure's terminal proof.
        // A later alias/writer change suppresses the result; committed original evidence is retained.
        CheckoutException::require(DB::connection() === $connection && $connection->getRawPdo() === $primary
            && $connection->transactionLevel() === 0 && ! $primary->inTransaction(), 'primary_changed');
        PrimaryBoundary::prove($primary, $driver);

        return $result;
    }
}
