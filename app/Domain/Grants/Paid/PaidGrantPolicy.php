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
        self::wellFormed($policy, 503);
        $this->transfer();

        return $policy;
    }

    /**
     * Seconds an admitted transfer may stream, counted from the redemption commit and independent of the authorization
     * lifetime: the base allowance plus the size at the minimum rate, capped.
     */
    public function transferSeconds(int $bytes): int
    {
        $transfer = $this->transfer();
        PaidGrantException::require($bytes >= 1, 503);

        return min($transfer['max'], $transfer['base'] + intdiv($bytes + $transfer['rate'] - 1, $transfer['rate']));
    }

    /** @return array{rate:int,base:int,max:int} Bounds mirror ProductionFreeGrantPolicy. */
    private function transfer(): array
    {
        $rate = config('paid-grants.transfer_min_bytes_per_second');
        $base = config('paid-grants.transfer_base_seconds');
        $max = config('paid-grants.transfer_max_seconds');
        PaidGrantException::require(is_int($rate) && $rate >= 16384 && $rate <= 1073741824 && is_int($base) && $base >= 0 && $base <= 600
            && is_int($max) && $max >= 60 && $max <= 14400, 503);

        return ['rate' => $rate, 'base' => $base, 'max' => $max];
    }

    /**
     * An order keeps the delivery policy it was finalized under, and its download limit and authorization lifetime
     * come from that retained copy. Revising the configured policy (its version, limit or lifetime) for future orders
     * must not lock earlier buyers out of their license and files, so a retained policy is accepted when it is a
     * well-formed policy of the current provenance. Enablement, provenance and identity are still proven against the
     * current configuration (`capture()`, `provePure()`); a rehearsal order never runs under a production policy or
     * the reverse.
     */
    public static function retained(mixed $retained, array $current): void
    {
        self::wellFormed($retained, 409);
        PaidGrantException::require($retained['provenance'] === $current['provenance'], 409);
    }

    private static function wellFormed(mixed $policy, int $status): void
    {
        PaidGrantException::require(is_array($policy) && ! array_is_list($policy), $status);
        PaidGrantInput::keys($policy, ['schema_version', 'version', 'purpose', 'provenance', 'max_downloads', 'authorization_seconds']);
        PaidGrantException::require($policy['schema_version'] === 1 && $policy['purpose'] === 'paid-original-delivery'
            && is_string($policy['version']) && preg_match('/\A[a-z][a-z0-9_-]{0,79}\z/D', $policy['version']) === 1
            && in_array($policy['provenance'], ['synthetic_rehearsal', 'verified_production'], true)
            && is_int($policy['max_downloads']) && $policy['max_downloads'] >= 1 && $policy['max_downloads'] <= 100
            && is_int($policy['authorization_seconds']) && $policy['authorization_seconds'] >= 30 && $policy['authorization_seconds'] <= 600, $status);
    }

    /** Captured repository only: caller resolves all injectable services before terminal raw proof. */
    public static function provePure(array $policy, Repository $configuration, string $environment): void
    {
        $rehearsal = in_array($environment, ['local', 'testing'], true);
        $current = PaidGrantConfiguration::read($configuration, 'paid-grants.delivery_policy');
        PaidGrantException::require(is_array($current) && CanonicalJson::encode($current) === CanonicalJson::encode($policy)
            && ($rehearsal ? PaidGrantConfiguration::read($configuration, 'paid-grants.rehearsal_enabled') === true && $policy['provenance'] === 'synthetic_rehearsal'
                : PaidGrantConfiguration::read($configuration, 'paid-grants.operative_enabled') === true && $policy['provenance'] === 'verified_production')
            && PaidGrantConfiguration::read($configuration, 'production-customer-identity.enabled') === true
            && PaidGrantConfiguration::read($configuration, 'production-customer-identity.provenance') === $policy['provenance'], 403);
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
