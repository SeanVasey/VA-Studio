<?php

namespace App\Domain\Grants\PaidTax;

final class PaidTaxInput
{
    public static function uuid(mixed $value): string
    {
        PaidTaxException::require(is_string($value) && preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $value) === 1, 422);

        return $value;
    }

    public static function hash(mixed $value): string
    {
        PaidTaxException::require(is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1, 422);

        return $value;
    }

    public static function keys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        PaidTaxException::require($keys === $expected, 422);
    }
}
