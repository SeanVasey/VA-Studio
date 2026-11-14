<?php

namespace App\Domain\Services\Invoicing;

use App\Domain\Services\Projects\ServiceProjectPolicy;
use App\Support\Environment\TestEnvironment;

/**
 * Stripe test-mode invoicing for service projects (BL-70 phase 1). Admitted only where test commerce is admitted, with an explicit
 * default-off flag, the service-project journey itself enabled, and Stripe test mode. Production refuses by environment and by the
 * production validator's hold on the flag.
 */
final class ServiceInvoicePolicy
{
    public function enabled(): bool
    {
        return TestEnvironment::admitsTestCommerce() && config('services-invoicing.test_enabled') === true
            && config('payments.stripe.mode') === 'test' && app(ServiceProjectPolicy::class)->enabled();
    }

    public function requireEnabled(): void
    {
        if (! $this->enabled()) {
            throw new ServiceInvoiceException(404);
        }
    }
}
