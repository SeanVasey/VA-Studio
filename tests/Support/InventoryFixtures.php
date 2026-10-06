<?php

namespace Tests\Support;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Media\MalwareScanner;
use Illuminate\Support\Str;

final class InventoryFixtures
{
    public const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public static function policy(int $ttl = 60): array
    {
        return ['schema_version' => 1, 'purpose' => 'test_inventory', 'ttl_seconds' => $ttl,
            'pending' => 'retain_until_verified_resolution'];
    }

    public static function configure(int $ttl = 60): void
    {
        config(['commerce.test_inventory_policy' => json_encode(self::policy($ttl), JSON_THROW_ON_ERROR)]);
    }

    public static function selection(?RightsScope $scope = null, ?MalwareScanner $scanner = null): array
    {
        $selection = QuoteFixtures::selection(scanner: $scanner);
        $manage = app(ManageRightsScope::class);
        $scope ??= $manage->register('fixture-'.Str::uuid(), 'SYNTHETIC-SCOPE', $selection['actor']);
        $link = $manage->link($scope->id, $selection['revision']->id, 'SYNTHETIC-LINK', $selection['actor']);
        $quote = app(CreateQuote::class)->handle(self::OWNER, (string) Str::uuid(), $selection['items']);

        return $selection + compact('scope', 'link', 'quote');
    }
}
