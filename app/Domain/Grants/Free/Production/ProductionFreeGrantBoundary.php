<?php

namespace App\Domain\Grants\Free\Production;

use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\FreeGrantRows;
use Illuminate\Support\Facades\DB;
use PDO;

/** Pure admission before final policy/current proofs; never executes an unresolved PDO resolver. */
final class ProductionFreeGrantBoundary
{
    public static function admit(FreeGrantRows $rows): void
    {
        $primary = $rows->identity();
        $connection = DB::connection();
        FreeGrantException::require($connection->getRawPdo() === $primary && $connection->transactionLevel() === 1
            && $primary->inTransaction(), 403);
        foreach (DB::getConnections() as $other) {
            $pdo = $other->getRawPdo();
            FreeGrantException::require($pdo instanceof PDO
                && ($other === $connection || $other->transactionLevel() === 0 && ! $pdo->inTransaction()), 403);
        }
        $rows->assertCurrent();
    }
}
