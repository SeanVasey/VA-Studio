<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;

/** Real root bootstrap, with only an explicit synthetic mount setting before providers/routes boot. */
trait PaidGrantRootHttpFixture
{
    protected mixed $paidHttpMountSetting = true;

    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['config']->set('paid-grants.http_mounted', $this->paidHttpMountSetting);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
