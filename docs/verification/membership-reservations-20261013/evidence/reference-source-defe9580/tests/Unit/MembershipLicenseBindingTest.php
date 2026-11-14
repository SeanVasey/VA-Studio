<?php

namespace Tests\Unit;

use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipLicenseBinding;
use App\Support\Money\MinorUnits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MembershipLicenseBindingTest extends TestCase
{
    public function test_legacy_values_have_no_catalog_rule_identity(): void
    {
        $binding = $this->binding([]);
        $this->assertSame([null, null, null, null], [$binding->trackId, $binding->offerId, $binding->offerRevisionId, $binding->licenseVersionId]);
    }

    public function test_complete_bounded_catalog_identity_is_retained_exactly(): void
    {
        $ids = [1, 27, 39, MinorUnits::MAX];
        $binding = $this->binding($ids);
        $this->assertSame($ids, [$binding->trackId, $binding->offerId, $binding->offerRevisionId, $binding->licenseVersionId]);
    }

    #[DataProvider('invalidIdentities')]
    public function test_incomplete_or_unbounded_catalog_identity_is_refused(array $ids): void
    {
        $this->expectException(MembershipException::class);
        $this->binding($ids);
    }

    public static function invalidIdentities(): array
    {
        return ['partial' => [[1]], 'gap' => [[1, null, 3, 4]], 'zero' => [[0, 2, 3, 4]],
            'negative' => [[1, 2, -1, 4]], 'overflow' => [[1, 2, 3, MinorUnits::MAX + 1]]];
    }

    private function binding(array $ids): MembershipLicenseBinding
    {
        $hash = str_repeat('a', 64);

        return new MembershipLicenseBinding($hash, $hash, $hash, $hash, 1, ...$ids);
    }
}
