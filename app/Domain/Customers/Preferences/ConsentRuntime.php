<?php

namespace App\Domain\Customers\Preferences;

/** Reviewed production identity/purpose adapters may replace the default synthetic runtime.
 * Implementations must be pure: no network, persistence, customer inference or sends.
 */
interface ConsentRuntime
{
    public function grantsEnabled(): bool;
}
