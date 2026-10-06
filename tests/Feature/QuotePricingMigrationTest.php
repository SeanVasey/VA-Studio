<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\PriceQuote;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PricingFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class QuotePricingMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

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
        $payments = require database_path('migrations/2026_09_26_000019_test_payment_evidence.php');
        $finalizations = require database_path('migrations/2026_09_26_000020_test_order_finalization.php');
        $contracts = require database_path('migrations/2026_09_26_000021_test_contract_issuance.php');
        $fulfillmentActivations = require database_path('migrations/2026_09_26_000022_test_fulfillment_activation.php');
        $delivery = require database_path('migrations/2026_09_26_000023_test_owner_delivery.php');
        $administration = require database_path('migrations/2026_09_29_000025_promotion_administration.php');
        $financialObservations = require database_path('migrations/2026_10_06_000039_test_payment_financial_observations.php');
        $unpaidRelease = require database_path('migrations/2026_10_06_000042_test_unpaid_releases.php');
        $refundResolution = require database_path('migrations/2026_10_06_000047_test_refund_resolutions.php');
        $refundResolution->down();
        $unpaidRelease->down();
        $financialObservations->down();
        $exceptionOperations = require database_path('migrations/2026_10_02_000036_test_payment_exception_operations.php');
        foreach (['test_payment_exception_events', 'test_payment_exception_work'] as $table) { $this->assertDatabaseCount($table, 0); }
        foreach (['stripe_receipt_work', 'payment_observations', 'verified_payments'] as $table) { $this->assertDatabaseCount($table, 0); }
        // Reverse dependency order, as the migrator does; retain foreign-key enforcement.
        foreach (['checkout_intents', 'checkout_sessions', 'checkout_observations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $exceptionOperations->down(); $delivery->down(); $fulfillmentActivations->down(); $contracts->down(); $finalizations->down(); $payments->down(); $checkout->down();
        foreach (['orders', 'order_lines', 'order_attempts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $orders->down();
        $this->assertDatabaseCount('promotion_uses', 0);
        $this->assertDatabaseCount('promotion_campaigns', 0);
        $administration->down(); $promotions->down();
        $this->assertDatabaseCount('quote_pricings', 0); // Down is exercised only on empty, disposable new evidence.
        $migration->down();
        $this->assertFalse(Schema::hasTable('quote_pricings'));
        $this->assertSame($before, CanonicalJson::encode($quote->refresh()->snapshot));
        $migration->up();
        $promotions->up();
        $orders->up();
        $checkout->up(); $payments->up(); $finalizations->up(); $contracts->up(); $fulfillmentActivations->up(); $delivery->up(); $administration->up(); $exceptionOperations->up();
        $financialObservations->up();
        $unpaidRelease->up();
        $refundResolution->up();
        foreach (['test_payment_exception_events', 'test_payment_exception_work'] as $table) { $this->assertDatabaseCount($table, 0); }
        $pricing = app(PriceQuote::class)->create($quote->public_id, str_repeat('a', 64));
        $this->assertSame($quote->snapshot_hash, $pricing->snapshot['quote_snapshot_hash']);
        $this->assertSame($before, CanonicalJson::encode($quote->refresh()->snapshot));
        $this->assertDatabaseCount('quote_pricings', 1);
    }
}
