<?php

namespace App\Domain\Customers\Preferences;

use App\Support\Environment\TestEnvironment;

final class LocalConsentRuntime implements ConsentRuntime
{
    public function grantsEnabled(): bool
    {
        return TestEnvironment::admitsTestCommerce() && config('customer-preferences.test_grants_enabled') === true;
    }
}
