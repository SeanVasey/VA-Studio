<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;

/** Identifier-only projection using the actual retained Tax source and consumer closure. */
final class PaidTaxReads
{
    public function show(string $batch, ProductionCustomerPrincipal $principal, User $actor, ?PaidTaxProjectionRead $read = null): array
    {
        return (new PaidTaxCommands)->run($batch, $principal, $actor,
            fn (array $graph): array => (new PaidTaxGrants)->project($graph), projectionRead: $read);
    }
}
