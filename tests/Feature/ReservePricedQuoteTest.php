<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PromotionUsage;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\ReservePricedQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InventoryFixtures as F;
use Tests\Support\PricingFixtures;
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class ReservePricedQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure();
        PricingFixtures::configure(PricingFixtures::policy());
        PromotionFixtures::configure([PromotionFixtures::policy()]);
    }

    private function rejected(string $code, callable $operation): void
    {
        try { $operation(); $this->fail('Expected '.$code); }
        catch (QuoteException $error) { $this->assertSame($code, $error->errorCode); }
    }

    public static function modes(): array { return [[null], ['SYNTHETIC']]; }

    #[DataProvider('modes')]
    public function test_hold_and_attempt_replay_preserve_exact_evidence_and_audits(?string $code): void
    {
        $f = F::selection(); $service = app(ReservePricedQuote::class); $hash = $f['quote']->snapshot_hash;
        $held = $service->hold($f['quote']->public_id, F::OWNER, $code);
        $again = $service->hold($f['quote']->public_id, F::OWNER, $code);
        foreach (['pricing', 'reservation'] as $key) {
            $this->assertSame($held[$key]->id, $again[$key]->id);
            $this->assertSame($held[$key]->snapshot_hash, $again[$key]->snapshot_hash);
        }
        $attempt = (string) Str::uuid();
        $pending = $service->beginAttempt($f['quote']->public_id, F::OWNER, $attempt);
        $replay = $service->beginAttempt($f['quote']->public_id, F::OWNER, $attempt);
        $this->assertSame($pending['reservation']->id, $replay['reservation']->id);
        $this->assertSame('pending', $pending['reservation']->state);
        $this->assertSame($attempt, $pending['reservation']->attempt_id);
        $this->assertSame($code === null ? null : $attempt, $pending['promotion_use']?->attempt_id);
        $this->assertSame($hash, $f['quote']->refresh()->snapshot_hash);
        $this->assertFalse($pending['pricing']->snapshot['payable']);
        foreach (['commerce.quote.priced', 'commerce.inventory.held', 'commerce.inventory.pending'] as $action) {
            $this->assertSame(1, DB::table('audit_events')->where('action', $action)->count());
        }
        $this->assertSame($code === null ? 0 : 1, DB::table('audit_events')->where('action', 'commerce.promotion.pending')->count());
        $this->rejected($code === null ? 'INVENTORY_ATTEMPT_CONFLICT' : 'PROMOTION_ATTEMPT_CONFLICT',
            fn () => $service->beginAttempt($f['quote']->public_id, F::OWNER, (string) Str::uuid()));
    }

    public function test_unavailable_inventory_rolls_back_new_price_campaign_use_and_audits(): void
    {
        $a = F::selection(); $b = F::selection($a['scope']);
        app(ReserveQuoteInventory::class)->hold($a['quote']->public_id, F::OWNER);
        $before = DB::table('audit_events')->count();
        $this->rejected('INVENTORY_UNAVAILABLE', fn () => app(ReservePricedQuote::class)->hold($b['quote']->public_id, F::OWNER, 'SYNTHETIC'));
        foreach (['quote_pricings', 'promotion_campaigns', 'promotion_uses'] as $table) { $this->assertDatabaseCount($table, 0); }
        $this->assertDatabaseCount('inventory_reservations', 1); $this->assertDatabaseCount('inventory_claims', 1);
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    public function test_existing_price_and_promotion_hold_survive_failed_inventory_acquisition(): void
    {
        $a = F::selection(); $b = F::selection($a['scope']);
        $pricing = app(PriceQuote::class)->createWithPromotion($b['quote']->public_id, F::OWNER, 'SYNTHETIC');
        app(ReserveQuoteInventory::class)->hold($a['quote']->public_id, F::OWNER);
        $this->rejected('INVENTORY_UNAVAILABLE', fn () => app(ReservePricedQuote::class)->hold($b['quote']->public_id, F::OWNER, 'SYNTHETIC'));
        $this->assertDatabaseCount('quote_pricings', 1);
        $this->assertDatabaseHas('promotion_uses', ['quote_pricing_id' => $pricing->id, 'state' => 'held', 'attempt_id' => null]);
    }

    public function test_exhausted_promotion_does_not_acquire_inventory_or_reprice_a_quote(): void
    {
        PromotionFixtures::configure([PromotionFixtures::policy(['max_uses' => 1])]);
        $a = F::selection(); $b = F::selection(); $service = app(ReservePricedQuote::class);
        $service->hold($a['quote']->public_id, F::OWNER, 'SYNTHETIC');
        $this->rejected('PROMOTION_LIMIT_REACHED', fn () => $service->hold($b['quote']->public_id, F::OWNER, 'SYNTHETIC'));
        $this->assertDatabaseCount('quote_pricings', 1); $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('promotion_uses', 1);
        $this->rejected('PRICING_CHANGED', fn () => $service->hold($a['quote']->public_id, F::OWNER));
    }

    public static function attemptFailures(): array
    {
        return [['blocked', 'INVENTORY_BLOCKED'], ['expired', 'INVENTORY_EXPIRED'],
            ['missing', 'INVENTORY_NOT_FOUND'], ['conflict', 'INVENTORY_ATTEMPT_CONFLICT'],
            ['policy', 'INVENTORY_CHANGED']];
    }

    #[DataProvider('attemptFailures')]
    public function test_inventory_failure_rolls_back_promotion_pending_transition(string $scenario, string $error): void
    {
        $f = F::selection(); $service = app(ReservePricedQuote::class);
        if ($scenario === 'missing') { app(PriceQuote::class)->createWithPromotion($f['quote']->public_id, F::OWNER, 'SYNTHETIC'); }
        else { $held = $service->hold($f['quote']->public_id, F::OWNER, 'SYNTHETIC'); }
        if ($scenario === 'blocked') { app(ManageRightsScope::class)->block($f['scope']->id, true, 0, 'TEST-BLOCK', $f['actor']); }
        if ($scenario === 'expired') { $this->travelTo($held['reservation']->expires_at); }
        if ($scenario === 'conflict') { app(ReserveQuoteInventory::class)->beginAttempt($f['quote']->public_id, F::OWNER, (string) Str::uuid()); }
        if ($scenario === 'policy') { F::configure(90); }
        $before = DB::table('audit_events')->count();
        $this->rejected($error, fn () => $service->beginAttempt($f['quote']->public_id, F::OWNER, (string) Str::uuid()));
        $this->assertDatabaseHas('promotion_uses', ['state' => 'held', 'attempt_id' => null, 'pending_at' => null]);
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    public function test_partial_legacy_attempt_cannot_be_presented_as_a_complete_hold(): void
    {
        $f = F::selection(); $service = app(ReservePricedQuote::class);
        $service->hold($f['quote']->public_id, F::OWNER, 'SYNTHETIC');
        app(PromotionUsage::class)->beginAttempt($f['quote']->public_id, F::OWNER, (string) Str::uuid());
        $this->rejected('PRICED_INVENTORY_CHANGED', fn () => $service->hold($f['quote']->public_id, F::OWNER, 'SYNTHETIC'));
        $this->assertDatabaseHas('inventory_reservations', ['state' => 'held', 'attempt_id' => null]);
    }

    public function test_cross_quote_attempt_reuse_rolls_back_both_resources(): void
    {
        $a = F::selection(); $b = F::selection(); $service = app(ReservePricedQuote::class); $attempt = (string) Str::uuid();
        $service->hold($a['quote']->public_id, F::OWNER); // No promotion on the first quote.
        $second = $service->hold($b['quote']->public_id, F::OWNER, 'SYNTHETIC');
        $service->beginAttempt($a['quote']->public_id, F::OWNER, $attempt);
        $this->rejected('INVENTORY_ATTEMPT_CONFLICT', fn () => $service->beginAttempt($b['quote']->public_id, F::OWNER, $attempt));
        $this->assertSame('held', $second['reservation']->refresh()->state);
        $this->assertSame('held', $second['promotion_use']->refresh()->state);
        $this->assertNull($second['promotion_use']->attempt_id);
    }

    public function test_pending_resources_remain_occupied_after_inventory_ttl(): void
    {
        PromotionFixtures::configure([PromotionFixtures::policy(['max_uses' => 1])]);
        $a = F::selection(); $b = F::selection($a['scope']); $service = app(ReservePricedQuote::class); $attempt = (string) Str::uuid();
        $held = $service->hold($a['quote']->public_id, F::OWNER, 'SYNTHETIC');
        $service->beginAttempt($a['quote']->public_id, F::OWNER, $attempt);
        $this->travelTo($held['reservation']->expires_at->addSecond());
        $service->beginAttempt($a['quote']->public_id, F::OWNER, $attempt);
        $this->rejected('PROMOTION_LIMIT_REACHED', fn () => $service->hold($b['quote']->public_id, F::OWNER, 'SYNTHETIC'));
        $this->rejected('INVENTORY_UNAVAILABLE', fn () => $service->hold($b['quote']->public_id, F::OWNER));
        $this->assertSame('pending', $held['reservation']->refresh()->state);
        $this->assertSame('pending', $held['promotion_use']->refresh()->state);
    }

    public function test_owner_environment_and_explicit_policy_are_required_before_any_effect(): void
    {
        $f = F::selection(); $service = app(ReservePricedQuote::class);
        $this->rejected('QUOTE_NOT_FOUND', fn () => $service->hold($f['quote']->public_id, str_repeat('b', 64), 'SYNTHETIC'));
        $this->rejected('INVALID_QUOTE_REQUEST', fn () => $service->beginAttempt($f['quote']->public_id, F::OWNER, 'invalid'));
        config(['commerce.test_inventory_policy' => null]);
        $this->rejected('INVENTORY_POLICY_UNAVAILABLE', fn () => $service->hold($f['quote']->public_id, F::OWNER, 'SYNTHETIC'));
        F::configure(); $this->app->instance('env', 'production');
        $this->rejected('INVENTORY_UNAVAILABLE', fn () => $service->hold($f['quote']->public_id, F::OWNER));
        $this->rejected('INVENTORY_UNAVAILABLE', fn () => $service->beginAttempt($f['quote']->public_id, F::OWNER, (string) Str::uuid()));
        $this->assertDatabaseCount('quote_pricings', 0); $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('promotion_uses', 0);
    }

    public function test_multi_scope_failure_releases_no_prior_evidence_and_leaves_no_partial_claims(): void
    {
        $a = F::selection(); $b = F::selection();
        app(ReserveQuoteInventory::class)->hold($b['quote']->public_id, F::OWNER);
        $quote = app(CreateQuote::class)->handle(F::OWNER, (string) Str::uuid(), [...$b['items'], ...$a['items']]);
        $this->rejected('INVENTORY_UNAVAILABLE', fn () => app(ReservePricedQuote::class)->hold($quote->public_id, F::OWNER, 'SYNTHETIC'));
        $this->assertDatabaseCount('inventory_claims', 1); $this->assertDatabaseCount('quote_pricings', 0);
        $this->assertDatabaseCount('promotion_uses', 0);
    }

    public static function operations(): array { return [['hold'], ['attempt']]; }

    #[DataProvider('operations')]
    public function test_price_expiry_during_inventory_wait_rolls_back_the_entire_operation(string $operation): void
    {
        $f = F::selection(); $service = app(ReservePricedQuote::class);
        PricingFixtures::configure(array_replace(PricingFixtures::policy(), ['effective_until' => now()->addSeconds(30)->toIso8601ZuluString()]));
        if ($operation === 'attempt') { $service->hold($f['quote']->public_id, F::OWNER, 'SYNTHETIC'); }
        $before = DB::table('audit_events')->count(); $advanced = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$advanced): void {
            if (! $advanced && str_starts_with(strtolower($query), 'select') && preg_match('/from ["`]rights_scopes["`]/', $query)) {
                $advanced = true; $this->travelTo(now()->addSeconds(31));
            }
        });
        $this->rejected('PRICING_EXPIRED', fn () => $operation === 'hold' ?
            $service->hold($f['quote']->public_id, F::OWNER, 'SYNTHETIC') :
            $service->beginAttempt($f['quote']->public_id, F::OWNER, (string) Str::uuid()));
        $this->assertTrue($advanced); $this->assertSame($before, DB::table('audit_events')->count());
        $this->assertDatabaseCount('quote_pricings', $operation === 'hold' ? 0 : 1);
        $this->assertDatabaseCount('inventory_reservations', $operation === 'hold' ? 0 : 1);
        $this->assertSame(0, PromotionUse::where('state', 'pending')->count());
    }

    public function test_enclosing_order_failure_rolls_back_both_holds_attempts_and_audits(): void
    {
        $f = F::selection(); $before = DB::table('audit_events')->count();
        try {
            DB::transaction(function () use ($f) {
                $service = app(ReservePricedQuote::class);
                $service->hold($f['quote']->public_id, F::OWNER, 'SYNTHETIC');
                $service->beginAttempt($f['quote']->public_id, F::OWNER, (string) Str::uuid());
                throw new \RuntimeException('Synthetic order failure');
            });
        } catch (\RuntimeException $error) { $this->assertSame('Synthetic order failure', $error->getMessage()); }
        foreach (['quote_pricings', 'promotion_campaigns', 'promotion_uses', 'inventory_reservations', 'inventory_claims'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($before, DB::table('audit_events')->count());
    }
}
