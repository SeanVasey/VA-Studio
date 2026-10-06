<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutObservation;
use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\CheckoutFixtures as F;
use Tests\Support\CheckoutRace;
use Tests\Support\InventoryFixtures;
use Tests\TestCase;

class HostedCheckoutConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Hosted checkout races require independent MySQL processes.'); }
    }

    public static function creates(): array { return [['same_session'], ['conflicting_session']]; }

    #[DataProvider('creates')]
    public function test_concurrent_initial_creation_reuses_one_committed_intent_and_cannot_bind_two_provider_sessions(string $scenario): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure(); $f = F::prepared(true, true);
        $orderBefore = $f['order']->getAttributes(); $reservationBefore = InventoryReservation::sole()->getAttributes();
        $inputs = [];
        foreach ([0, 1] as $worker) {
            $inputs[] = ['action' => 'start', 'order' => $f['order']->public_id, 'owner' => InventoryFixtures::OWNER,
                'session_id' => $scenario === 'same_session' ? F::SESSION : 'cs_test_WORKER'.$worker, 'now' => now()->toIso8601ZuluString()];
        }
        $results = CheckoutRace::run($this, $inputs); $outcomes = array_column($results, 'result'); sort($outcomes);
        $this->assertSame($scenario === 'same_session' ? ['ok', 'ok'] : ['ok', 'rejected'], $outcomes);
        $creates = array_map(fn ($result) => array_values(array_filter($result['calls'], fn ($call) => $call['operation'] === 'create'))[0], $results);
        $this->assertSame($creates[0]['params'], $creates[1]['params']); $this->assertSame($creates[0]['key'], $creates[1]['key']);
        foreach ($creates as $call) { $this->assertSame(0, $call['transaction_level']); $this->assertSame(1, $call['intent_count']); }
        foreach ($results as $result) { if ($result['result'] === 'rejected') { $this->assertSame('CHECKOUT_CHANGED', $result['code']); } }
        $this->assertDatabaseCount('checkout_intents', 1); $this->assertDatabaseCount('checkout_sessions', 1);
        $this->assertDatabaseCount('checkout_observations', $scenario === 'same_session' ? 2 : 1);
        $this->assertSame($creates[0]['key'], CheckoutIntent::sole()->idempotency_key);
        $this->assertSame($orderBefore, Order::sole()->getAttributes()); $this->assertSame($reservationBefore, InventoryReservation::sole()->getAttributes());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.checkout.initiated')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.checkout.bound')->count());
    }

    public static function observations(): array { return [['open_and_complete'], ['conflicting_terminal']]; }

    #[DataProvider('observations')]
    public function test_concurrent_provider_observations_cannot_regress_or_replace_terminal_evidence(string $scenario): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure(); $f = F::prepared();
        $gateway = F::gateway(); $this->app->instance(StripeCheckoutGateway::class, $gateway);
        app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER);
        $sessionBefore = CheckoutSession::sole()->getAttributes(); $reservationBefore = InventoryReservation::sole()->getAttributes();
        $inputs = [];
        foreach ([$scenario === 'open_and_complete' ? 'open' : 'expired', 'complete'] as $status) {
            $inputs[] = ['action' => 'reconcile', 'order' => $f['order']->public_id, 'owner' => InventoryFixtures::OWNER,
                'session' => $gateway->session, 'status' => $status, 'now' => now()->toIso8601ZuluString()];
        }
        $results = CheckoutRace::run($this, $inputs); $outcomes = array_column($results, 'result'); sort($outcomes);
        $this->assertSame($scenario === 'open_and_complete' ? ['ok', 'ok'] : ['ok', 'rejected'], $outcomes);
        $current = app(HostedCheckout::class)->status($f['order']->public_id, InventoryFixtures::OWNER);
        if ($scenario === 'open_and_complete') { $this->assertSame('complete', $current['status']); }
        else {
            $this->assertContains($current['status'], ['complete', 'expired']);
            foreach ($results as $result) { if ($result['result'] === 'rejected') { $this->assertSame('CHECKOUT_CHANGED', $result['code']); } }
        }
        $this->assertNull($current['url']); $this->assertSame('not_verified', $current['paymentStatus']);
        $this->assertSame('not_started', $current['fulfillmentStatus']);
        $this->assertSame($sessionBefore, CheckoutSession::sole()->getAttributes());
        $this->assertSame($reservationBefore, InventoryReservation::sole()->getAttributes());
        $this->assertSame(1, CheckoutObservation::whereIn('status', ['complete', 'expired'])->count());
        $this->assertDatabaseCount('checkout_intents', 1); $this->assertDatabaseCount('checkout_sessions', 1);
    }
}
