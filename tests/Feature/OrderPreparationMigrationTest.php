<?php

namespace Tests\Feature;

use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\ReservePricedQuote;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures as F;
use Tests\TestCase;

class OrderPreparationMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_empty_order_tables_roundtrip_without_changing_existing_pending_inventory_and_pricing(): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure();
        $f = F::priced(false, true); $service = app(ReservePricedQuote::class);
        $held = $service->hold($f['quote']->public_id, InventoryFixtures::OWNER, 'SYNTHETIC');
        $service->beginAttempt($f['quote']->public_id, InventoryFixtures::OWNER, (string) Str::uuid());
        $quote = $f['quote']->refresh()->getAttributes(); $price = $f['pricing']->refresh()->getAttributes();
        $reservation = $held['reservation']->refresh()->getAttributes(); $promotion = $held['promotion_use']->refresh()->getAttributes();
        $audits = DB::table('audit_events')->count();
        $migration = require database_path('migrations/2026_09_24_000017_order_preparation.php');
        $checkout = require database_path('migrations/2026_09_26_000018_hosted_test_checkout.php');
        $payments = require database_path('migrations/2026_09_26_000019_test_payment_evidence.php');
        $finalizations = require database_path('migrations/2026_09_26_000020_test_order_finalization.php');
        $contracts = require database_path('migrations/2026_09_26_000021_test_contract_issuance.php');
        $fulfillmentActivations = require database_path('migrations/2026_09_26_000022_test_fulfillment_activation.php');
        $delivery = require database_path('migrations/2026_09_26_000023_test_owner_delivery.php');
        $financialObservations = require database_path('migrations/2026_10_06_000039_test_payment_financial_observations.php');
        $unpaidRelease = require database_path('migrations/2026_10_06_000042_test_unpaid_releases.php');
        $refundResolution = require database_path('migrations/2026_10_06_000047_test_refund_resolutions.php');
        $refundResolution->down();
        $unpaidRelease->down();
        $financialObservations->down();
        $exceptionOperations = require database_path('migrations/2026_10_02_000036_test_payment_exception_operations.php');
        foreach (['test_payment_exception_events', 'test_payment_exception_work'] as $table) { $this->assertDatabaseCount($table, 0); }
        foreach (['stripe_receipt_work', 'payment_observations', 'verified_payments'] as $table) { $this->assertDatabaseCount($table, 0); }
        foreach (['checkout_intents', 'checkout_sessions', 'checkout_observations'] as $table) { $this->assertDatabaseCount($table, 0); }
        $exceptionOperations->down(); $delivery->down(); $fulfillmentActivations->down(); $contracts->down(); $finalizations->down(); $payments->down(); $checkout->down();
        foreach (['orders', 'order_lines', 'order_attempts'] as $table) { $this->assertDatabaseCount($table, 0); }
        $migration->down();
        foreach (['orders', 'order_lines', 'order_attempts'] as $table) { $this->assertFalse(Schema::hasTable($table)); }
        $migration->up();
        $checkout->up(); $payments->up(); $finalizations->up(); $contracts->up(); $fulfillmentActivations->up(); $delivery->up(); $exceptionOperations->up();
        $financialObservations->up();
        $unpaidRelease->up();
        $refundResolution->up();
        foreach (['test_payment_exception_events', 'test_payment_exception_work'] as $table) { $this->assertDatabaseCount($table, 0); }
        $this->assertSame($quote, $f['quote']->refresh()->getAttributes());
        $this->assertSame($price, $f['pricing']->refresh()->getAttributes());
        $this->assertSame($reservation, $held['reservation']->refresh()->getAttributes());
        $this->assertSame($promotion, $held['promotion_use']->refresh()->getAttributes());
        $this->assertSame($audits, DB::table('audit_events')->count());
        foreach (['orders', 'order_lines', 'order_attempts'] as $table) { $this->assertDatabaseCount($table, 0); }
        // Historical pending rows stay historical. A distinct fresh quote can use the new aggregate.
        $fresh = F::priced();
        app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), F::request($fresh['quote']));
        $this->assertDatabaseCount('orders', 1); $this->assertDatabaseCount('order_lines', 1); $this->assertDatabaseCount('order_attempts', 1);
    }

    public function test_new_foreign_keys_reject_orphan_evidence_with_enforcement_enabled(): void
    {
        $this->fakePrivateMediaStorage(); F::configure(); $f = F::priced();
        $held = app(ReservePricedQuote::class)->hold($f['quote']->public_id, InventoryFixtures::OWNER);
        $rows = [
            'orders' => ['public_id' => (string) Str::uuid(), 'owner_key' => str_repeat('f', 64),
                'quote_id' => $f['quote']->id, 'quote_pricing_id' => $f['pricing']->id,
                'idempotency_key_hash' => str_repeat('a', 64), 'payload_ciphertext' => 'synthetic-nonorder',
                'payload_hash' => str_repeat('b', 64), 'canonicalization_version' => 'test', 'created_at' => now()],
            'order_lines' => ['order_id' => 999999, 'quote_line_id' => $f['quote']->lines()->sole()->id,
                'offer_revision_id' => $f['revision']->id, 'position' => 0, 'line_hash' => str_repeat('c', 64)],
            'order_attempts' => ['public_id' => (string) Str::uuid(), 'order_id' => 999999,
                'inventory_reservation_id' => $held['reservation']->id, 'promotion_use_id' => null,
                'binding' => '{}', 'binding_hash' => str_repeat('d', 64), 'canonicalization_version' => 'test',
                'created_at' => now(), 'expires_at' => now()->addMinute()],
        ];
        foreach ($rows as $table => $row) {
            try { DB::table($table)->insert($row); $this->fail('Orphan '.$table.' evidence was accepted.'); }
            catch (QueryException $error) { $this->assertStringContainsString('foreign key', strtolower($error->getMessage())); }
            $this->assertDatabaseCount($table, 0);
        }
        foreach (['orders', 'order_lines', 'order_attempts'] as $table) {
            foreach (Schema::getForeignKeys($table) as $key) { $this->assertNotSame('cascade', strtolower($key['on_delete'])); }
        }
    }
}
