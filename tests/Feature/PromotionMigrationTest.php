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
        foreach (['checkout_intents', 'checkout_sessions', 'checkout_observations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        foreach (['orders', 'order_lines', 'order_attempts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $checkout->down(); $orders->down(); $migration->down(); $migration->up(); $orders->up(); $checkout->up();
        $this->assertSame($hash, $pricing->refresh()->snapshot_hash);
        $this->assertSame($hash, CanonicalJson::hash(app(PricingSnapshot::class)->verify($pricing, $quote)));
        PromotionFixtures::configure([PromotionFixtures::policy()]);
        $next = app(CreateQuote::class)->handle($owner, (string) Str::uuid(), $quote->request);
        app(PriceQuote::class)->createWithPromotion($next->public_id, $owner, 'SYNTHETIC');
        $this->assertDatabaseCount('promotion_uses', 1);
        $this->assertDatabaseCount('quote_pricings', 2);
    }
}
