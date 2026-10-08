<?php

namespace App\Domain\Services\Projects;

use App\Support\Environment\TestEnvironment;

final class ServiceProjectPolicy
{
    public function enabled(): bool
    {
        return TestEnvironment::admitsTestCommerce() && config('services-projects.test_enabled') === true;
    }

    public function requireEnabled(): void
    {
        if (! $this->enabled()) {
            throw new ServiceProjectException(404);
        }
    }
}
