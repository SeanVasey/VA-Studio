<?php

namespace App\Domain\Commerce\Checkout;

use App\Domain\Commerce\QuoteException;
use App\Support\Environment\TestEnvironment;
use Throwable;

final class CheckoutPolicy
{
    public const API_VERSION = '2026-08-26.dahlia';

    public function current(): array
    {
        if (! TestEnvironment::admitsTestCommerce() || config('payments.stripe.checkout_enabled') !== true) {
            throw new QuoteException('CHECKOUT_UNAVAILABLE', 503);
        }
        try {
            $raw = config('commerce.test_checkout_policy');
            if (! is_string($raw) || strlen($raw) > 4096) { throw new \UnexpectedValueException; }

            return $this->validate(json_decode($raw, true, 16, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            throw new QuoteException('CHECKOUT_UNAVAILABLE', 503);
        }
    }

    public function enabled(): bool
    {
        try { $this->current(); $this->account(); return true; } catch (Throwable) { return false; }
    }

    public function account(): string
    {
        $account = config('payments.stripe.account_id');
        if (! TestEnvironment::admitsTestCommerce() || config('payments.stripe.mode') !== 'test' ||
            ! is_string($account) || ! preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/', $account)) {
            throw new QuoteException('CHECKOUT_UNAVAILABLE', 503);
        }

        return $account;
    }

    public function validate(mixed $policy): array
    {
        $keys = ['schema_version', 'purpose', 'version', 'provider_lifetime_seconds', 'retry_seconds', 'pending_resources', 'tax', 'return_origin'];
        if (! is_array($policy) || count($policy) !== count($keys) || array_diff($keys, array_keys($policy)) ||
            $policy['schema_version'] !== 1 || $policy['purpose'] !== 'test_hosted_checkout' ||
            ! is_string($policy['version']) || ! preg_match('/\A[A-Za-z0-9._-]{1,80}\z/', $policy['version']) ||
            $policy['provider_lifetime_seconds'] !== 3600 || $policy['retry_seconds'] !== 900 ||
            $policy['pending_resources'] !== 'retain_until_authoritative_finalization' || $policy['tax'] !== 'fixed_test_zero' ||
            ! $this->origin($policy['return_origin'])) { throw new \UnexpectedValueException('Invalid test checkout policy.'); }

        return $policy;
    }

    private function origin(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 255 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) { return false; }
        $parts = parse_url($value);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) ||
            array_diff(array_keys($parts), ['scheme', 'host', 'port']) || str_ends_with($value, '/')) { return false; }

        return $parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true));
    }
}
