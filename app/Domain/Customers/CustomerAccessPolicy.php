<?php

namespace App\Domain\Customers;

final class CustomerAccessPolicy
{
    public function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('customer.test_accounts_enabled') === true;
    }

    public function requireEnabled(): void
    {
        if (! $this->enabled()) {
            throw new CustomerAccessException;
        }
    }
}
