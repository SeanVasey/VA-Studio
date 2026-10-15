<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidLineAdapterV2;
use Illuminate\Config\Repository;

/** Explicit retained technical policy. Tax V2 currently has only a local/testing synthetic producer. */
final class PaidTaxPolicy
{
    public function capture(): array
    {
        PaidTaxException::require(app()->environment('local', 'testing') && config('paid-tax-grants.rehearsal_enabled') === true, 404);
        $policy = config('paid-tax-grants.delivery_policy');
        self::wellFormed($policy);

        return $policy;
    }

    public static function retained(mixed $retained, array $current): void
    {
        self::wellFormed($retained);
        PaidTaxException::require($retained['provenance'] === $current['provenance'], 409);
    }

    private static function wellFormed(mixed $policy): void
    {
        PaidTaxException::require(is_array($policy) && ! array_is_list($policy));
        PaidTaxInput::keys($policy, ['schema_version', 'version', 'purpose', 'provenance', 'max_downloads', 'authorization_seconds']);
        PaidTaxException::require($policy['schema_version'] === 2 && $policy['purpose'] === 'paid-tax-original-delivery'
            && $policy['provenance'] === 'synthetic_rehearsal'
            && is_string($policy['version']) && preg_match('/\A[a-z][a-z0-9_-]{0,79}\z/D', $policy['version']) === 1
            && is_int($policy['max_downloads']) && $policy['max_downloads'] >= 1 && $policy['max_downloads'] <= 100
            && is_int($policy['authorization_seconds']) && $policy['authorization_seconds'] >= 30 && $policy['authorization_seconds'] <= 600);
    }

    public static function provePure(array $policy, Repository $configuration, string $environment): void
    {
        self::wellFormed($policy);
        PaidTaxException::require(in_array($environment, ['local', 'testing'], true)
            && PaidTaxConfiguration::read($configuration, 'paid-tax-grants.rehearsal_enabled') === true
            && PaidTaxConfiguration::read($configuration, 'paid-tax-grants.operative_enabled') === false
            && PaidTaxConfiguration::read($configuration, 'paid-tax-grants.delivery_policy') === $policy
            && PaidTaxConfiguration::read($configuration, 'production-customer-identity.enabled') === true
            && PaidTaxConfiguration::read($configuration, 'production-customer-identity.provenance') === 'synthetic_rehearsal', 403);
    }

    /** Consistency only: initial authority comes exclusively from the held V2 adapter. */
    public function source(array $line, array $policy): void
    {
        self::wellFormed($policy);
        ProductionTaxPaidLineAdapterV2::assertSelfConsistent($line, $policy['provenance']);
    }
}
