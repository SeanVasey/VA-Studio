<?php

namespace App\Domain\Grants\Free;

final class FreeGrantPolicy
{
    public function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('free-grants.test_enabled') === true
            || config('free-grants.operative_enabled') === true && app()->bound(FreeGrantIdentity::class);
    }

    public function requireEnabled(): void
    {
        FreeGrantException::require($this->enabled(), 404);
    }

    public function identity(): FreeGrantIdentity
    {
        $this->requireEnabled();

        return app()->bound(FreeGrantIdentity::class) ? app(FreeGrantIdentity::class) : new TestFreeGrantIdentity;
    }

    public function requireDefinition(array $payload): void
    {
        $this->requireEnabled();
        FreeGrantException::require(($payload['schema_version'] ?? null) === 'free-definition-v1', 503);
        if (($payload['test_only'] ?? null) === true) {
            FreeGrantException::require(app()->environment('local', 'testing') && config('free-grants.test_enabled') === true, 404);
        } else {
            // Only a reviewed operative identity/approved-terms successor may mint that provenance.
            FreeGrantException::require(($payload['operative_approval'] ?? null) === 'approved-free-terms-v1'
                && config('free-grants.operative_enabled') === true && app()->bound(FreeGrantIdentity::class), 404);
        }
    }
}
