<?php

namespace App\Domain\Grants\ProductionFree;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

/** Default-off capability baseline. It never approves terms, templates, assets or production storage. */
final class ProductionFreeGrantPolicy
{
    /**
     * The pinned renderer child's hard timeout (`ProductionFreeGrantRendererProcess`: `setTimeout(60)` and
     * `max_execution_time=60`; that file is hash-pinned in the render profile, so the value is mirrored here and a
     * test fails if the two drift). A render lease must outlive the child plus a margin for storing and publishing the
     * original; otherwise a render that succeeds near the timeout stores an immutable PDF whose publication is refused
     * `lease_expired`, and repeated claims exhaust the origin.
     */
    public const RENDERER_TIMEOUT_SECONDS = 60;

    public const RENDER_LEASE_MARGIN_SECONDS = 60;

    public const REHEARSAL = 'synthetic_rehearsal';

    /** @return array{enabled:true,version:1,provenance:string,approved_terms_hashes:list<string>,authorization_ttl_seconds:int,render_lease_seconds:int,snapshot_seconds:int,transfer_min_bytes_per_second:int,transfer_base_seconds:int,transfer_max_seconds:int,spool_slots:int,spool_reserve_bytes:int,storage_root:?string,environment:string} */
    public function current(): array
    {
        $configuration = $this->configuration();
        ProductionFreeGrantException::require($configuration['enabled'] === true && $configuration['version'] === 1
            && $configuration['family'] === ProductionFreeGrantSchema::FAMILY && $configuration['purpose'] === ProductionFreeGrantSchema::PURPOSE, 'disabled');
        // Only synthetic rehearsal in local/testing exists in this batch. Verified production needs Sean's facts,
        // a production storage host and the composed readiness proofs; until then it is refused, never inferred.
        ProductionFreeGrantException::require(in_array($configuration['environment'], ['local', 'testing'], true), 'environment');
        ProductionFreeGrantException::require($configuration['provenance'] === self::REHEARSAL, 'provenance');
        ProductionFreeGrantException::require(App::bound(ProductionFreeGrantSources::class)
            && App::make(ProductionFreeGrantSources::class) instanceof ProductionFreeGrantSources, 'capability_absent');
        $this->prove($configuration);

        return $configuration;
    }

    public function prove(array $expected): void
    {
        ProductionFreeGrantException::require($this->configuration() === $expected, 'changed_policy');
    }

    public function sources(array $expected): ProductionFreeGrantSources
    {
        $this->prove($expected);

        return App::make(ProductionFreeGrantSources::class);
    }

    /** Operative open and assent require the exact terms bytes to be listed by an approved configuration. */
    public function requireApprovedTerms(array $expected, string $termsHash): void
    {
        $this->prove($expected);
        ProductionFreeGrantException::require(in_array($termsHash, $expected['approved_terms_hashes'], true), 'stale_terms');
    }

    private function configuration(): array
    {
        $policy = Config::get('production-free-grants');
        ProductionFreeGrantException::require(is_array($policy), 'changed_policy');
        $environment = App::environment();
        ProductionFreeGrantException::require(is_bool($policy['enabled'] ?? null) && is_int($policy['version'] ?? null)
            && is_string($policy['family'] ?? null) && is_string($policy['purpose'] ?? null)
            && array_key_exists('provenance', $policy) && ($policy['provenance'] === null || is_string($policy['provenance']))
            && is_array($policy['approved_terms_hashes'] ?? null) && array_is_list($policy['approved_terms_hashes'])
            && is_int($policy['authorization_ttl_seconds'] ?? null) && $policy['authorization_ttl_seconds'] >= 30 && $policy['authorization_ttl_seconds'] <= 300
            && is_int($policy['render_lease_seconds'] ?? null) && $policy['render_lease_seconds'] >= self::RENDERER_TIMEOUT_SECONDS + self::RENDER_LEASE_MARGIN_SECONDS && $policy['render_lease_seconds'] <= 900
            && is_int($policy['snapshot_seconds'] ?? null) && $policy['snapshot_seconds'] >= 30 && $policy['snapshot_seconds'] <= 1800
            && is_int($policy['transfer_min_bytes_per_second'] ?? null) && $policy['transfer_min_bytes_per_second'] >= 16384 && $policy['transfer_min_bytes_per_second'] <= 1073741824
            && is_int($policy['transfer_base_seconds'] ?? null) && $policy['transfer_base_seconds'] >= 0 && $policy['transfer_base_seconds'] <= 600
            && is_int($policy['transfer_max_seconds'] ?? null) && $policy['transfer_max_seconds'] >= 60 && $policy['transfer_max_seconds'] <= 14400
            && is_int($policy['spool_slots'] ?? null) && $policy['spool_slots'] >= 1 && $policy['spool_slots'] <= 16
            && is_int($policy['spool_reserve_bytes'] ?? null) && $policy['spool_reserve_bytes'] >= 16777216 && $policy['spool_reserve_bytes'] <= 1099511627776
            && array_key_exists('storage_root', $policy) && ($policy['storage_root'] === null || is_string($policy['storage_root']))
            && is_string($environment), 'changed_policy');
        foreach ($policy['approved_terms_hashes'] as $hash) {
            ProductionFreeGrantException::require(is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/D', $hash) === 1, 'changed_policy');
        }

        return ['enabled' => $policy['enabled'], 'version' => $policy['version'], 'family' => $policy['family'],
            'purpose' => $policy['purpose'], 'provenance' => $policy['provenance'],
            'approved_terms_hashes' => $policy['approved_terms_hashes'],
            'authorization_ttl_seconds' => $policy['authorization_ttl_seconds'], 'render_lease_seconds' => $policy['render_lease_seconds'],
            'snapshot_seconds' => $policy['snapshot_seconds'], 'transfer_min_bytes_per_second' => $policy['transfer_min_bytes_per_second'],
            'transfer_base_seconds' => $policy['transfer_base_seconds'], 'transfer_max_seconds' => $policy['transfer_max_seconds'],
            'spool_slots' => $policy['spool_slots'], 'spool_reserve_bytes' => $policy['spool_reserve_bytes'],
            'storage_root' => $policy['storage_root'], 'environment' => $environment];
    }
}
