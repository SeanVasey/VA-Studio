<?php

namespace App\Providers;

use App\Domain\Commerce\ProductionCheckout\OwnAccountStripeGateway;
use App\Domain\Commerce\ProductionCheckout\ProviderGateway;
use Illuminate\Support\ServiceProvider;

/** Registration and private route integration are separate root-owned composition steps. */
final class ProductionCheckoutServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProviderGateway::class, OwnAccountStripeGateway::class);
    }
}
