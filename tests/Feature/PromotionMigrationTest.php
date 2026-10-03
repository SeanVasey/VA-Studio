<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PricingSnapshot;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\Support\PromotionFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class PromotionMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_empty_new_tables_can_roundtrip_without_rewriting_earlier_quote_or_pricing_evidence(): void
    {
        $this->fakePrivateMediaStorage();
        config(['commerce.test_pricing_policy' => null]);
        $owner = str_repeat('a', 64);
        $quote = app(CreateQuote::class)->handle($owner, (string) Str::uuid(), QuoteFixtures::selection()['items']);
        $pricing = app(PriceQuote::class)->create($quote->public_id, $owner);
        $hash = $pricing->snapshot_hash;
        $this->assertDatabaseCount('promotion_uses', 0);
        $this->assertDatabaseCount('promotion_campaigns', 0);
        $migration = require database_path('migrations/2026_09_14_000014_promotion_usage.php');
        $orders = require database_path('migrations/2026_09_24_000017_order_preparation.php');
        $checkout = require database_path('migrations/2026_09_26_000018_hosted_test_checkout.php');
        $payments = require database_path('migrations/2026_09_26_000019_test_payment_evidence.php');
        $finalizations = require database_path('migrations/2026_09_26_000020_test_order_finalization.php');
        $contracts = require database_path('migrations/2026_09_26_000021_test_contract_issuance.php');
        $fulfillmentActivations = require database_path('migrations/2026_09_26_000022_test_fulfillment_activation.php');
        $delivery = require database_path('migrations/2026_09_26_000023_test_owner_delivery.php');
        $administration = require database_path('migrations/2026_09_29_000025_promotion_administration.php');
        $exceptionOperations = require database_path('migrations/2026_10_02_000036_test_payment_exception_operations.php');
        foreach (['test_payment_exception_events', 'test_payment_exception_work'] as $table) { $this->assertDatabaseCount($table, 0); }
        foreach (['stripe_receipt_work', 'payment_observations', 'verified_payments'] as $table) { $this->assertDatabaseCount($table, 0); }
        foreach (['checkout_intents', 'checkout_sessions', 'checkout_observations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        foreach (['orders', 'order_lines', 'order_attempts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $exceptionOperations->down(); $administration->down(); $delivery->down(); $fulfillmentActivations->down(); $contracts->down(); $finalizations->down(); $payments->down(); $checkout->down(); $orders->down(); $migration->down(); $migration->up(); $orders->up(); $checkout->up(); $payments->up(); $finalizations->up(); $contracts->up(); $fulfillmentActivations->up(); $delivery->up(); $administration->up(); $exceptionOperations->up();
        foreach (['test_payment_exception_events', 'test_payment_exception_work'] as $table) { $this->assertDatabaseCount($table, 0); }
        $this->assertSame($hash, $pricing->refresh()->snapshot_hash);
        $this->assertSame($hash, CanonicalJson::hash(app(PricingSnapshot::class)->verify($pricing, $quote)));
        PromotionFixtures::configure([PromotionFixtures::policy()]);
        $next = app(CreateQuote::class)->handle($owner, (string) Str::uuid(), $quote->request);
        app(PriceQuote::class)->createWithPromotion($next->public_id, $owner, 'SYNTHETIC');
        $this->assertDatabaseCount('promotion_uses', 1);
        $this->assertDatabaseCount('quote_pricings', 2);
    }
}
