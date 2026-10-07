<?php

namespace App\Domain\Grants\Free\Production;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\FreeGrantHttpIdentity;
use App\Models\User;
use Illuminate\Http\Request;

/** Actual T23 marker plus trusted customer guard actor; never cached-user remint or HTTP binding input. */
final class ProductionFreeGrantHttpIdentity implements FreeGrantHttpIdentity
{
    public function forRequest(Request $request): array
    {
        $configuration = (new ProductionFreeGrantIdentityPolicy)->current();
        try {
            $principal = (new ProductionCustomerSessions)->principal($request);
        } catch (IdentityException) {
            throw new FreeGrantException(403);
        }
        $actor = $request->user('customer');
        FreeGrantException::require($actor instanceof User && $actor->id === $principal->userId
            && $principal->provenance === $configuration['provenance']
            && (new ProductionFreeGrantIdentityPolicy)->current() === $configuration, 403);

        return [$principal, $actor];
    }
}
