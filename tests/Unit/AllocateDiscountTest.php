<?php

namespace Tests\Unit;

use App\Support\Money\AllocateDiscount;
use App\Support\Money\MinorUnits;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AllocateDiscountTest extends TestCase
{
    public static function allocations(): array
    {
        return [
            'equal remainders' => [[1, 1, 1], 2, [1, 1, 0], [2, 2, 2]],
            'proportional' => [[100, 200, 300], 101, [17, 34, 50], [500, 400, 300]],
            'uneven prices' => [[4999, 9999], 500, [167, 333], [9832, 5166]],
            'maximum product' => [[4503599627370495, 4503599627370496], 9007199254740990,
                [4503599627370495, 4503599627370495], [4503599627370496, 4503599627370495]],
            'tiny second weight' => [[9007199254740982, 9], 3002399751580330, [3002399751580327, 3], [3, 9007199254740988]],
            'full amount' => [[1, 5, 9], 15, [1, 5, 9], [0, 0, 0]],
            'zero amount' => [[1, 5, 9], 0, [0, 0, 0], [0, 0, 0]],
            'one line at maximum' => [[MinorUnits::MAX], MinorUnits::MAX, [MinorUnits::MAX], [0]],
        ];
    }

    #[DataProvider('allocations')]
    public function test_exact_allocations_survive_reordering_and_large_intermediate_products(array $weights, int $discount, array $expected, array $remainders): void
    {
        $lines = [];
        foreach ($weights as $index => $weight) { $lines[] = ['offer_revision_id' => $index + 1, 'base_minor' => $weight]; }
        $result = (new AllocateDiscount)->handle($lines, $discount);
        $this->assertSame($expected, array_column($result['allocations'], 'discount_minor'));
        $this->assertSame($remainders, array_column($result['allocations'], 'remainder'));
        $this->assertSame($discount, array_sum($expected));
        $this->assertSame($result, (new AllocateDiscount)->handle(array_reverse($lines), $discount));
        foreach ($result['allocations'] as $line) {
            $this->assertLessThanOrEqual($line['base_minor'], $line['discount_minor']);
            $this->assertSame($line['floor_minor'] + $line['extra_minor'], $line['discount_minor']);
        }
    }

    public static function invalid(): array
    {
        $line = ['offer_revision_id' => 1, 'base_minor' => 10];
        return [[[], 1], [[$line], 11], [[$line], '1'], [[$line], 1.0], [[$line], -1],
            [[$line, $line], 1], [[['offer_revision_id' => 0, 'base_minor' => 10]], 1],
            [[['offer_revision_id' => 1, 'base_minor' => 0]], 0],
            [[['offer_revision_id' => 1, 'base_minor' => MinorUnits::MAX], ['offer_revision_id' => 2, 'base_minor' => 1]], 1]];
    }

    #[DataProvider('invalid')]
    public function test_rejects_invalid_or_excessive_allocations(array $lines, mixed $discount): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AllocateDiscount)->handle($lines, $discount);
    }
}
