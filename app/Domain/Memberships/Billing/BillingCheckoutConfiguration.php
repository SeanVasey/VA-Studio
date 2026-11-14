<?php

namespace App\Domain\Memberships\Billing;

/**
 * Validates the staff-entered write-side configuration (config/production-membership-billing-checkout.php) and returns
 * only checked values. A price id, return URL or portal configuration id that is absent or malformed is a refusal, never a
 * default: nothing here is invented, and no value is a price fact (the plan version and Stripe's own price decide that).
 */
final class BillingCheckoutConfiguration
{
    public const KEY = 'production-membership-billing-checkout';

    /** @return array{price_ref: string, success_url: string, cancel_url: string} */
    public static function checkout(string $planVersionId): array
    {
        $configuration = self::enabled();
        $refs = $configuration['price_refs'];
        BillingException::require(is_array($refs) && count($refs) <= 100, 'checkout_configuration');
        $price = $refs[$planVersionId] ?? null;
        BillingException::require(BillingValues::is('price', $price), 'price_not_configured');

        return ['price_ref' => $price, 'success_url' => self::url($configuration['success_url'] ?? null, true),
            'cancel_url' => self::url($configuration['cancel_url'] ?? null, false)];
    }

    /** @return array{return_url: string, configuration_ref: ?string} */
    public static function portal(): array
    {
        $configuration = self::enabled();
        $reference = $configuration['portal_configuration_ref'] ?? null;
        BillingException::require($reference === null || (is_string($reference) && preg_match('/\Abpc_[A-Za-z0-9]{1,120}\z/D', $reference) === 1), 'checkout_configuration');

        return ['return_url' => self::url($configuration['portal_return_url'] ?? null, false), 'configuration_ref' => $reference];
    }

    private static function enabled(): array
    {
        $configuration = config(self::KEY);
        BillingException::require(is_array($configuration) && ($configuration['enabled'] ?? null) === true, 'checkout_disabled');

        return $configuration;
    }

    /**
     * An absolute URL on this application's own host. https everywhere; plain http only for localhost in local/testing. No
     * credentials, fragment or whitespace, so a configured value cannot become an open redirect or smuggle a second URL.
     */
    private static function url(mixed $value, bool $sessionPlaceholder): string
    {
        BillingException::require(is_string($value) && $value !== '' && strlen($value) <= 512 && preg_match('/\A[\x21-\x7e]+\z/D', $value) === 1, 'checkout_configuration');
        $probe = $value;
        if ($sessionPlaceholder) {
            BillingException::require(substr_count($value, '{CHECKOUT_SESSION_ID}') <= 1, 'checkout_configuration');
            $probe = str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_placeholder', $value);
        }
        $parts = parse_url($probe);
        $own = parse_url((string) config('app.url'));
        BillingException::require(is_array($parts) && is_array($own) && isset($parts['scheme'], $parts['host'], $own['host'])
            && array_intersect_key($parts, ['user' => 1, 'pass' => 1, 'fragment' => 1]) === []
            && strtolower($parts['host']) === strtolower($own['host']) && ($parts['port'] ?? null) === ($own['port'] ?? null), 'checkout_configuration');
        $local = in_array(app()->environment(), ['local', 'testing'], true) && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true);
        BillingException::require($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && $local), 'checkout_configuration');

        return $value;
    }
}
