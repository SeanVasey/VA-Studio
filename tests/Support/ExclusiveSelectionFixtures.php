<?php

namespace Tests\Support;

use App\Domain\Catalog\ActivateExclusiveOffer;
use App\Domain\Catalog\PrepareExclusiveOffer;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\RightsScope;
use Illuminate\Support\Str;

final class ExclusiveSelectionFixtures
{
    public static function policy(): array
    {
        return ['schema_version' => 1, 'purpose' => 'test_exclusive_selection', 'non_exclusive_cutoff' => 'block_while_reserved',
            'existing_pending' => 'retain_until_verified_resolution', 'discounts' => 'explicit_revision_only'];
    }

    public static function configure(): void
    {
        InventoryFixtures::configure();
        config(['commerce.test_exclusive_selection_policy' => json_encode(self::policy(), JSON_THROW_ON_ERROR)]);
    }

    public static function prepared(?RightsScope $scope = null): array
    {
        $f = ExclusiveOfferFixtures::draft($scope);
        app(ManageRightsScope::class)->link($f['scope']->id, $f['legacy']['revision']->id, 'SYNTHETIC-SIBLING-LINK', $f['actor']);
        $f['revision'] = app(PrepareExclusiveOffer::class)->handle($f['offer'], $f['scope']->id, 'SYNTHETIC-EXCLUSIVE-LINK', $f['actor']);
        $f['items'] = [['trackId' => $f['track']->id, 'offerId' => $f['offer']->id,
            'licenseVersionId' => $f['license']->id, 'offerRevisionId' => $f['revision']->id]];

        return $f;
    }

    public static function active(?RightsScope $scope = null): array
    {
        $f = self::prepared($scope);
        $f['activation'] = app(ActivateExclusiveOffer::class)->handle($f['offer'], $f['revision']->id, $f['actor']);

        return $f;
    }

    public static function quote(array $f, ?string $owner = null): Quote
    {
        return app(CreateQuote::class)->handle($owner ?? InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']);
    }
}
