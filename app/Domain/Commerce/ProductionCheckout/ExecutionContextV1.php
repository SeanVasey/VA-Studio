<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Commerce\ProductionPolicy\MachinePolicyV1;
use App\Support\Environment\TestEnvironment;

/** Commercial target and execution funds are distinct. Rehearsal never establishes production facts. */
final readonly class ExecutionContextV1
{
    public const API_VERSION = '2026-08-26.dahlia';

    private function __construct(
        public string $fundsMode,
        public string $provenance,
        public string $accountId,
        public string $returnOrigin,
        public string $captureMethod,
        public int $reservationSeconds,
        public int $providerLifetimeSeconds,
        public int $retrySeconds,
        public int $reviewLifetimeSeconds,
    ) {}

    public static function current(array $machine): self
    {
        $mode = config('production_checkout.funds_mode');
        $account = config('production_checkout.account_id');
        $origin = config('production_checkout.return_origin');

        return self::make($machine, $mode, $account, $origin, config('production_checkout.review_lifetime_seconds'));
    }

    /** Called only after authenticating a retained record; never accepts an HTTP execution context. */
    public static function retained(array $machine, array $binding): self
    {
        $context = self::make($machine, $binding['funds_mode'] ?? null, $binding['account_id'] ?? null, $binding['return_origin'] ?? null, $binding['review_lifetime_seconds'] ?? null);
        Evidence::same($context->binding(), $binding);

        return $context;
    }

    private static function make(array $machine, mixed $mode, mixed $account, mixed $origin, mixed $reviewSeconds): self
    {
        MachinePolicyV1::validate($machine);
        $c = $machine['choices'];
        $p = $c['provider_account'];
        $r = $c['reservation_and_exclusives'];
        CheckoutException::require(in_array($mode, ['test', 'live'], true)
            && $account === $p['account_id'] && $origin === $p['return_origin']
            && $p['api_version'] === self::API_VERSION && $p['mode'] === 'live'
            && in_array($p['capture_method'], ['automatic', 'automatic_async'], true)
            && $c['buyer_identity']['mode'] === 'verified_account'
            && $c['currency']['code'] === 'USD' && $c['currency']['minor_unit_exponent'] === 2
            && $c['tax_calculation']['strategy'] === 'declared_exemption'
            && $c['tax_calculation']['maximum_rate_bps'] === 0 && $c['tax_calculation']['rounding'] === 'not_applicable'
            && $r['provider_lifetime_seconds'] >= 1800 + $r['retry_seconds'] && $r['reservation_seconds'] >= $r['provider_lifetime_seconds'] + $r['retry_seconds']
            && $r['late_time_basis'] === 'application_verified_observation_time', 'unsupported');
        CheckoutException::require(is_int($reviewSeconds) && $reviewSeconds >= 30 && $reviewSeconds <= 3600, 'unsupported');
        CheckoutException::require($mode !== 'test' || app()->environment(['local', 'testing']), 'unsupported');
        // Staging admits only Stripe-test commerce; live funds there are refused, never configured.
        CheckoutException::require($mode !== 'live' || ! TestEnvironment::refusesProductionOnly(), 'unsupported');

        return new self($mode, $mode === 'test' ? 'synthetic_rehearsal' : 'verified_production',
            $account, $origin, $p['capture_method'], $r['reservation_seconds'], $r['provider_lifetime_seconds'], $r['retry_seconds'], $reviewSeconds);
    }

    public function binding(): array
    {
        return ['schema_version' => 1, 'commercial_target_mode' => 'live', 'funds_mode' => $this->fundsMode,
            'provenance' => $this->provenance, 'provider' => 'stripe', 'account_id' => $this->accountId,
            'api_version' => self::API_VERSION, 'return_origin' => $this->returnOrigin, 'capture_method' => $this->captureMethod,
            'reservation_seconds' => $this->reservationSeconds, 'provider_lifetime_seconds' => $this->providerLifetimeSeconds,
            'retry_seconds' => $this->retrySeconds, 'review_lifetime_seconds' => $this->reviewLifetimeSeconds];
    }

    public function requireBuyer(array $binding): void
    {
        CheckoutException::require(($binding['provenance'] ?? null) === $this->provenance, 'identity', 403);
    }
}
