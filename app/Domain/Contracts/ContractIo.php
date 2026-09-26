<?php

namespace App\Domain\Contracts;

use Illuminate\Support\Facades\DB;

/** Rendering and filesystem work must never extend any open database transaction. */
final class ContractIo
{
    public static function outsideTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new ContractIssuanceException('unavailable');
            }
        }
    }
}
