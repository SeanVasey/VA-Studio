<?php

namespace App\Domain\Grants\Free\Production;

use App\Domain\Grants\Free\FreeGrantHttpIdentity;
use Illuminate\Http\Request;

/** Actual T23 marker plus trusted customer guard actor; never cached-user remint or HTTP binding input. */
final class ProductionFreeGrantHttpIdentity implements FreeGrantHttpIdentity
{
    public function forRequest(Request $request): array
    {
        $binding = $this->binding($request);

        return [$binding->principal(), $binding->actor()];
    }

    /** Production-family consumers retain this same capsule through their terminal HTTP proof. */
    public function binding(Request $request): ProductionFreeGrantHttpBinding
    {
        return ProductionFreeGrantHttpBinding::forRequest($request);
    }
}
