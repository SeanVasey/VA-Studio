<?php

namespace App\Domain\Commerce\Orders;

use App\Domain\Commerce\QuoteException;
use App\Support\Environment\TestEnvironment;
use JsonException;

/** Explicit nonbinding development policy; never a default legal or merchant policy. */
final class OrderPolicy
{
    public function current(): array
    {
        if (! TestEnvironment::admitsTestCommerce()) {
            throw new QuoteException('ORDER_POLICY_UNAVAILABLE', 503);
        }
        $raw = config('commerce.test_order_policy');
        try {
            $policy = is_string($raw) && strlen($raw) <= 8192 ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $policy = null;
        }
        if (! is_array($policy)) { throw new QuoteException('ORDER_POLICY_UNAVAILABLE', 503); }

        return self::validate($policy);
    }

    public function enabled(): bool
    {
        try { $this->current(); return true; }
        catch (QuoteException) { return false; }
    }

    /** Historical validation deliberately does not consult current configuration or environment. */
    public static function validate(array $policy): array
    {
        if (! self::keys($policy, ['schema_version', 'purpose', 'version', 'seller', 'assent', 'buyer_identity']) ||
            $policy['schema_version'] !== 1 || $policy['purpose'] !== 'test_order_preparation' ||
            $policy['buyer_identity'] !== 'unverified_guest' || ! self::version($policy['version']) ||
            ! is_array($policy['seller']) || ! self::keys($policy['seller'], ['legal_name']) ||
            ! self::text($policy['seller']['legal_name'], 160, false) ||
            ! is_array($policy['assent']) || ! self::keys($policy['assent'], ['version', 'text']) ||
            ! self::version($policy['assent']['version']) || ! self::text($policy['assent']['text'], 4000, true)) {
            throw new QuoteException('ORDER_POLICY_UNAVAILABLE', 503);
        }

        return $policy;
    }

    public static function keys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && array_diff(array_keys($value), $keys) === [];
    }

    private static function version(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,79}\z/D', $value) === 1;
    }

    private static function text(mixed $value, int $limit, bool $multiline): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && trim($value) !== '' &&
            mb_strlen($value) <= $limit && preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u' : '/[\p{C}]/u', $value) === 0;
    }
}
