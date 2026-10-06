<?php

namespace App\Domain\Commerce\ProductionPolicy;

use App\Domain\Commerce\ProductionPolicy\Models\ProductionTrackCapabilityCandidate;
use App\Models\User;
use Closure;

final class ReadProductionTrackCapabilities
{
    public function project(ProductionTrackCapabilityCandidate $candidate, array $expectedContext, User $actor): array
    {
        return $this->withLockedForAdapter($candidate, $expectedContext, $actor, fn (array $projection): array => $projection);
    }

    /**
     * Trusted preparation receives one projection argument in legacy mode;
     * supplying a terminal verifier opts into the captured reader as a second
     * preparation argument. The verifier must throw on drift and return void, using only
     * fixed primary reads/pure checks. No callbacks, writes, file or provider I/O.
     */
    public function withLockedForAdapter(ProductionTrackCapabilityCandidate $candidate, array $expectedContext, User $actor, Closure $prepare, ?Closure $finalPrimaryProof = null): mixed
    {
        return app(ProductionTrackCapabilities::class)->withLockedForAdapter($candidate, $expectedContext, $actor, $prepare, $finalPrimaryProof);
    }
}
