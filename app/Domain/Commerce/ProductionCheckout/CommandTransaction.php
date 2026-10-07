<?php

namespace App\Domain\Commerce\ProductionCheckout;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
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
            // A commit-time refusal lowers the framework depth without notifying the
            // transactions manager, so the refused frame's pending record (and any
            // after-commit work registered inside it) would run on the next unrelated
            // commit. This command owned the only frame on the connection, so clearing
            // the connection's records at depth zero discards nothing of a caller's.
            if ($frame !== null && $connection->transactionLevel() === 0) {
                $manager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
                if ($manager instanceof DatabaseTransactionsManager) {
                    $manager->rollback($connection->getName(), 0);
                }
            }
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
