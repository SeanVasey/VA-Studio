<?php

namespace App\Domain\Grants\Paid;

use App\Support\CanonicalJson;
use Illuminate\Config\Repository;

/** Technical delivery input is explicitly authored; it does not replace original license assent. */
final class PaidGrantPolicy
{
    public function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('paid-grants.rehearsal_enabled') === true
            || ! app()->environment('local', 'testing') && config('paid-grants.operative_enabled') === true;
    }

    public function capture(): array
    {
        PaidGrantException::require($this->enabled(), 404);
        $policy = config('paid-grants.delivery_policy');
        PaidGrantException::require(is_array($policy) && ! array_is_list($policy), 503);
        PaidGrantInput::keys($policy, ['schema_version', 'version', 'purpose', 'provenance', 'max_downloads', 'authorization_seconds']);
        PaidGrantException::require($policy['schema_version'] === 1 && $policy['purpose'] === 'paid-original-delivery'
            && is_string($policy['version']) && preg_match('/\A[a-z][a-z0-9_-]{0,79}\z/D', $policy['version']) === 1
            && in_array($policy['provenance'], ['synthetic_rehearsal', 'verified_production'], true)
            && is_int($policy['max_downloads']) && $policy['max_downloads'] >= 1 && $policy['max_downloads'] <= 100
            && is_int($policy['authorization_seconds']) && $policy['authorization_seconds'] >= 30 && $policy['authorization_seconds'] <= 600, 503);

        return $policy;
    }

    /** Captured repository only: caller resolves all injectable services before terminal raw proof. */
    public static function provePure(array $policy, Repository $configuration, string $environment): void
    {
        $rehearsal = in_array($environment, ['local', 'testing'], true);
        $current = $configuration->get('paid-grants.delivery_policy');
        PaidGrantException::require(is_array($current) && CanonicalJson::encode($current) === CanonicalJson::encode($policy)
            && ($rehearsal ? $configuration->get('paid-grants.rehearsal_enabled') === true && $policy['provenance'] === 'synthetic_rehearsal'
                : $configuration->get('paid-grants.operative_enabled') === true && $policy['provenance'] === 'verified_production')
            && $configuration->get('production-customer-identity.enabled') === true
            && $configuration->get('production-customer-identity.provenance') === $policy['provenance'], 403);
    }

    public function source(array $line, array $policy): void
    {
        PaidGrantException::require(($line['schema_version'] ?? null) === 1 && ($line['producer'] ?? null) === 'production_checkout_v1'
            && ($line['provenance'] ?? null) === $policy['provenance'] && ($line['buyer']['provenance'] ?? null) === $policy['provenance']
            && ($line['assent']['accepted'] ?? null) === true && ($line['license']['type'] ?? null) === 'non-exclusive', 409);
        $rehearsal = $policy['provenance'] === 'synthetic_rehearsal';
        PaidGrantException::require(($line['funds_mode'] ?? null) === ($rehearsal ? 'test' : 'live')
            && ($line['payment_evidence_origin'] ?? null) === ($rehearsal ? 'synthetic_rehearsal' : 'own_account_sdk'), 409);
        $source = $line;
        unset($source['source_hash']);
        PaidGrantException::require(hash_equals(PaidGrantInput::hash($line['source_hash'] ?? null), CanonicalJson::hash($source)), 409);
    }
}
