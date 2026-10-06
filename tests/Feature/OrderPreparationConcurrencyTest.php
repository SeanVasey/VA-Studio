<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderAttempt;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\PriceQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ExclusiveSelectionFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures as F;
use Tests\Support\OrderRace;
use Tests\Support\PricingFixtures;
use Tests\TestCase;

class OrderPreparationConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Order races require independent MySQL processes.'); }
    }

    private function input(array $request, string $key, string $owner = InventoryFixtures::OWNER): array
    {
        return ['owner' => $owner, 'key' => $key, 'request' => $request,
            'inventory_policy' => InventoryFixtures::policy(), 'pricing_policy' => PricingFixtures::policy(),
            'order_policy' => F::policy(), 'exclusive_policy' => ExclusiveSelectionFixtures::policy(),
            'promotions' => json_decode(config('commerce.test_promotions'), true, 16, JSON_THROW_ON_ERROR),
            'now' => now()->toIso8601ZuluString()];
    }

    public static function races(): array
    {
        return [['same_key'], ['changed_body'], ['different_key'], ['different_quote_same_key'], ['shared_scope'], ['expired_replay']];
    }

    #[DataProvider('races')]
    public function test_real_mysql_contention_commits_only_one_order_and_resource_attempt(string $scenario): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure();
        $shared = $scenario === 'shared_scope';
        $a = F::priced(false, ! $shared); $first = F::request($a['quote']); $second = $first;
        $key = (string) Str::uuid(); $secondKey = $scenario === 'different_key' ? (string) Str::uuid() : $key;
        $secondOwner = InventoryFixtures::OWNER;
        if ($scenario === 'changed_body') { $second['buyer']['email'] = 'second-synthetic@example.invalid'; }
        if ($scenario === 'different_quote_same_key') { $b = F::priced(); $second = F::request($b['quote']); }
        if ($shared) {
            $b = InventoryFixtures::selection($a['scope']); $secondOwner = str_repeat('b', 64);
            $quote = app(CreateQuote::class)->handle($secondOwner, (string) Str::uuid(), $b['items']);
            app(PriceQuote::class)->create($quote->public_id, $secondOwner); $second = F::request($quote, $secondOwner);
        }
        $before = null;
        if ($scenario === 'expired_replay') {
            $order = app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, $key, $first);
            $before = ['order' => $order->refresh()->getAttributes(), 'attempt' => OrderAttempt::sole()->getAttributes(),
                'reservation' => InventoryReservation::sole()->getAttributes(), 'promotion' => PromotionUse::sole()->getAttributes(),
                'audits' => DB::table('audit_events')->count()];
            $this->travelTo($a['quote']->expires_at->addDay());
        }
        $results = OrderRace::run($this, [$this->input($first, $key), $this->input($second, $secondKey, $secondOwner)]);
        $outcomes = array_column($results, 'result'); sort($outcomes);
        $both = in_array($scenario, ['same_key', 'expired_replay'], true);
        $this->assertSame($both ? ['ok', 'ok'] : ['ok', 'rejected'], $outcomes);
        if ($both) { $this->assertSame($results[0]['effect_id'], $results[1]['effect_id']); }
        foreach ($results as $result) {
            if ($result['result'] === 'rejected') {
                $this->assertContains($result['code'], match ($scenario) {
                    'changed_body', 'different_quote_same_key' => ['IDEMPOTENCY_CONFLICT'],
                    'different_key' => ['ORDER_ALREADY_PREPARED'],
                    default => ['INVENTORY_UNAVAILABLE', 'SELECTION_CHANGED'],
                });
            }
        }
        foreach (['orders', 'order_lines', 'order_attempts', 'inventory_reservations', 'inventory_claims'] as $table) { $this->assertDatabaseCount($table, 1); }
        $order = Order::sole(); $attempt = OrderAttempt::sole(); $reservation = InventoryReservation::sole();
        $this->assertSame($order->id, $attempt->order_id); $this->assertSame('pending', $reservation->state);
        $this->assertSame($attempt->public_id, $reservation->attempt_id); $this->assertSame($reservation->id, $attempt->inventory_reservation_id);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.inventory.pending')->count());
        if ($attempt->promotion_use_id !== null) {
            $use = PromotionUse::whereKey($attempt->promotion_use_id)->sole();
            $this->assertSame('pending', $use->state); $this->assertSame($attempt->public_id, $use->attempt_id);
            $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.promotion.pending')->count());
        }
        if ($before !== null) {
            $this->assertSame($before['order'], $order->getAttributes()); $this->assertSame($before['attempt'], $attempt->getAttributes());
            $this->assertSame($before['reservation'], $reservation->getAttributes()); $this->assertSame($before['promotion'], PromotionUse::sole()->getAttributes());
            $this->assertSame($before['audits'], DB::table('audit_events')->count());
        }
    }

    public function test_scope_block_committed_before_owner_mutex_acquisition_prevents_both_orders(): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure();
        $a = F::priced(); $b = InventoryFixtures::selection($a['scope']);
        app(PriceQuote::class)->create($b['quote']->public_id, InventoryFixtures::OWNER);
        $inputs = [$this->input(F::request($a['quote']), (string) Str::uuid()), $this->input(F::request($b['quote']), (string) Str::uuid())];
        $results = OrderRace::run($this, $inputs, function () use ($a): void {
            app(ManageRightsScope::class)->block($a['scope']->id, true, 0, 'ORDER-RACE-COMMITTED-BLOCK', $a['actor']);
            $this->assertSame(0, DB::transactionLevel());
        });
        $this->assertSame(['rejected', 'rejected'], array_column($results, 'result'));
        foreach ($results as $result) { $this->assertContains($result['code'], ['INVENTORY_BLOCKED', 'INVENTORY_UNAVAILABLE', 'SELECTION_CHANGED']); }
        foreach (['orders', 'order_lines', 'order_attempts', 'inventory_reservations', 'inventory_claims'] as $table) { $this->assertDatabaseCount($table, 0); }
        $this->assertTrue($a['scope']->refresh()->blocked); $this->assertDatabaseCount('quote_pricings', 2);
    }

    public function test_price_expiry_during_owner_mutex_wait_cannot_create_an_order_or_extend_capacity(): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure();
        $f = F::priced(); $input = $this->input(F::request($f['quote']), (string) Str::uuid());
        $input['after_release_now'] = $f['pricing']->expires_at->toIso8601ZuluString();
        $before = DB::table('audit_events')->count();
        $results = OrderRace::run($this, [$input, $input]);
        $this->assertSame(['rejected', 'rejected'], array_column($results, 'result'));
        foreach ($results as $result) { $this->assertContains($result['code'], ['QUOTE_EXPIRED', 'PRICING_EXPIRED', 'ORDER_EXPIRED']); }
        foreach (['orders', 'order_lines', 'order_attempts', 'inventory_reservations', 'inventory_claims'] as $table) { $this->assertDatabaseCount($table, 0); }
        $this->assertSame($before, DB::table('audit_events')->count());
    }
}
