<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\TestUnpaidReleaseEvent;
use App\Domain\Commerce\Models\TestUnpaidReleaseWork;
use App\Domain\Commerce\Models\TestUnsentOrderRelease;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\UnpaidRelease\ReadUnsentRelease;
use App\Domain\Commerce\UnpaidRelease\ReleaseTestOrderResources;
use App\Filament\Resources\TestUnpaidOrderResource\Pages\ListTestUnpaidOrders;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\UnpaidReleaseFixtures as F;
use Tests\TestCase;

/** Staff release of a prepared test order that never reached Stripe. Synthetic transport only; no provider call is expected. */
class TestUnsentOrderReleaseTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        Queue::fake();
        $this->gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
    }

    /** A prepared order whose buyer never started checkout, after its attempt window closed. */
    private function abandoned(bool $exclusive = false, bool $promoted = false): array
    {
        $f = CheckoutFixtures::prepared($exclusive, $promoted);
        $attempt = $f['order']->attempt()->sole();
        $this->travelTo($attempt->expires_at->addSecond());

        return $f + ['attempt' => $attempt, 'admin' => LicenseFixtures::admin()];
    }

    public static function promotions(): array
    {
        return ['no promotion' => [false], 'promotion' => [true]];
    }

    #[DataProvider('promotions')]
    public function test_never_sent_order_is_released_with_local_proof_only_and_is_idempotent(bool $promoted): void
    {
        $f = $this->abandoned(false, $promoted);
        // Fixture preparation schedules media work; measure only the release boundary.
        Queue::fake();
        $order = $f['order'];
        $attempt = $f['attempt'];
        $original = app(ReadOrder::class)->verify($order);
        $attributes = $order->getAttributes();
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $service = app(ReleaseTestOrderResources::class);
        $this->assertDatabaseHas('inventory_reservations', ['id' => $attempt->inventory_reservation_id, 'state' => 'pending']);
        $listed = $service->query()->get();
        $this->assertSame([$order->id], $listed->pluck('id')->all());
        $this->assertSame(0, (int) $listed->sole()->checkout_started);
        $review = $service->review($order->public_id, $f['admin']);
        $this->assertSame(['unverified', 0, 'never_sent', $attempt->expires_at->toIso8601ZuluString()],
            [$review['status'], $review['sequence'], $review['checkout'], $review['checkoutWindowClosesAt']]);

        $key = (string) Str::uuid();
        $result = $service->release($order->public_id, $f['admin'], $key, 0, ReleaseTestOrderResources::NEVER_SENT);
        $this->assertSame(['testOnly' => true, 'status' => 'released', 'sequence' => 2], $result);
        $this->assertSame($result, $service->release($order->public_id, $f['admin'], $key, 0, ReleaseTestOrderResources::NEVER_SENT));
        $this->assertSame($result, $service->release($order->public_id, $f['admin'], $key, 0));
        $this->assertSame([], $this->gateway->calls);
        $this->assertSame(['requested', 'observed'], TestUnpaidReleaseEvent::orderBy('sequence')->pluck('kind')->all());
        $release = TestUnsentOrderRelease::sole();
        $this->assertSame([ReadUnsentRelease::POLICY_VERSION, ReadUnsentRelease::PROOF, $f['admin']->id, $attempt->id],
            [$release->policy_version, $release->proof, $release->actor_id, $attempt->id]);
        $this->assertTrue($release->checkout_window_closed_at->equalTo($attempt->expires_at));
        $payload = app(ReadUnsentRelease::class)->verify($release, $order);
        $this->assertSame(ReadUnsentRelease::FACTS, $payload['facts']);
        $this->assertSame($attributes, $order->fresh()->getAttributes());
        $this->assertSame($original, app(ReadOrder::class)->verify($order));
        $after = PaymentFixtures::unchangedBusinessEvidence();
        foreach (['inventory_reservations', 'promotion_uses'] as $table) {
            unset($before[$table], $after[$table]);
        }
        $this->assertSame($before, $after);
        $this->assertDatabaseHas('inventory_reservations', ['id' => $attempt->inventory_reservation_id, 'state' => 'released', 'attempt_id' => $attempt->public_id, 'consumed_at' => null]);
        if ($promoted) {
            $this->assertDatabaseHas('promotion_uses', ['id' => $attempt->promotion_use_id, 'state' => 'released', 'consumed_at' => null]);
        }
        $audit = AuditEvent::where('action', 'commerce.unpaid_release.unsent_released')->sole();
        $this->assertSame($f['admin']->id, $audit->actor_id);
        // MySQL's JSON column does not keep key order; compare the exact content in a canonical key order.
        $expected = ['order_id' => $order->public_id, 'release_id' => $release->public_id, 'proof' => ReadUnsentRelease::PROOF,
            'request_id' => $key, 'sequence' => 2, 'test_only' => true];
        $context = $audit->context;
        ksort($expected);
        ksort($context);
        $this->assertSame($expected, $context);
        $review = $service->review($order->public_id, $f['admin']);
        $this->assertSame(['released', $release->public_id], [$review['status'], $review['release']['publicId']]);
        $this->assertNull(TestUnpaidReleaseWork::sole()->claim_token);
        foreach (['checkout_intents', 'checkout_sessions', 'verified_payments', 'order_finalizations', 'test_unpaid_releases', 'exclusive_sales', 'license_grants'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        // A newly reviewed request replays the retained release without a second record.
        $this->assertSame('released', $service->release($order->public_id, LicenseFixtures::admin(), (string) Str::uuid(), 2)['status']);
        $this->assertSame(1, TestUnsentOrderRelease::count());
        $this->assertSame(2, TestUnpaidReleaseEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_released_order_cannot_reach_checkout_payment_or_a_new_intent(): void
    {
        $f = $this->abandoned();
        $this->assertSame('released', app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0)['status']);
        // Even with the attempt window artificially reopened, the order stays terminal.
        $this->travelTo($f['order']->created_at);
        try {
            app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER);
            $this->fail('Released order reached checkout.');
        } catch (QuoteException $error) {
            $this->assertSame('CHECKOUT_EXPIRED', $error->errorCode);
        }
        $status = app(HostedCheckout::class)->status($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame(['expired', null, 'not_verified'], [$status['status'], $status['url'], $status['paymentStatus']]);
        $this->assertSame('expired', app(HostedCheckout::class)->reconcile($f['order']->public_id, InventoryFixtures::OWNER)['status']);
        $this->assertSame([], $this->gateway->calls);
        // The database refuses a checkout intent for a released never-sent order, whatever code path tries.
        $other = CheckoutFixtures::prepared();
        app(HostedCheckout::class)->start($other['order']->public_id, InventoryFixtures::OWNER);
        $row = (array) DB::table('checkout_intents')->where('order_id', $other['order']->id)->sole();
        unset($row['id']);
        $row = ['public_id' => (string) Str::uuid(), 'order_id' => $f['order']->id, 'order_attempt_id' => $f['attempt']->id,
            'idempotency_key' => 'vasey-checkout-v1-'.Str::uuid()] + $row;
        $this->assertDatabaseRefuses(fn () => DB::table('checkout_intents')->insert($row));
        $this->assertSame(1, CheckoutIntent::count());
    }

    public function test_open_checkout_window_is_refused_without_history_or_resource_change(): void
    {
        $f = CheckoutFixtures::prepared();
        $admin = LicenseFixtures::admin();
        $this->travelTo($f['order']->attempt()->sole()->expires_at->subSecond());
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $result = app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $admin, (string) Str::uuid(), 0);
        $this->assertSame(['testOnly' => true, 'status' => 'checkout_open', 'sequence' => 0], $result);
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertDatabaseCount('test_unpaid_release_events', 0);
        $this->assertDatabaseCount('test_unsent_order_releases', 0);
        // The buyer can still start checkout, and the order then leaves the never-sent path.
        app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER);
        $this->travel(2)->hours();
        $this->assertSame('stale', app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $admin, (string) Str::uuid(), 0, ReleaseTestOrderResources::NEVER_SENT)['status']);
        $this->assertDatabaseCount('test_unsent_order_releases', 0);
        $this->assertDatabaseHas('inventory_reservations', ['state' => 'pending']);
    }

    public static function sentOrders(): array
    {
        return [['unbound'], ['bound']];
    }

    /** An order whose checkout intent exists may have reached Stripe: the never-sent proof never applies to it. */
    #[DataProvider('sentOrders')]
    public function test_order_with_a_checkout_intent_never_takes_the_never_sent_path(string $case): void
    {
        if ($case === 'unbound') {
            $f = CheckoutFixtures::prepared();
            // The provider may have created a session before the failure: nothing local proves otherwise.
            $this->gateway->onCreate = fn () => throw new RuntimeException('synthetic ambiguous dispatch');
            try {
                app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER);
            } catch (QuoteException) {
            }
        } else {
            $f = PaymentFixtures::started($this->gateway);
            $this->gateway->session['status'] = 'open';
            $this->gateway->session['payment_status'] = 'unpaid';
        }
        $admin = LicenseFixtures::admin();
        $this->travel(2)->hours();
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $service = app(ReleaseTestOrderResources::class);
        $this->assertSame(1, (int) $service->query()->get()->sole()->checkout_started);
        $this->assertSame('started', $service->review($f['order']->public_id, $admin)['checkout']);
        $calls = count($this->gateway->calls);
        $this->assertSame('stale', $service->release($f['order']->public_id, $admin, (string) Str::uuid(), 0, ReleaseTestOrderResources::NEVER_SENT)['status']);
        $this->assertSame($calls, count($this->gateway->calls));
        // The provider-proof path still applies, and an open or unbound session never establishes unpaid status.
        $this->assertNotSame('released', $service->release($f['order']->public_id, $admin, (string) Str::uuid(), 0)['status']);
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertDatabaseCount('test_unsent_order_releases', 0);
        $this->assertDatabaseCount('test_unpaid_releases', 0);
    }

    public static function refusals(): array
    {
        return [['production'], ['staging_live'], ['disabled'], ['policy'], ['account'], ['staff'], ['mfa'], ['outer_transaction'], ['proof']];
    }

    #[DataProvider('refusals')]
    public function test_admission_authority_and_request_shape_are_enforced_inside_the_service(string $case): void
    {
        $f = $this->abandoned(false, true);
        $before = PaymentFixtures::unchangedBusinessEvidence();
        match ($case) {
            'production' => $this->app->instance('env', 'production'),
            'staging_live' => config(['payments.stripe.mode' => 'live']),
            'disabled' => config(['unpaid-release.enabled' => false]),
            'policy' => config(['unpaid-release.policy' => '{}']),
            'account' => config(['payments.stripe.account_id' => 'not-an-account']),
            'staff' => $f['admin']->forceFill(['is_admin' => false])->save(),
            'mfa' => Filament::getPanel('admin')->multiFactorAuthentication([], isRequired: true),
            'outer_transaction' => DB::beginTransaction(),
            'proof' => null,
        };
        try {
            app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0,
                $case === 'proof' ? 'provider_session' : ReleaseTestOrderResources::NEVER_SENT);
            $this->fail('Refused release was accepted.');
        } catch (RuntimeException|AuthorizationException) {
        } finally {
            $this->app->instance('env', 'testing');
            if ($case === 'outer_transaction') {
                DB::rollBack();
            }
        }
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertDatabaseCount('test_unsent_order_releases', 0);
        $this->assertDatabaseCount('test_unpaid_release_events', 0);
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_stale_review_changed_replay_and_corrupted_proof_are_refused(): void
    {
        $f = $this->abandoned();
        $service = app(ReleaseTestOrderResources::class);
        $this->assertSame('stale', $service->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 3)['status']);
        $key = (string) Str::uuid();
        $this->assertSame('released', $service->release($f['order']->public_id, $f['admin'], $key, 0)['status']);
        foreach ([[$f['admin'], 1], [LicenseFixtures::admin(), 0]] as [$actor, $sequence]) {
            try {
                $service->release($f['order']->public_id, $actor, $key, $sequence);
                $this->fail('Changed replay accepted.');
            } catch (RuntimeException) {
            }
        }
        $this->assertSame('stale', $service->release($f['order']->public_id, LicenseFixtures::admin(), (string) Str::uuid(), 0)['status']);
        DB::unprepared('DROP TRIGGER unsent_release_immutable_update');
        DB::table('test_unsent_order_releases')->update(['evidence_hash' => str_repeat('0', 64)]);
        try {
            $service->release($f['order']->public_id, $f['admin'], $key, 0);
            $this->fail('Corrupted replay accepted.');
        } catch (RuntimeException) {
        }
        $this->assertSame(1, TestUnsentOrderRelease::count());
        $this->assertDatabaseCount('test_unpaid_release_events', 2);
    }

    public function test_release_record_events_audit_and_resources_roll_back_together(): void
    {
        $f = $this->abandoned(false, true);
        $before = PaymentFixtures::unchangedBusinessEvidence();
        Event::listen('eloquent.creating: '.AuditEvent::class, function ($event) {
            if ($event->action === 'commerce.unpaid_release.unsent_released') {
                throw new RuntimeException('synthetic audit failure');
            }
        });
        try {
            app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0);
            $this->fail('Partial release was accepted.');
        } catch (RuntimeException) {
        }
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertDatabaseCount('test_unsent_order_releases', 0);
        $this->assertDatabaseCount('test_unpaid_release_events', 0);
        $this->assertSame(0, (int) TestUnpaidReleaseWork::max('sequence'));
    }

    /**
     * Each refused proof row differs from an accepted one in exactly the field under test, on a second never-sent order with
     * its own attempt, reservation and event pair, so no duplicate or binding clause can refuse it first.
     */
    public function test_database_refuses_unproved_never_sent_records_and_resource_rewrites(): void
    {
        $b = CheckoutFixtures::prepared();
        $bAttempt = $b['order']->attempt()->sole();
        $actor = LicenseFixtures::admin();
        // An observed release event recorded while B's window was still open (only constructible by raw writes).
        $early = $this->eventPair($b['order']->id, $actor->id, 0);
        $f = $this->abandoned(false, true);
        $late = $this->eventPair($b['order']->id, $actor->id, 2);
        foreach (['inventory_reservations', 'promotion_uses'] as $table) {
            $this->assertDatabaseRefuses(fn () => DB::table($table)->update(['state' => 'released']));
        }
        $this->assertSame('released', app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0)['status']);
        // An existing never-sent release does not admit an unrelated pending reservation.
        $this->assertDatabaseRefuses(fn () => DB::table('inventory_reservations')->where('id', $bAttempt->inventory_reservation_id)->update(['state' => 'released']), 'unrelated reservation');

        $valid = fn (TestUnpaidReleaseEvent $event, array $change = []): array => array_merge(['public_id' => (string) Str::uuid(),
            'order_id' => $b['order']->id, 'order_attempt_id' => $bAttempt->id, 'event_id' => $event->id, 'request_id' => $event->request_id,
            'actor_id' => $actor->id, 'mode' => 'test', 'policy_version' => ReadUnsentRelease::POLICY_VERSION, 'proof' => ReadUnsentRelease::PROOF,
            'inventory_reservation_id' => $bAttempt->inventory_reservation_id, 'promotion_use_id' => null,
            'checkout_window_closed_at' => $bAttempt->expires_at->format('Y-m-d H:i:s'), 'released_at' => $event->created_at->format('Y-m-d H:i:s'),
            'evidence_ciphertext' => 'synthetic', 'evidence_hash' => str_repeat('a', 64), 'canonicalization_version' => 'vasey-json-v1'], $change);
        // Positive control: the unchanged row and an exact release transition are accepted (then rolled back).
        DB::beginTransaction();
        try {
            DB::table('test_unsent_order_releases')->insert($valid($late));
            $this->assertSame(1, DB::table('inventory_reservations')->where('id', $bAttempt->inventory_reservation_id)->update(['state' => 'released']));
        } finally {
            DB::rollBack();
        }
        $refused = [
            'released before the window closed' => $valid($early),
            'other proof' => $valid($late, ['proof' => 'expiry_only']),
            'other policy' => $valid($late, ['policy_version' => 'test-unpaid-release-v1']),
            'other mode' => $valid($late, ['mode' => 'live']),
            'other window' => $valid($late, ['checkout_window_closed_at' => $bAttempt->expires_at->subSecond()->format('Y-m-d H:i:s')]),
            'requested event' => $valid($late, ['event_id' => TestUnpaidReleaseEvent::where('request_id', $late->request_id)->where('kind', 'requested')->sole()->id]),
            'duplicate of the retained release' => array_merge((array) DB::table('test_unsent_order_releases')->sole(), ['id' => null, 'public_id' => (string) Str::uuid()]),
        ];
        foreach ($refused as $label => $row) {
            unset($row['id']);
            $this->assertDatabaseRefuses(fn () => DB::table('test_unsent_order_releases')->insert($row), $label);
        }
        // With a valid row for B in place, a release that also rewrites the attempt binding is refused.
        foreach (['attempt_id' => (string) Str::uuid(), 'pending_at' => $bAttempt->expires_at->subSeconds(5)->format('Y-m-d H:i:s'),
            'expired_at' => now()->format('Y-m-d H:i:s')] as $field => $value) {
            DB::beginTransaction();
            try {
                DB::table('test_unsent_order_releases')->insert($valid($late));
                $this->assertDatabaseRefuses(fn () => DB::table('inventory_reservations')->where('id', $bAttempt->inventory_reservation_id)
                    ->update(['state' => 'released', $field => $value]), 'release rewriting '.$field);
            } finally {
                DB::rollBack();
            }
        }
        $this->assertDatabaseRefuses(fn () => DB::table('test_unsent_order_releases')->update(['request_id' => (string) Str::uuid()]));
        $this->assertDatabaseRefuses(fn () => DB::table('test_unsent_order_releases')->delete());
        foreach ([['state' => 'pending'], ['state' => 'consumed', 'consumed_at' => now()]] as $change) {
            $this->assertDatabaseRefuses(fn () => DB::table('inventory_reservations')->where('id', $f['attempt']->inventory_reservation_id)->update($change));
        }
        $this->assertDatabaseHas('inventory_reservations', ['id' => $bAttempt->inventory_reservation_id, 'state' => 'pending']);
        $this->assertSame(1, TestUnsentOrderRelease::count());
    }

    /** A requested/observed `released` pair for an order, written directly at the current time (test fixture only). */
    private function eventPair(int $orderId, int $actorId, int $sequence): TestUnpaidReleaseEvent
    {
        $work = TestUnpaidReleaseWork::firstOrCreate(['order_id' => $orderId], ['sequence' => 0]);
        $this->assertSame($sequence, $work->sequence);
        $request = (string) Str::uuid();
        $at = now()->utc()->startOfSecond();
        $event = null;
        foreach ([['requested', 'pending', null], ['observed', 'released', $at]] as $offset => [$kind, $outcome, $observed]) {
            $event = TestUnpaidReleaseEvent::create(['order_id' => $orderId, 'sequence' => $sequence + $offset + 1, 'review_sequence' => $sequence,
                'request_id' => $request, 'actor_id' => $actorId, 'kind' => $kind, 'outcome' => $outcome, 'observed_at' => $observed, 'created_at' => $at]);
            DB::table('test_unpaid_release_work')->where('id', $work->id)->update(['sequence' => $sequence + $offset + 1]);
        }

        return $event;
    }

    /** If the code check ever misses a committed release, the database refuses the intent and the buyer still gets a 410. */
    public function test_database_refusal_of_a_late_intent_is_reported_as_checkout_expired(): void
    {
        $f = $this->abandoned();
        $this->assertSame('released', app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0)['status']);
        // Simulate the stale read: the reservation reads as pending again (raw, guard removed in this disposable database only),
        // so ReadUnsentRelease::for finds nothing and only the checkout_intents guard stands in the way.
        DB::unprepared('DROP TRIGGER inventory_reservations_transition');
        DB::table('inventory_reservations')->where('id', $f['attempt']->inventory_reservation_id)->update(['state' => 'pending']);
        $this->assertNull(ReadUnsentRelease::for($f['order']));
        $this->travelTo($f['order']->created_at);
        try {
            app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER);
            $this->fail('A released never-sent order reached checkout.');
        } catch (QuoteException $error) {
            $this->assertSame(['CHECKOUT_EXPIRED', 410], [$error->errorCode, $error->status]);
        }
        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertSame([], $this->gateway->calls);
    }

    /** A checkout intent committed by any path before the proof row is inserted makes the database refuse the release. */
    public function test_database_refuses_the_proof_row_once_a_checkout_intent_exists(): void
    {
        $other = CheckoutFixtures::prepared();
        app(HostedCheckout::class)->start($other['order']->public_id, InventoryFixtures::OWNER);
        $template = (array) DB::table('checkout_intents')->sole();
        unset($template['id']);
        $f = $this->abandoned(false, true);
        $before = PaymentFixtures::unchangedBusinessEvidence();
        Event::listen('eloquent.creating: '.TestUnsentOrderRelease::class, function () use ($f, $template) {
            DB::table('checkout_intents')->insert(['public_id' => (string) Str::uuid(), 'order_id' => $f['order']->id,
                'order_attempt_id' => $f['attempt']->id, 'idempotency_key' => 'vasey-checkout-v1-'.Str::uuid()] + $template);
        });
        try {
            app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0);
            $this->fail('A release was recorded for an order with a checkout intent.');
        } catch (RuntimeException) {
        }
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertDatabaseCount('test_unsent_order_releases', 0);
        $this->assertSame(1, CheckoutIntent::count());
    }

    public function test_exclusive_hold_is_restored_exactly_without_touching_sales(): void
    {
        // A separately paid and finalized exclusive order must be untouched by the release.
        $paid = FinalizationFixtures::confirmed($this->gateway, true);
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($paid['payment']->id));
        $f = $this->abandoned(true);
        $this->assertSame(1, DB::table('exclusive_sales')->count());
        $sales = DB::table('exclusive_sales')->get()->toJson();
        $consumed = DB::table('inventory_reservations')->where('state', 'consumed')->get()->toJson();
        $finalizations = DB::table('order_finalizations')->get()->toJson();
        try {
            app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']);
            $this->fail('Another selection ignored the pending exclusive order.');
        } catch (QuoteException $error) {
            $this->assertSame('INVENTORY_UNAVAILABLE', $error->errorCode);
        }
        $this->assertSame('released', app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0)['status']);
        $quote = app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']);
        app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER);
        $this->assertDatabaseHas('inventory_reservations', ['quote_id' => $quote->id, 'state' => 'held']);
        $this->assertDatabaseHas('inventory_reservations', ['quote_id' => $f['order']->quote_id, 'state' => 'released']);
        $this->assertSame($sales, DB::table('exclusive_sales')->get()->toJson());
        $this->assertSame($finalizations, DB::table('order_finalizations')->get()->toJson());
        $this->assertSame($consumed, DB::table('inventory_reservations')->where('state', 'consumed')->get()->toJson());
    }

    public function test_real_operator_action_shows_never_sent_status_and_releases(): void
    {
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $f = $this->abandoned();
        $this->actingAs($f['admin']);
        $page = ListTestUnpaidOrders::class;
        Livewire::test($page)->assertSee('Never sent to Stripe')
            ->assertTableActionHidden('releaseUnpaid', $f['order'])->assertTableActionVisible('releaseUnsent', $f['order']);
        $component = Livewire::test($page)->mountTableAction('releaseUnsent', $f['order'])
            ->assertMountedActionModalSee('never sent to Stripe');
        $data = $component->get('mountedActions.0.data');
        $this->assertSame(0, $data['sequence']);
        $component->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Test resources released');
        $this->assertDatabaseCount('test_unsent_order_releases', 1);
        Livewire::test($page)->mountTableAction('releaseHistory', $f['order'])
            ->assertMountedActionModalSee(TestUnsentOrderRelease::sole()->public_id)
            ->assertMountedActionModalSee('Never sent to Stripe')->assertMountedActionModalDontSee($f['order']->payload_ciphertext);
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_migration_rollback_refuses_retained_history_and_restores_the_previous_guards_when_empty(): void
    {
        $migration = require database_path('migrations/2026_11_11_001000_test_unsent_order_releases.php');
        $resources = fn () => DB::table('sqlite_master')->whereIn('name', ['inventory_reservations_transition', 'promotion_uses_guard_update'])
            ->orderBy('name')->pluck('sql')->all();
        $installed = DB::getDriverName() === 'sqlite' ? $resources() : null;
        $migration->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('test_unsent_order_releases'));
        (require database_path('migrations/2026_10_06_000047_test_refund_resolutions.php'))->down();
        (require database_path('migrations/2026_10_06_000047_test_refund_resolutions.php'))->up();
        $migration->up();
        if ($installed !== null) {
            $this->assertSame($installed, $resources());
        }
        $f = $this->abandoned();
        $this->assertSame('released', app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0)['status']);
        try {
            $migration->down();
            $this->fail('Retained unsent release history was dropped.');
        } catch (LogicException) {
        }
        $this->assertSame(1, TestUnsentOrderRelease::count());
    }

    private function assertDatabaseRefuses(callable $write, string $message = ''): void
    {
        try {
            DB::transaction($write);
            $this->fail('Database accepted a refused write. '.$message);
        } catch (QueryException $error) {
            $this->assertStringContainsString('Invalid or immutable', $error->getMessage(), $message);
        }
    }
}
