<?php

namespace Tests\Feature;

use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\CreateQuote;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\Support\InventoryFixtures as F;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class SharedInventoryMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_empty_inventory_tables_roundtrip_without_changing_quotes(): void
    {
        $this->fakePrivateMediaStorage(); F::configure();
        $prior = app(CreateQuote::class)->handle(F::OWNER, (string) Str::uuid(), QuoteFixtures::selection()['items']);
        $priorHash = $prior->snapshot_hash;
        $migration = require database_path('migrations/2026_09_17_000015_shared_rights_inventory.php');
        $migration->down(); $migration->up();
        $this->assertSame($priorHash, $prior->refresh()->snapshot_hash);
        $f = F::selection(); $hash = $f['quote']->snapshot_hash;
        app(ReserveQuoteInventory::class)->hold($f['quote']->public_id, F::OWNER);
        $this->assertSame($hash, $f['quote']->refresh()->snapshot_hash);
        $this->assertDatabaseCount('inventory_claims', 1);
    }
}
