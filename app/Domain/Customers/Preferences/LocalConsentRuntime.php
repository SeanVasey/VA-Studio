<?php

namespace App\Domain\Customers\Preferences;

final class LocalConsentRuntime implements ConsentRuntime
{
    public function grantsEnabled(): bool
    {
        return app()->environment('local', 'testing') && config('customer-preferences.test_grants_enabled') === true;
    }
}
