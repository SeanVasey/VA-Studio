<?php

namespace App\Providers;

use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutTransport;
use App\Domain\Commerce\ProductionTaxCheckout\UnboundTaxCheckoutTransport;
use Illuminate\Support\ServiceProvider;

/** Not registered by this lane. Root owns registration; the only binding is the refusing default transport. */
final class ProductionTaxCheckoutServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TaxCheckoutTransport::class, UnboundTaxCheckoutTransport::class);
    }
}
