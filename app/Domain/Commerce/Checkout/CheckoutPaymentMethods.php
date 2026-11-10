<?php

namespace App\Domain\Commerce\Checkout;

/**
 * Single source for the hosted Checkout payment-method allowlist (BL-68).
 *
 * `card` carries Apple Pay and Google Pay (they settle as type `card`) and `link` is Link. Confirmation is not
 * assumed synchronous (Link can be bank-funded): a `processing` PaymentIntent is retried and never granted until
 * `succeeded`, and `async_payment_*` events are locators only. A grant still needs a paid session plus one verified
 * `succeeded` PaymentIntent with the exact amount. Delayed-settlement and redirect methods the code does not
 * model (bank debits, Klarna, Affirm, Cash App and similar) are never offered and never accepted.
 *
 * Provider evidence must equal the list the immutable stored request asked for, compared as an unordered set:
 * Stripe echoes the requested types, so a different set means the session or PaymentIntent was not created from
 * our request. A request is only trusted if it asked for exactly OFFERED, or for LEGACY, which is what every
 * request stored before BL-68 holds. Legacy rows keep verifying, but new builders never produce LEGACY and a
 * session returned with `card` alone for a card+link request is rejected.
 *
 * Request builders rebuild a stored request to compare it byte for byte, so they take `forStored()` of the stored
 * `params.payment_method_types`: exactly LEGACY replays the legacy shape, anything else rebuilds OFFERED and so
 * fails the comparison when the stored value is not OFFERED.
 */
final class CheckoutPaymentMethods
{
    /** @var list<string> */
    public const OFFERED = ['card', 'link'];

    /** @var list<string> */
    public const LEGACY = ['card'];

    /** @return list<string> */
    public static function offered(): array
    {
        return self::OFFERED;
    }

    /** The list a request builder must use to replay a stored request: LEGACY only on a strict `['card']` list. */
    public static function forStored(mixed $stored): array
    {
        return $stored === self::LEGACY ? self::LEGACY : self::OFFERED;
    }

    /** True when `$observed` is exactly the allowlisted set the stored `$request` asked for. */
    public static function matchesRequest(mixed $observed, array $request): bool
    {
        $requested = self::normalize($request['params']['payment_method_types'] ?? null);
        if ($requested === null || ($requested !== self::normalized(self::OFFERED) && $requested !== self::normalized(self::LEGACY))) {
            return false;
        }

        return self::normalize($observed) === $requested;
    }

    /** @return list<string> */
    private static function normalized(array $types): array
    {
        sort($types);

        return $types;
    }

    /** Sorted, duplicate-free list of strings, or null for anything else (wrong type, empty, duplicate, non-string). */
    private static function normalize(mixed $types): ?array
    {
        if (! is_array($types) || ! array_is_list($types) || $types === []) {
            return null;
        }
        foreach ($types as $type) {
            if (! is_string($type) || $type === '') {
                return null;
            }
        }
        if (count(array_unique($types)) !== count($types)) {
            return null;
        }
        sort($types);

        return $types;
    }
}
