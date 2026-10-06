<?php

namespace App\Domain\Customers;

final class CustomerIdentityPolicy
{
    public const VERSION = 'customer-local-identity-v1';

    public const TTL_SECONDS = 600;

    public function enabled(): bool
    {
        return app(CustomerAccessPolicy::class)->enabled() && config('customer.test_identity_enabled') === true
            && config('customer.identity_transport') === 'private_capture';
    }

    public function requireEnabled(): void
    {
        if (! $this->enabled()) {
            throw new CustomerAccessException;
        }
    }

    public static function email(string $email): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || preg_match('/[^\x21-\x7e]/', $email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new CustomerAccessException;
        }

        return $email;
    }
}
