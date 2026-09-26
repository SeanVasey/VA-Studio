<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures as F;
use Tests\Support\FinalizationRace;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** MySQL locks and independent processes, never SQLite as a concurrency substitute. */
class TestOrderFinalizationConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Finalization races require independent MySQL processes.'); }
    }

    private function finalizationRaceInput(string $mutex = 'rights_scopes'): array
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure(); Queue::fake();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway); $this->app->instance(StripePaymentGateway::class, $gateway);
        $fixture = F::confirmed($gateway, true, true);
        $input = ['operation' => 'finalize', 'payment_id' => $fixture['payment']->id, 'scope_id' => $fixture['scope']->id,
            'actor_id' => $fixture['actor']->id, 'quote_id' => $fixture['quote']->public_id,
            'now' => now()->toIso8601ZuluString(), 'mutex' => $mutex];

        return [$fixture, $input];
    }

    public function test_competing_finalizers_share_one_terminal_graph_and_one_resource_effect(): void
    {
        [$f, $input] = $this->finalizationRaceInput('orders');
        $results = FinalizationRace::run($this, [$input, $input]);
        $this->assertSame(['paid', 'paid'], array_column($results, 'outcome'));
        $this->assertDatabaseCount('order_finalizations', 1); $this->assertDatabaseCount('license_grants', 1);
        $this->assertDatabaseCount('pending_entitlements', 1); $this->assertDatabaseCount('fulfillment_outbox', 1);
        $this->assertDatabaseCount('exclusive_sales', 1);
        $this->assertSame('consumed', InventoryReservation::sole()->state); $this->assertSame('consumed', PromotionUse::sole()->state);
        $this->assertSame($f['original'], app(ReadOrder::class)->verify($f['order']->fresh()));
    }

    public function test_block_that_wins_scope_mutex_forces_exception_without_grants(): void
    {
        [$f, $input] = $this->finalizationRaceInput(); $before = PaymentFixtures::unchangedBusinessEvidence();
        $results = FinalizationRace::run($this, [array_replace($input, ['operation' => 'block']), $input]);
        $this->assertSame(['blocked', 'paid_exception'], array_column($results, 'outcome'));
        $this->assertSame('inventory_blocked', OrderFinalization::sole()->reason);
        $this->assertDatabaseCount('license_grants', 0); $this->assertDatabaseCount('pending_entitlements', 0);
        $this->assertDatabaseCount('exclusive_sales', 0); $this->assertDatabaseCount('fulfillment_outbox', 1);
        $this->assertSame('order_paid_exception_v1', FulfillmentOutbox::sole()->kind);
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertSame($f['original'], app(ReadOrder::class)->verify($f['order']->fresh()));
    }

    public function test_finalization_that_wins_scope_mutex_retains_grant_when_admin_blocks_afterward(): void
    {
        [$f, $input] = $this->finalizationRaceInput();
        $results = FinalizationRace::run($this, [$input, array_replace($input, ['operation' => 'block'])]);
        $this->assertSame(['paid', 'blocked'], array_column($results, 'outcome'));
        $this->assertTrue(RightsScope::findOrFail($f['scope']->id)->blocked);
        $this->assertSame('paid', OrderFinalization::sole()->outcome);
        $this->assertDatabaseCount('license_grants', 1); $this->assertDatabaseCount('exclusive_sales', 1);
        $this->assertSame('consumed', InventoryReservation::sole()->state);
        $this->assertSame($f['original'], app(ReadOrder::class)->verify($f['order']->fresh()));
    }

    public function test_own_reservation_retry_waiting_on_scope_observes_committed_exclusive_sale(): void
    {
        [$f, $input] = $this->finalizationRaceInput();
        $results = FinalizationRace::run($this, [$input, array_replace($input, ['operation' => 'read_inventory'])]);
        $this->assertSame(['paid', 'INVENTORY_UNAVAILABLE'], array_column($results, 'outcome'));
        $this->assertDatabaseCount('exclusive_sales', 1); $this->assertDatabaseCount('license_grants', 1);
        $this->assertDatabaseCount('inventory_reservations', 1); $this->assertDatabaseCount('inventory_claims', 1);
        $this->assertSame('consumed', InventoryReservation::sole()->state);
        $this->assertSame($f['original'], app(ReadOrder::class)->verify($f['order']->fresh()));
    }
}
