<?php

namespace App\Domain\Grants\ProductionFree;

use Carbon\CarbonImmutable;

/** Exact typed command input. Unknown, missing or loosely typed fields are refused, never coerced. */
final class ProductionFreeGrantInput
{
    public const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D';

    public static function keys(array $input, array $keys): void
    {
        $actual = array_keys($input);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        ProductionFreeGrantException::require(! array_is_list($input) && $actual === $keys, 'invalid_input');
    }

    public static function uuid(mixed $value): string
    {
        ProductionFreeGrantException::require(is_string($value) && preg_match(self::UUID, $value) === 1, 'invalid_input');

        return $value;
    }

    public static function hash(mixed $value): string
    {
        ProductionFreeGrantException::require(is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1, 'invalid_input');

        return $value;
    }

    /** Visible UTF-8 text with ordinary line breaks; no control, format or private-use characters. */
    public static function text(mixed $value, int $max, bool $multiline = false): string
    {
        ProductionFreeGrantException::require(is_string($value) && $value !== '' && strlen($value) <= $max && mb_check_encoding($value, 'UTF-8')
            && trim($value) === $value
            && preg_match($multiline ? '/[\x00-\x09\x0B-\x1F\x7F-\x9F]/u' : '/[\x00-\x1F\x7F-\x9F]/u', $value) === 0
            && preg_match('/[\p{Cf}\p{Co}\p{Cs}\p{Cn}]/u', $value) === 0, 'invalid_input');

        return $value;
    }

    public static function integer(mixed $value, int $min, int $max): int
    {
        ProductionFreeGrantException::require(is_int($value) && $value >= $min && $value <= $max, 'invalid_input');

        return $value;
    }

    /** One clock reading per command, truncated to the stored second. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfSecond();
    }

    public static function stored(CarbonImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }

    public static function iso(CarbonImmutable $at): string
    {
        return $at->format('Y-m-d\TH:i:s\Z');
    }

    public static function parse(string $stored): CarbonImmutable
    {
        $at = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $stored, 'UTC');
        ProductionFreeGrantException::require($at !== false && $at->format('Y-m-d H:i:s') === $stored, 'tampered');

        return $at;
    }
}
