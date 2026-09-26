<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\PriceQuote;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PricingFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class QuotePricingMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_additive_migration_roundtrip_keeps_existing_quote_evidence(): void
    {
        $this->fakePrivateMediaStorage();
        PricingFixtures::configure(null);
        $quote = app(CreateQuote::class)->handle(str_repeat('a', 64), 'migration-evidence', QuoteFixtures::selection()['items']);
        $before = CanonicalJson::encode($quote->snapshot);
        $migration = require database_path('migrations/2026_09_12_000013_quote_pricing.php');
        $promotions = require database_path('migrations/2026_09_14_000014_promotion_usage.php');
        $orders = require database_path('migrations/2026_09_24_000017_order_preparation.php');
        $checkout = require database_path('migrations/2026_09_26_000018_hosted_test_checkout.php');
        // Reverse dependency order, as the migrator does; retain foreign-key enforcement.
        foreach (['checkout_intents', 'checkout_sessions', 'checkout_observations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $checkout->down();
        foreach (['orders', 'order_lines', 'order_attempts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $orders->down();
        $this->assertDatabaseCount('promotion_uses', 0);
        $this->assertDatabaseCount('promotion_campaigns', 0);
        $promotions->down();
        $this->assertDatabaseCount('quote_pricings', 0); // Down is exercised only on empty, disposable new evidence.
        $migration->down();
        $this->assertFalse(Schema::hasTable('quote_pricings'));
        $this->assertSame($before, CanonicalJson::encode($quote->refresh()->snapshot));
        $migration->up();
        $promotions->up();
        $orders->up();
        $checkout->up();
        $pricing = app(PriceQuote::class)->create($quote->public_id, str_repeat('a', 64));
        $this->assertSame($quote->snapshot_hash, $pricing->snapshot['quote_snapshot_hash']);
        $this->assertSame($before, CanonicalJson::encode($quote->refresh()->snapshot));
        $this->assertDatabaseCount('quote_pricings', 1);
    }
}
