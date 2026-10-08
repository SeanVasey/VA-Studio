<?php

namespace App\Domain\Customers;

use App\Support\Environment\TestEnvironment;

final class CustomerAccessPolicy
{
    public function enabled(): bool
    {
        return TestEnvironment::admitsTestCommerce() && config('customer.test_accounts_enabled') === true;
    }

    public function requireEnabled(): void
    {
        if (! $this->enabled()) {
            throw new CustomerAccessException;
        }
    }
}
