<?php

namespace Tests\Unit;

use App\Domain\Commerce\ProductionPreparation\PreparationSelection;
use App\Support\Money\MinorUnits;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductionTrackPreparationSnapshotTest extends TestCase
{
    public function test_integer_advertised_subtotal_exact_boundary_and_overflow(): void
    {
        $this->assertSame(MinorUnits::MAX, PreparationSelection::subtotal([MinorUnits::MAX - 1, 1]));
        $this->expectException(InvalidArgumentException::class);
        PreparationSelection::subtotal([MinorUnits::MAX, 1]);
    }

    public static function malformed(): array
    {
        $item = ['trackId' => 1, 'offerId' => 2, 'licenseVersionId' => 3, 'offerRevisionId' => 4];

        return [['empty', []], ['non-list', ['line' => $item]], ['numeric string', [array_replace($item, ['trackId' => '1'])]],
            ['float', [array_replace($item, ['trackId' => 1.0])]], ['zero', [array_replace($item, ['trackId' => 0])]],
            ['extra total', [$item + ['total' => 1]]], ['duplicate track', [$item, $item]], ['unbounded id', [array_replace($item, ['trackId' => MinorUnits::MAX + 1])]]];
    }

    #[DataProvider('malformed')]
    public function test_closed_request_requires_distinct_exact_bounded_integer_identities(string $label, array $items): void
    {
        $this->expectException(ValidationException::class);
        PreparationSelection::items($items);
    }

    public function test_track_order_is_normalized_without_changing_selected_identities(): void
    {
        $a = ['trackId' => 9, 'offerId' => 2, 'licenseVersionId' => 3, 'offerRevisionId' => 4];
        $b = ['trackId' => 1, 'offerId' => 8, 'licenseVersionId' => 7, 'offerRevisionId' => 6];
        $this->assertSame([$b, $a], PreparationSelection::items([$a, $b]));
    }
}
