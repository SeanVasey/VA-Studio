<?php

namespace Tests\Unit;

use App\Support\Money\MinorUnits;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MinorUnitsTest extends TestCase
{
    public static function fractions(): array
    {
        return [
            [0, 750, 0, 0, 0], [1, 4999, 0, 4999, 0], [1, 5000, 0, 5000, 1], [3, 5000, 1, 5000, 2],
            [4999, 750, 374, 9250, 375], [2147483647, 875, 187904819, 1125, 187904819],
            [9007199254740991, 9999, 9006298534815516, 9009, 9006298534815517],
            [9007199254740991, 10000, 9007199254740991, 0, 9007199254740991],
        ];
    }

    #[DataProvider('fractions')]
    public function test_fraction_is_exact_even_when_naive_multiplication_overflows(int $basis, int $rate, int $floor, int $remainder, int $rounded): void
    {
        $this->assertSame(['floor_minor' => $floor, 'remainder' => $remainder, 'denominator' => 10000,
            'rounded_minor' => $rounded, 'ceiling_minor' => $floor + ($remainder > 0 ? 1 : 0)], MinorUnits::fraction($basis, $rate));
    }

    public static function invalidAmounts(): array
    {
        return [[-1], [9007199254740992], [1.0], ['100'], ['1e3'], [true], [null], [[]]];
    }

    #[DataProvider('invalidAmounts')]
    public function test_amount_never_coerces_or_truncates(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        MinorUnits::amount($value);
    }

    public function test_sum_checks_the_bound_before_adding(): void
    {
        $this->assertSame(MinorUnits::MAX, MinorUnits::sum([MinorUnits::MAX - 1, 1, 0]));
        $this->expectException(InvalidArgumentException::class);
        MinorUnits::sum([MinorUnits::MAX, 1]);
    }

    public static function invalidRates(): array
    {
        return [[-1], [10001], ['750'], [750.0], [null]];
    }

    #[DataProvider('invalidRates')]
    public function test_rate_is_explicit_integer_basis_points(mixed $rate): void
    {
        $this->expectException(InvalidArgumentException::class);
        MinorUnits::fraction(1000, $rate);
    }
}
