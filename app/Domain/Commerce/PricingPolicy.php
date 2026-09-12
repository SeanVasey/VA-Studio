<?php

namespace App\Domain\Commerce;

use App\Support\Money\MinorUnits;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/** The policy is server configuration, never a request body or an inferred tax decision. */
final class PricingPolicy
{
    public function current(): ?array
    {
        $json = config('commerce.test_pricing_policy');
        if ($json === null || $json === '') {
            return null;
        }
        try {
            if (! app()->environment('local', 'testing') || ! is_string($json) || strlen($json) > 8192) {
                throw new InvalidArgumentException('Test pricing is unavailable in this environment.');
            }
            $policy = $this->validate(json_decode($json, true, 8, JSON_THROW_ON_ERROR));
            $this->activeAt($policy, now()->toImmutable());

            return $policy;
        } catch (Throwable) {
            throw new QuoteException('PRICING_UNAVAILABLE', 503);
        }
    }

    public function validate(mixed $policy): array
    {
        self::keys($policy, ['schema_version', 'scope', 'key', 'version', 'currency', 'provider', 'account', 'effective_from', 'effective_until', 'tax']);
        if ($policy['schema_version'] !== 1 || $policy['scope'] !== 'test' || $policy['currency'] !== 'USD' || $policy['provider'] !== 'stripe' ||
            ! is_string($policy['key']) || ! preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $policy['key']) ||
            ! is_int($policy['version']) || $policy['version'] < 1 || $policy['version'] > 2147483647 ||
            ! is_string($policy['account']) || ! preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $policy['account'])) {
            throw new InvalidArgumentException('Unsupported pricing policy.');
        }
        if ($this->timestamp($policy['effective_until'])->lessThanOrEqualTo($this->timestamp($policy['effective_from']))) {
            throw new InvalidArgumentException('Invalid policy effective interval.');
        }
        $tax = $policy['tax'];
        $fixed = is_array($tax) && ($tax['mode'] ?? null) === 'fixed_test';
        self::keys($tax, $fixed ? ['mode', 'behavior', 'rounding', 'rate_bps'] : ['mode', 'behavior', 'max_rate_bps']);
        if ($tax['behavior'] !== 'exclusive' || (! $fixed && $tax['mode'] !== 'provider_calculated') || ($fixed && $tax['rounding'] !== 'line_half_up')) {
            throw new InvalidArgumentException('Unsupported tax calculation.');
        }
        MinorUnits::rate($tax[$fixed ? 'rate_bps' : 'max_rate_bps']);

        return $policy;
    }

    public function activeAt(array $policy, CarbonImmutable $instant): void
    {
        if ($instant->lessThan($this->timestamp($policy['effective_from'])) || $instant->greaterThanOrEqualTo($this->timestamp($policy['effective_until']))) {
            throw new InvalidArgumentException('Pricing policy is outside its effective interval.');
        }
    }

    public function timestamp(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $value)) {
            throw new InvalidArgumentException('Use an exact UTC policy timestamp.');
        }
        $time = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, 'UTC');
        if (! $time || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new InvalidArgumentException('Invalid policy timestamp.');
        }

        return $time;
    }

    public static function keys(mixed $value, array $keys): void
    {
        if (! is_array($value) || count($value) !== count($keys) || array_diff(array_keys($value), $keys) !== []) {
            throw new InvalidArgumentException('The pricing schema does not match.');
        }
    }
}
