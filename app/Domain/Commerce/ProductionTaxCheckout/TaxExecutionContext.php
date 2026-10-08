<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionPolicy\MachinePolicyV1;
use Illuminate\Validation\ValidationException;

/**
 * Provider-calculated tax execution context. Tax behavior and the rate ceiling come only from the approved
 * machine policy (Sean's choices); account, return origin and funds mode only from configuration. Test funds
 * only: this lane has no live-funds path.
 */
final readonly class TaxExecutionContext
{
    private function __construct(
        public string $fundsMode,
        public string $provenance,
        public string $accountId,
        public string $returnOrigin,
        public string $captureMethod,
        public string $taxBehavior,
        public int $maximumRateBps,
        public int $reservationSeconds,
        public int $providerLifetimeSeconds,
        public int $retrySeconds,
    ) {}

    public static function current(array $machine): self
    {
        return self::make($machine, config('production-tax-checkout.funds_mode'), config('production-tax-checkout.account_id'),
            config('production-tax-checkout.return_origin'));
    }

    /** Called only after authenticating a retained record; never accepts an HTTP execution context. */
    public static function retained(array $machine, array $binding): self
    {
        $context = self::make($machine, $binding['funds_mode'] ?? null, $binding['account_id'] ?? null, $binding['return_origin'] ?? null);
        Evidence::same($context->binding(), $binding);

        return $context;
    }

    private static function make(array $machine, mixed $mode, mixed $account, mixed $origin): self
    {
        try {
            MachinePolicyV1::validate($machine);
        } catch (ValidationException) {
            throw new CheckoutException('unsupported');
        }
        $c = $machine['choices'];
        $p = $c['provider_account'];
        $t = $c['tax_calculation'];
        $r = $c['reservation_and_exclusives'];
        CheckoutException::require($mode === 'test' && app()->environment('local', 'testing')
            && is_string($account) && preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $account) === 1 && $account === $p['account_id']
            && is_string($origin) && $origin === $p['return_origin']
            && $p['api_version'] === TaxCheckoutPolicy::STRIPE_API_VERSION && $p['mode'] === 'live'
            && in_array($p['capture_method'], ['automatic', 'automatic_async'], true)
            && $c['buyer_identity']['mode'] === 'verified_account'
            && $c['currency']['code'] === 'USD' && $c['currency']['minor_unit_exponent'] === 2
            && $t['strategy'] === 'provider_calculated' && $t['rounding'] === 'provider_exact'
            && in_array($t['behavior'], ['exclusive', 'inclusive'], true)
            && is_int($t['maximum_rate_bps']) && $t['maximum_rate_bps'] >= 0 && $t['maximum_rate_bps'] <= 10000
            && $r['provider_lifetime_seconds'] >= 1800 + $r['retry_seconds'] && $r['reservation_seconds'] >= $r['provider_lifetime_seconds'] + $r['retry_seconds']
            && $r['late_time_basis'] === 'application_verified_observation_time', 'unsupported');

        return new self($mode, 'synthetic_rehearsal', $account, $origin, $p['capture_method'], $t['behavior'], $t['maximum_rate_bps'],
            $r['reservation_seconds'], $r['provider_lifetime_seconds'], $r['retry_seconds']);
    }

    public function binding(): array
    {
        return ['schema_version' => 1, 'purpose' => 'production_tax_checkout_execution_context', 'commercial_target_mode' => 'live',
            'funds_mode' => $this->fundsMode, 'provenance' => $this->provenance, 'provider' => 'stripe', 'account_id' => $this->accountId,
            'api_version' => TaxCheckoutPolicy::STRIPE_API_VERSION, 'sdk_version' => TaxCheckoutPolicy::STRIPE_SDK_VERSION,
            'return_origin' => $this->returnOrigin, 'capture_method' => $this->captureMethod,
            'tax' => ['strategy' => 'provider_calculated', 'calculator' => 'stripe_checkout_automatic_tax', 'behavior' => $this->taxBehavior,
                'maximum_rate_bps' => $this->maximumRateBps, 'rounding' => 'provider_exact'],
            'reservation_seconds' => $this->reservationSeconds, 'provider_lifetime_seconds' => $this->providerLifetimeSeconds,
            'retry_seconds' => $this->retrySeconds];
    }

    public function requireBuyer(array $binding): void
    {
        CheckoutException::require(($binding['provenance'] ?? null) === $this->provenance, 'identity', 403);
    }
}
