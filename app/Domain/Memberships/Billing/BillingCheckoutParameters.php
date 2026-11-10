<?php

namespace App\Domain\Memberships\Billing;

use SensitiveParameter;

/**
 * Builds and re-validates the only provider write shapes Billing uses. The SDK gateway calls the assert methods, so a
 * caller (or a fake) cannot widen a write beyond: one price at quantity 1, flexible billing mode, our own opaque references,
 * no Connect routing (no account, on_behalf_of, transfer or application fee), no discounts or trials, and no caller-chosen
 * customer on a checkout. Nothing here is a price: the price id is staff-entered and the amount is judged against the plan.
 */
final class BillingCheckoutParameters
{
    public const CHECKOUT_KEY = '/\Ava-membership-checkout-v1-[a-f0-9]{64}\z/D';

    public const PORTAL_KEY = '/\Ava-membership-portal-v1-[a-f0-9]{64}\z/D';

    public const REQUEST_KEY = '/\A[A-Za-z0-9_-]{16,80}\z/D';

    /** @param array<string, string> $metadata */
    public static function subscription(string $priceRef, string $successUrl, string $cancelUrl, string $clientReference, array $metadata): array
    {
        $params = ['mode' => 'subscription', 'line_items' => [['price' => $priceRef, 'quantity' => 1]], 'success_url' => $successUrl,
            'cancel_url' => $cancelUrl, 'client_reference_id' => $clientReference, 'metadata' => $metadata,
            'subscription_data' => ['billing_mode' => ['type' => 'flexible'], 'metadata' => $metadata]];
        self::assertSubscription($params);

        return $params;
    }

    public static function portal(string $customerRef, string $returnUrl, ?string $configurationRef): array
    {
        $params = ['customer' => $customerRef, 'return_url' => $returnUrl];
        if ($configurationRef !== null) {
            $params['configuration'] = $configurationRef;
        }
        self::assertPortal($params);

        return $params;
    }

    public static function assertSubscription(#[SensitiveParameter] array $params): void
    {
        $keys = array_keys($params);
        sort($keys);
        BillingException::require($keys === ['cancel_url', 'client_reference_id', 'line_items', 'metadata', 'mode', 'subscription_data', 'success_url']
            && $params['mode'] === 'subscription'
            && is_array($params['line_items']) && array_is_list($params['line_items']) && count($params['line_items']) === 1
            && self::keys($params['line_items'][0], ['price', 'quantity']) && BillingValues::is('price', $params['line_items'][0]['price']) && $params['line_items'][0]['quantity'] === 1
            && is_string($params['success_url']) && is_string($params['cancel_url'])
            && is_string($params['client_reference_id']) && preg_match('/\Ava-sub-[a-f0-9]{32}\z/D', $params['client_reference_id']) === 1
            && self::metadata($params['metadata'])
            && is_array($params['subscription_data']) && self::keys($params['subscription_data'], ['billing_mode', 'metadata'])
            && $params['subscription_data']['billing_mode'] === ['type' => 'flexible'] && $params['subscription_data']['metadata'] === $params['metadata'], 'write_shape');
    }

    public static function assertPortal(#[SensitiveParameter] array $params): void
    {
        $keys = array_keys($params);
        sort($keys);
        BillingException::require(($keys === ['customer', 'return_url'] || $keys === ['configuration', 'customer', 'return_url'])
            && BillingValues::is('customer', $params['customer']) && is_string($params['return_url'])
            && (! isset($params['configuration']) || (is_string($params['configuration']) && preg_match('/\Abpc_[A-Za-z0-9]{1,120}\z/D', $params['configuration']) === 1)), 'write_shape');
    }

    public static function assertKey(#[SensitiveParameter] string $key, string $pattern): void
    {
        BillingException::require(preg_match($pattern, $key) === 1, 'write_shape');
    }

    private static function keys(mixed $value, array $expected): bool
    {
        if (! is_array($value)) {
            return false;
        }
        $keys = array_keys($value);
        sort($keys);

        return $keys === $expected;
    }

    private static function metadata(mixed $metadata): bool
    {
        if (! self::keys($metadata, ['va_member_ref', 'va_plan_version_id', 'va_purpose'])) {
            return false;
        }

        return preg_match('/\A[a-f0-9]{64}\z/D', (string) $metadata['va_member_ref']) === 1
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', (string) $metadata['va_plan_version_id']) === 1
            && $metadata['va_purpose'] === 'production_membership_subscription_checkout';
    }
}
