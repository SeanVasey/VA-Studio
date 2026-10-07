<?php

namespace App\Domain\Grants\Free;

final class FreeGrantInput
{
    public static function keys(array $value, array $keys): void
    {
        sort($keys);
        $actual = array_keys($value);
        sort($actual);
        FreeGrantException::require($keys === $actual, 422);
    }

    public static function uuid(mixed $value): string
    {
        FreeGrantException::require(is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $value) === 1, 422);

        return $value;
    }

    public static function hash(mixed $value): string
    {
        FreeGrantException::require(is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1, 422);

        return $value;
    }

    public static function integer(mixed $value, int $min, int $max): int
    {
        FreeGrantException::require(is_int($value) && $value >= $min && $value <= $max, 422);

        return $value;
    }

    public static function text(mixed $value, int $max): string
    {
        FreeGrantException::require(is_string($value) && $value !== '' && trim($value) === $value && strlen($value) <= $max
            && preg_match('//u', $value) === 1 && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) !== 1, 422);

        return $value;
    }
}
