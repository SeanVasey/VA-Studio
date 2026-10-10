<?php

namespace Tests\Feature;

use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\CreateQuote;
use Illuminate\Support\Str;
use Tests\Support\CapabilityRollbackFixture;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures as F;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class SharedInventoryMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_empty_inventory_tables_roundtrip_without_changing_quotes(): void
    {
        $this->fakePrivateMediaStorage(); F::configure();
        $prior = app(CreateQuote::class)->handle(F::OWNER, (string) Str::uuid(), QuoteFixtures::selection()['items']);
        $priorHash = $prior->snapshot_hash;
        $migration = require database_path('migrations/2026_09_17_000015_shared_rights_inventory.php');
        $activations = require database_path('migrations/2026_09_23_000016_exclusive_activations.php');
        $orders = require database_path('migrations/2026_09_24_000017_order_preparation.php');
        $checkout = require database_path('migrations/2026_09_26_000018_hosted_test_checkout.php');
        $payments = require database_path('migrations/2026_09_26_000019_test_payment_evidence.php');
        $finalizations = require database_path('migrations/2026_09_26_000020_test_order_finalization.php');
        $contracts = require database_path('migrations/2026_09_26_000021_test_contract_issuance.php');
        $fulfillmentActivations = require database_path('migrations/2026_09_26_000022_test_fulfillment_activation.php');
        $delivery = require database_path('migrations/2026_09_26_000023_test_owner_delivery.php');
        $financialObservations = require database_path('migrations/2026_10_06_000039_test_payment_financial_observations.php');
        $unpaidRelease = require database_path('migrations/2026_10_06_000042_test_unpaid_releases.php');
        $purchaseClaims = require database_path('migrations/2026_10_06_000048_customer_purchase_claims.php');
        $refundResolution = require database_path('migrations/2026_10_06_000047_test_refund_resolutions.php');
        $orderInquiries = require database_path('migrations/2026_10_06_000049_inquiry_order_contexts.php');
        $orderInquiries->down();
        // Remove the empty additive notification child before its retained parents.
        $notices = require database_path('migrations/2026_10_06_231000_test_transactional_notifications.php');
        $this->assertDatabaseCount('transactional_notices', 0);
        $this->assertDatabaseCount('transactional_notice_attempts', 0);
        $notices->down();
        $purchaseClaims->down();
        $refundResolution->down();
        $unpaidRelease->down();
        $financialObservations->down();
        $exceptionOperations = require database_path('migrations/2026_10_02_000036_test_payment_exception_operations.php');
        foreach (['test_payment_exception_events', 'test_payment_exception_work'] as $table) { $this->assertDatabaseCount($table, 0); }
        foreach (['stripe_receipt_work', 'payment_observations', 'verified_payments'] as $table) { $this->assertDatabaseCount($table, 0); }
        $this->assertDatabaseCount('exclusive_activations', 0);
        foreach (['checkout_intents', 'checkout_sessions', 'checkout_observations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        foreach (['orders', 'order_lines', 'order_attempts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        // free_definitions (245000) holds a RESTRICT foreign key to rights_scopes, and 245000 refuses
        // operational rollback, so native MySQL refuses the 000015 rollback below (SQLSTATE 3730). Dispose
        // of the verified-empty free-grant tables from the live catalog, leaves first, with foreign keys
        // still enforced.
        $freeGrants = ['free_definitions', ...CapabilityRollbackFixture::dependents(['free_definitions'])];
        CapabilityRollbackFixture::dropEmptyLeavesFirst($freeGrants);
        $exceptionOperations->down(); $delivery->down(); $fulfillmentActivations->down(); $contracts->down(); $finalizations->down(); $payments->down(); $checkout->down(); $orders->down(); $activations->down(); $migration->down(); $migration->up(); $activations->up(); $orders->up(); $checkout->up(); $payments->up(); $finalizations->up(); $contracts->up(); $fulfillmentActivations->up(); $delivery->up(); $exceptionOperations->up();
        $financialObservations->up();
        $unpaidRelease->up();
        $refundResolution->up();
        $purchaseClaims->up();
        $notices->up();
        $orderInquiries->up();
        foreach (['test_payment_exception_events', 'test_payment_exception_work'] as $table) { $this->assertDatabaseCount($table, 0); }
        $this->assertSame($priorHash, $prior->refresh()->snapshot_hash);
        $f = F::selection(); $hash = $f['quote']->snapshot_hash;
        app(ReserveQuoteInventory::class)->hold($f['quote']->public_id, F::OWNER);
        $this->assertSame($hash, $f['quote']->refresh()->snapshot_hash);
        $this->assertDatabaseCount('inventory_claims', 1);
    }
}
