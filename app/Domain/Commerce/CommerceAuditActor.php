<?php

namespace App\Domain\Commerce;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Audit identity only. Quote ownership and customer eligibility remain their existing contracts. */
final class CommerceAuditActor
{
    public function lock(?User $actor): ?int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Commerce audit attribution requires the command transaction.');
        }
        if ($actor === null) {
            return null;
        }
        $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
        if ($current === null) {
            throw new QuoteException('COMMERCE_UNAVAILABLE', 503);
        }

        return $current->id;
    }
}
