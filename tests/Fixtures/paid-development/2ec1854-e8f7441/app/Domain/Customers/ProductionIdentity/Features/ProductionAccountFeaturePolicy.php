<?php

namespace App\Domain\Customers\ProductionIdentity\Features;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;

/** An explicit consumer version, never a switch that activates legacy test-origin APIs. */
final readonly class ProductionAccountFeaturePolicy
{
    public const VERSIONS = [
        'listening_library' => 'production-listening-library-identity-v1',
        'consent_preferences' => 'production-consent-preferences-identity-v1',
        'service_projects' => 'production-service-projects-identity-v1',
    ];

    public string $version;

    public function __construct(public string $feature)
    {
        $this->version = self::VERSIONS[$feature] ?? throw new IdentityException;
    }

    public function current(): array
    {
        $identity = new IdentityPolicy;
        $identity->requireEnabled();
        $scope = config('production-account-features.provenance');
        if (config('production-account-features.enabled', false) !== true || $scope !== $identity->provenance()
            || config('production-account-features.versions.'.$this->feature) !== $this->version) {
            throw new IdentityException;
        }

        return ['feature' => $this->feature, 'version' => $this->version, 'provenance' => $scope];
    }
}
