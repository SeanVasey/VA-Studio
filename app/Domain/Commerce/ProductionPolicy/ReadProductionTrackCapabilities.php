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

    /** Trusted domain preparation callback only; no provider I/O or execution authority is supplied. */
    public function withLockedForAdapter(ProductionTrackCapabilityCandidate $candidate, array $expectedContext, User $actor, Closure $prepare): mixed
    {
        return app(ProductionTrackCapabilities::class)->withLockedForAdapter($candidate, $expectedContext, $actor, $prepare);
    }
}
