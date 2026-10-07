<?php

namespace App\Domain\Services\Projects;

final class ServiceProjectPolicy
{
    public function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('services-projects.test_enabled') === true;
    }

    public function requireEnabled(): void
    {
        if (! $this->enabled()) {
            throw new ServiceProjectException(404);
        }
    }
}
