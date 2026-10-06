<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ExclusiveSelectionFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ExclusiveActivationMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_disposable_empty_activation_migration_retains_prior_quotes_and_links(): void
    {
        $this->fakePrivateMediaStorage(); F::configure();
        $prior = app(CreateQuote::class)->handle(InventoryFixtures::OWNER, 'prior', QuoteFixtures::selection()['items']);
        $hash = $prior->snapshot_hash; $f = F::prepared(); $revisionHash = $f['revision']->snapshot_hash;
        $migration = require database_path('migrations/2026_09_23_000016_exclusive_activations.php');
        $this->assertDatabaseCount('exclusive_activations', 0); $migration->down();
        $this->assertFalse(Schema::hasTable('exclusive_activations')); $migration->up();
        $this->assertSame($hash, $prior->refresh()->snapshot_hash);
        $this->assertSame($revisionHash, $f['revision']->refresh()->snapshot_hash);
        app(\App\Domain\Catalog\ActivateExclusiveOffer::class)->handle($f['offer'], $f['revision']->id, $f['actor']);
        $this->assertDatabaseCount('exclusive_activations', 1); $this->assertDatabaseCount('rights_scope_offers', 2);
    }
}
