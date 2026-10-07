<?php

namespace App\Domain\Grants\Free\Production;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Grants\Free\FreeGrantException;

/** Distinct identity/purpose configuration. No terms, asset or fulfillment approval is inferred. */
final class ProductionFreeGrantIdentityPolicy
{
    public const VERSION = 'production-free-grant-identity-v1';

    public const FAMILY = 'production-free-origin-v1';

    public const PURPOSE = 'production-free-origin-v1';

    public function current(): array
    {
        $provenance = config('production-free-grant-identity.provenance');
        FreeGrantException::require(config('production-free-grant-identity.enabled') === true
            && config('production-free-grant-identity.version') === self::VERSION
            && config('production-free-grant-identity.purpose') === self::PURPOSE
            && config('free-grants.operative_enabled') === true && config('free-grants.test_enabled') !== true, 404);
        FreeGrantException::require(in_array($provenance, [IdentityPolicy::REHEARSAL, IdentityPolicy::PRODUCTION], true)
            && ($provenance !== IdentityPolicy::REHEARSAL || app()->environment('local', 'testing')), 403);

        return ['version' => self::VERSION, 'family' => self::FAMILY, 'purpose' => self::PURPOSE, 'provenance' => $provenance];
    }
}
