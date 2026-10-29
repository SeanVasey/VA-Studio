<?php

namespace App\Providers;

use App\Domain\Commerce\ProductionCheckout\OwnAccountStripeGateway;
use App\Domain\Commerce\ProductionCheckout\ProviderGateway;
use Illuminate\Support\ServiceProvider;

/** Registered by AppServiceProvider only when ProductionCheckoutMount admits (lane PCC); tests may register it directly. */
final class ProductionCheckoutServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProviderGateway::class, OwnAccountStripeGateway::class);
    }
}
