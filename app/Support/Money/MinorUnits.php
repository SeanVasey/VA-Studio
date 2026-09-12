<?php

namespace App\Support\Money;

use InvalidArgumentException;

/** Nonnegative, JavaScript-safe integer minor units. Currency belongs to the pricing contract. */
final class MinorUnits
{
    public const MAX = 9007199254740991;

    public static function amount(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > self::MAX) {
            throw new InvalidArgumentException('Money must be a bounded nonnegative integer.');
        }

        return $value;
    }

    public static function sum(array $values): int
    {
        $total = 0;
        foreach ($values as $value) {
            $value = self::amount($value);
            if ($value > self::MAX - $total) {
                throw new InvalidArgumentException('Money total exceeds the exact integer range.');
            }
            $total += $value;
        }

        return $total;
    }

    public static function rate(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > 10000) {
            throw new InvalidArgumentException('A test tax rate must be integer basis points from 0 to 10000.');
        }

        return $value;
    }

    /** Split before multiplying so even MAX * 10000 never overflows a 64-bit integer. */
    public static function fraction(mixed $basis, mixed $basisPoints): array
    {
        $basis = self::amount($basis);
        $rate = self::rate($basisPoints);
        $fraction = ($basis % 10000) * $rate;
        $floor = intdiv($basis, 10000) * $rate + intdiv($fraction, 10000);
        $remainder = $fraction % 10000;

        return ['floor_minor' => $floor, 'remainder' => $remainder, 'denominator' => 10000,
            'rounded_minor' => $floor + ($remainder >= 5000 ? 1 : 0),
            'ceiling_minor' => $floor + ($remainder > 0 ? 1 : 0)];
    }
}
