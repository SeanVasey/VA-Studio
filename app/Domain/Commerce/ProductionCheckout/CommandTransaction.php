<?php

namespace App\Domain\Commerce\ProductionCheckout;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CommandTransaction
{
    public static function run(Closure $command): mixed
    {
        $connection = DB::connection();
        CheckoutException::require($connection->transactionLevel() === 0 && in_array($connection->getDriverName(), ['sqlite', 'mysql'], true));
        $primary = $connection->getPdo();
        CheckoutException::require(! $primary->inTransaction(), 'outer_transaction');
        $driver = $connection->getDriverName();

        $frame = null;
        try {
            $result = $connection->transaction(function () use ($command, $connection, $primary, $driver, &$frame): mixed {
                $frame = CheckoutCommandFrame::capture($connection, $primary, $driver);
                $rows = new Records($primary, $driver, null, $frame);
                $result = $command($rows);
                $rows->provePrimary();
                $frame->prove(1);

                return $result;
            });
        } catch (Throwable $error) {
            $frame?->abort();
            throw $error;
        } finally {
            $frame?->restore();
        }
        // Framework commit listeners run after the closure's terminal proof.
        // A later alias/writer change suppresses the result; committed original evidence is retained.
        CheckoutException::require(ResolvedConnection::current() === $connection && $connection->getRawPdo() === $primary
            && $connection->transactionLevel() === 0 && ! $primary->inTransaction(), 'primary_changed');
        PrimaryBoundary::prove($primary, $driver);

        return $result;
    }
}
