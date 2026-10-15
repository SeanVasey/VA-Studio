<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;

/** Actual bootstrap; explicit synthetic flags are loaded before provider and route registration. */
trait PaidTaxRootHttpFixture
{
    protected mixed $taxGrantMount = true;

    protected mixed $taxCheckoutMount = true;

    protected string $taxRootEnvironment = 'testing';

    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['env'] = $this->taxRootEnvironment;
            $app['config']->set('paid-tax-grants.http_mounted', $this->taxGrantMount);
            $app['config']->set('production-tax-checkout.http_mounted', $this->taxCheckoutMount);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
