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

    /**
     * The public service catalog pages (BL-64). A separate, default-off switch from the test project journey above: the
     * pages only describe staff-approved services and lead to the ordinary contact inquiry; they collect no money.
     * The value is a code literal in config, so only a reviewed change turns it on.
     */
    public function catalogEnabled(): bool
    {
        return config('services-projects.catalog_enabled') === true;
    }
}
