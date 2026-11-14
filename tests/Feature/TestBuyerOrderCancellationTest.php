<?php

namespace Tests\Feature;

use App\Domain\Commerce\BuyerCancel\BuyerCancelPolicy;
use App\Domain\Commerce\BuyerCancel\CancelTestOrder;
use App\Domain\Commerce\BuyerCancel\ReadBuyerCancellation;
use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\TestBuyerOrderCancellation;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\UnpaidRelease\ReleaseTestOrderResources;
use App\Domain\Customers\CustomerAccessException;
use App\Filament\Resources\TestUnpaidOrderResource\Pages\ListTestUnpaidOrders;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CheckoutFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\UnpaidReleaseFixtures as F;
use Tests\TestCase;

/** The buyer cancels their own prepared test order before it was ever sent to Stripe. Synthetic transport; no provider call. */
class TestBuyerOrderCancellationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        config(['buyer-cancel.enabled' => true, 'buyer-cancel.policy' => json_encode(BuyerCancelPolicy::CONTRACT, JSON_THROW_ON_ERROR)]);
        Queue::fake();
        $this->gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
    }

    private function cancel(array $f, string $owner = InventoryFixtures::OWNER): array
    {
        return app(CancelTestOrder::class)->cancel($f['order']->public_id, $owner);
    }

    private function refused(string $code, int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Refused operation was accepted: '.$code);
        } catch (QuoteException $error) {
            $this->assertSame([$code, $status], [$error->errorCode, $error->status]);
        }
    }

    public static function promotions(): array
    {
        return ['no promotion' => [false], 'promotion' => [true]];
    }

    #[DataProvider('promotions')]
    public function test_buyer_cancels_a_never_sent_order_with_local_proof_only_and_the_cancel_is_idempotent(bool $promoted): void
    {
        $f = CheckoutFixtures::prepared(false, $promoted);
        Queue::fake();
        $order = $f['order'];
        $attempt = $order->attempt()->sole();
        // Inside the attempt window: the buyer need not wait for it to close, nor for staff.
        $this->assertTrue($attempt->expires_at->greaterThan(now()));
        $original = app(ReadOrder::class)->verify($order);
        $attributes = $order->getAttributes();
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $status = app(HostedCheckout::class)->status($order->public_id, InventoryFixtures::OWNER);
        $this->assertSame(['not_started', 'available'], [$status['status'], $status['buyerCancel']]);

        $result = $this->cancel($f);
        $this->assertSame(['not_started', 'cancelled', null, null, 'not_verified'],
            [$result['status'], $result['buyerCancel'], $result['id'], $result['url'], $result['paymentStatus']]);
        $this->assertSame($result, $this->cancel($f));
        $this->assertSame($result, app(HostedCheckout::class)->status($order->public_id, InventoryFixtures::OWNER));
        $this->assertSame([], $this->gateway->calls);
        $cancellation = TestBuyerOrderCancellation::sole();
        $this->assertSame([ReadBuyerCancellation::POLICY_VERSION, ReadBuyerCancellation::PROOF, 'test', $attempt->id,
            $attempt->inventory_reservation_id, $attempt->promotion_use_id],
            [$cancellation->policy_version, $cancellation->proof, $cancellation->mode, $cancellation->order_attempt_id,
                $cancellation->inventory_reservation_id, $cancellation->promotion_use_id]);
        $payload = app(ReadBuyerCancellation::class)->verify($cancellation, $order);
        $this->assertSame(ReadBuyerCancellation::FACTS, $payload['facts']);
        $this->assertSame(CanonicalJson::encode(BuyerCancelPolicy::CONTRACT), CanonicalJson::encode($payload['policy']));
        $this->assertSame($cancellation->public_id, ReadBuyerCancellation::for($order)->public_id);
        // Order snapshots are untouched; only the reservation (and promotion use) moved pending -> released.
        $this->assertSame($attributes, $order->fresh()->getAttributes());
        $this->assertSame($original, app(ReadOrder::class)->verify($order));
        $after = PaymentFixtures::unchangedBusinessEvidence();
        foreach (['inventory_reservations', 'promotion_uses'] as $table) {
            unset($before[$table], $after[$table]);
        }
        $this->assertSame($before, $after);
        $this->assertDatabaseHas('inventory_reservations', ['id' => $attempt->inventory_reservation_id, 'state' => 'released',
            'attempt_id' => $attempt->public_id, 'consumed_at' => null]);
        if ($promoted) {
            $this->assertDatabaseHas('promotion_uses', ['id' => $attempt->promotion_use_id, 'state' => 'released', 'consumed_at' => null]);
        }
        $audit = AuditEvent::where('action', 'commerce.order.buyer_cancelled')->sole();
        $this->assertNull($audit->actor_id);
        $expected = ['order_id' => $order->public_id, 'cancellation_id' => $cancellation->public_id,
            'proof' => ReadBuyerCancellation::PROOF, 'test_only' => true];
        $context = $audit->context;
        ksort($expected);
        ksort($context);
        $this->assertSame($expected, $context);
        foreach (['checkout_intents', 'checkout_sessions', 'verified_payments', 'order_finalizations', 'test_unpaid_releases',
            'test_unsent_order_releases', 'test_unpaid_release_events', 'exclusive_sales', 'license_grants'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Queue::assertNothingPushed();
    }

    public function test_a_cancelled_order_can_never_reach_checkout_an_intent_or_a_staff_release(): void
    {
        $f = CheckoutFixtures::prepared(false, true);
        $this->cancel($f);
        $this->refused('CHECKOUT_EXPIRED', 410, fn () => app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER));
        $this->assertSame('not_started', app(HostedCheckout::class)->reconcile($f['order']->public_id, InventoryFixtures::OWNER)['status']);
        $this->assertSame([], $this->gateway->calls);
        $this->assertDatabaseCount('checkout_intents', 0);
        // The database refuses a checkout intent for a cancelled order, whatever code path tries.
        $other = CheckoutFixtures::prepared();
        app(HostedCheckout::class)->start($other['order']->public_id, InventoryFixtures::OWNER);
        $row = (array) DB::table('checkout_intents')->where('order_id', $other['order']->id)->sole();
        unset($row['id']);
        $row = ['public_id' => (string) Str::uuid(), 'order_id' => $f['order']->id, 'order_attempt_id' => $f['order']->attempt()->sole()->id,
            'idempotency_key' => 'vasey-checkout-v1-'.Str::uuid()] + $row;
        $this->assertDatabaseRefuses(fn () => DB::table('checkout_intents')->insert($row));
        $this->assertSame(1, CheckoutIntent::count());
        // Staff see it as cancelled by the buyer; the never-sent release has nothing left to release and records nothing.
        $admin = LicenseFixtures::admin();
        $this->travelTo($f['order']->attempt()->sole()->expires_at->addSecond());
        $service = app(ReleaseTestOrderResources::class);
        $listed = $service->query()->where('orders.id', $f['order']->id)->sole();
        $this->assertSame([0, 1], [(int) $listed->checkout_started, (int) $listed->buyer_cancelled]);
        $review = $service->review($f['order']->public_id, $admin);
        $this->assertSame(['buyer_cancelled', null, TestBuyerOrderCancellation::sole()->public_id],
            [$review['status'], $review['release'], $review['buyerCancellation']['publicId']]);
        $this->assertSame('buyer_cancelled', $service->release($f['order']->public_id, $admin, (string) Str::uuid(), 0, ReleaseTestOrderResources::NEVER_SENT)['status']);
        $this->assertDatabaseCount('test_unsent_order_releases', 0);
        $this->assertDatabaseCount('test_unpaid_release_events', 0);
    }

    /** Defence in depth, each layer alone: the code check under the Order lock, then the checkout_intents guard. */
    public function test_checkout_start_is_refused_by_the_locked_check_and_by_the_database_guard_each_alone(): void
    {
        $f = CheckoutFixtures::prepared();
        $this->cancel($f);
        DB::unprepared('DROP TRIGGER checkout_intents_buyer_cancellation_insert');
        $this->refused('CHECKOUT_EXPIRED', 410, fn () => app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER));
        $this->assertDatabaseCount('checkout_intents', 0);

        $g = CheckoutFixtures::prepared();
        $this->cancel($g);
        // Simulate a stale read: the reservation reads as pending again (raw, guard removed in this disposable database only),
        // so ReadBuyerCancellation::for finds nothing and only the checkout_intents guard of the second order stands in the way.
        $migration = require database_path('migrations/2026_11_12_001000_test_buyer_order_cancellations.php');
        $guards = (new \ReflectionMethod($migration, 'guards'))->invoke($migration);
        DB::unprepared($guards['checkout_intents_buyer_cancellation_insert']['sql']);
        DB::unprepared('DROP TRIGGER inventory_reservations_transition');
        DB::table('inventory_reservations')->where('id', $g['order']->attempt()->sole()->inventory_reservation_id)->update(['state' => 'pending']);
        $this->assertNull(ReadBuyerCancellation::for($g['order']));
        $this->refused('CHECKOUT_EXPIRED', 410, fn () => app(HostedCheckout::class)->start($g['order']->public_id, InventoryFixtures::OWNER));
        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertSame([], $this->gateway->calls);
    }

    public static function sentOrders(): array
    {
        return [['unbound'], ['bound']];
    }

    /** An order with a checkout intent may have reached Stripe: the buyer is never offered, and never granted, a cancel. */
    #[DataProvider('sentOrders')]
    public function test_an_order_with_a_checkout_intent_is_never_cancelled(string $case): void
    {
        if ($case === 'unbound') {
            $f = CheckoutFixtures::prepared();
            $this->gateway->onCreate = fn () => throw new RuntimeException('synthetic ambiguous dispatch');
            try {
                app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER);
            } catch (QuoteException) {
            }
            $this->assertDatabaseCount('checkout_sessions', 0);
        } else {
            $f = PaymentFixtures::started($this->gateway);
            $this->gateway->session['status'] = 'open';
            $this->gateway->session['payment_status'] = 'unpaid';
        }
        $this->assertSame(1, CheckoutIntent::count());
        $this->assertSame('unavailable', app(HostedCheckout::class)->status($f['order']->public_id, InventoryFixtures::OWNER)['buyerCancel']);
        $calls = count($this->gateway->calls);
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $audits = DB::table('audit_events')->count();
        $this->refused('CHECKOUT_STARTED', 409, fn () => $this->cancel($f));
        $this->assertSame($calls, count($this->gateway->calls));
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertSame($audits, DB::table('audit_events')->count());
        $this->assertDatabaseCount('test_buyer_order_cancellations', 0);
    }

    public function test_an_order_already_released_by_staff_is_not_cancellable(): void
    {
        $f = CheckoutFixtures::prepared();
        $this->travelTo($f['order']->attempt()->sole()->expires_at->addSecond());
        $this->assertSame('released', app(ReleaseTestOrderResources::class)->release($f['order']->public_id, LicenseFixtures::admin(), (string) Str::uuid(), 0)['status']);
        $this->assertSame('unavailable', app(HostedCheckout::class)->status($f['order']->public_id, InventoryFixtures::OWNER)['buyerCancel']);
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $this->refused('ORDER_NOT_CANCELLABLE', 409, fn () => $this->cancel($f));
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertDatabaseCount('test_buyer_order_cancellations', 0);
    }

    public function test_only_the_owner_can_cancel(): void
    {
        $f = CheckoutFixtures::prepared();
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $this->refused('ORDER_NOT_FOUND', 404, fn () => $this->cancel($f, str_repeat('b', 64)));
        $this->refused('ORDER_NOT_FOUND', 404, fn () => app(CancelTestOrder::class)->cancel((string) Str::uuid(), InventoryFixtures::OWNER));
        $this->refused('ORDER_NOT_FOUND', 404, fn () => app(CancelTestOrder::class)->cancel('not-a-uuid', InventoryFixtures::OWNER));
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertDatabaseCount('test_buyer_order_cancellations', 0);
        $this->assertSame(0, AuditEvent::where('action', 'commerce.order.buyer_cancelled')->count());
    }

    // Review of PR #363 (P2): the signed-in customer account path. Ownership is the account's owner key and locked principal,
    // exactly as for the checkout start; a guest key, another account or a withdrawn account never cancels.
    public function test_only_the_owning_customer_account_cancels_its_order(): void
    {
        $owner = CustomerFixtures::account();
        $other = CustomerFixtures::account(['email' => 'other-buyer@example.test']);
        $order = CustomerFixtures::prepared($owner['user'], configured: true);
        $cancel = app(CancelTestOrder::class);
        $this->refused('ORDER_NOT_FOUND', 404, fn () => $cancel->cancel($order->public_id, $other['principal']->ownerKey,
            $other['user'], $other['principal']));
        $this->refused('ORDER_NOT_FOUND', 404, fn () => $cancel->cancel($order->public_id, InventoryFixtures::OWNER));
        $this->assertDatabaseCount('test_buyer_order_cancellations', 0);

        $result = $cancel->cancel($order->public_id, $owner['principal']->ownerKey, $owner['user'], $owner['principal']);
        $this->assertSame(['not_started', 'cancelled'], [$result['status'], $result['buyerCancel']]);
        $this->assertSame($order->id, TestBuyerOrderCancellation::sole()->order_id);
        $this->assertSame($owner['user']->id, AuditEvent::where('action', 'commerce.order.buyer_cancelled')->sole()->actor_id);
    }

    public function test_a_withdrawn_customer_account_cannot_cancel_and_nothing_is_recorded(): void
    {
        $owner = CustomerFixtures::account();
        $order = CustomerFixtures::prepared($owner['user'], configured: true);
        $reservation = $order->attempt()->sole()->inventory_reservation_id;
        CustomerFixtures::withdraw($owner);
        try {
            app(CancelTestOrder::class)->cancel($order->public_id, $owner['principal']->ownerKey, $owner['user'], $owner['principal']);
            $this->fail('A withdrawn account cancelled its order.');
        } catch (CustomerAccessException) {
        }
        $this->assertDatabaseCount('test_buyer_order_cancellations', 0);
        $this->assertDatabaseHas('inventory_reservations', ['id' => $reservation, 'state' => 'pending']);
        $this->assertSame(0, AuditEvent::where('action', 'commerce.order.buyer_cancelled')->count());
    }

    // Review of PR #363 (P3): a signed-in staff user browsing as a guest buyer is the attributed actor of the cancellation.
    public function test_a_staff_actor_is_attributed_on_a_guest_order_cancellation(): void
    {
        $f = CheckoutFixtures::prepared();
        $admin = LicenseFixtures::admin();
        app(CancelTestOrder::class)->cancel($f['order']->public_id, InventoryFixtures::OWNER, $admin);
        $this->assertSame($admin->id, AuditEvent::where('action', 'commerce.order.buyer_cancelled')->sole()->actor_id);
        $this->assertSame($f['order']->id, TestBuyerOrderCancellation::sole()->order_id);
    }

    public static function admissions(): array
    {
        return [['production'], ['staging_live'], ['disabled'], ['enabled_string'], ['policy'], ['policy_absent']];
    }

    /** Production holds stay as they are: the capability refuses in code there whatever the flags say. */
    #[DataProvider('admissions')]
    public function test_admission_is_enforced_inside_the_service(string $case): void
    {
        $f = CheckoutFixtures::prepared();
        $before = PaymentFixtures::unchangedBusinessEvidence();
        match ($case) {
            'production' => $this->app->instance('env', 'production'),
            'staging_live' => config(['payments.stripe.mode' => 'live']),
            'disabled' => config(['buyer-cancel.enabled' => false]),
            'enabled_string' => config(['buyer-cancel.enabled' => 'true']),
            'policy' => config(['buyer-cancel.policy' => json_encode(['version' => 'test-buyer-cancel-v1'])]),
            'policy_absent' => config(['buyer-cancel.policy' => null]),
        };
        try {
            $this->assertFalse(app(BuyerCancelPolicy::class)->admitted());
            $this->refused('ORDER_CANCEL_UNAVAILABLE', 403, fn () => $this->cancel($f));
            if ($case !== 'production' && $case !== 'staging_live') {
                $this->assertSame('unavailable', app(HostedCheckout::class)->status($f['order']->public_id, InventoryFixtures::OWNER)['buyerCancel']);
            }
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertDatabaseCount('test_buyer_order_cancellations', 0);
    }

    public function test_staging_admits_the_capability_with_the_exact_policy(): void
    {
        try {
            $this->app->instance('env', 'staging');
            $this->assertSame(BuyerCancelPolicy::CONTRACT, app(BuyerCancelPolicy::class)->current());
            config(['buyer-cancel.policy' => json_encode(array_reverse(BuyerCancelPolicy::CONTRACT, true), JSON_THROW_ON_ERROR)]);
            $this->assertTrue(app(BuyerCancelPolicy::class)->admitted());
            config(['buyer-cancel.policy' => json_encode(['provider_calls' => 'some'] + BuyerCancelPolicy::CONTRACT, JSON_THROW_ON_ERROR)]);
            $this->assertFalse(app(BuyerCancelPolicy::class)->admitted());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_cancellation_record_audit_and_resources_roll_back_together(): void
    {
        $f = CheckoutFixtures::prepared(false, true);
        $before = PaymentFixtures::unchangedBusinessEvidence();
        Event::listen('eloquent.creating: '.AuditEvent::class, function ($event) {
            if ($event->action === 'commerce.order.buyer_cancelled') {
                throw new RuntimeException('synthetic audit failure');
            }
        });
        try {
            $this->cancel($f);
            $this->fail('Partial cancellation was accepted.');
        } catch (RuntimeException) {
        }
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertDatabaseCount('test_buyer_order_cancellations', 0);
    }

    /** A checkout intent committed by any path before the proof row is inserted makes the database refuse the cancellation. */
    public function test_database_refuses_the_proof_row_once_a_checkout_intent_exists(): void
    {
        $other = CheckoutFixtures::prepared();
        app(HostedCheckout::class)->start($other['order']->public_id, InventoryFixtures::OWNER);
        $template = (array) DB::table('checkout_intents')->sole();
        unset($template['id']);
        $f = CheckoutFixtures::prepared(false, true);
        $before = PaymentFixtures::unchangedBusinessEvidence();
        Event::listen('eloquent.creating: '.TestBuyerOrderCancellation::class, function () use ($f, $template) {
            DB::table('checkout_intents')->insert(['public_id' => (string) Str::uuid(), 'order_id' => $f['order']->id,
                'order_attempt_id' => $f['order']->attempt()->sole()->id, 'idempotency_key' => 'vasey-checkout-v1-'.Str::uuid()] + $template);
        });
        try {
            $this->cancel($f);
            $this->fail('A cancellation was recorded for an order with a checkout intent.');
        } catch (QueryException) {
        }
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertDatabaseCount('test_buyer_order_cancellations', 0);
        $this->assertSame(1, CheckoutIntent::count());
    }

    /**
     * Each refused proof row differs from an accepted one in exactly the field under test, on a second never-cancelled order,
     * so no duplicate or binding clause can refuse it first.
     */
    public function test_database_refuses_unproved_cancellations_and_resource_rewrites(): void
    {
        $b = CheckoutFixtures::prepared();
        $bAttempt = $b['order']->attempt()->sole();
        $f = CheckoutFixtures::prepared(false, true);
        foreach (['inventory_reservations', 'promotion_uses'] as $table) {
            $this->assertDatabaseRefuses(fn () => DB::table($table)->update(['state' => 'released']));
        }
        $this->cancel($f);
        // An existing cancellation does not admit an unrelated pending reservation.
        $this->assertDatabaseRefuses(fn () => DB::table('inventory_reservations')->where('id', $bAttempt->inventory_reservation_id)->update(['state' => 'released']), 'unrelated reservation');
        $valid = fn (array $change = []): array => array_merge(['public_id' => (string) Str::uuid(),
            'order_id' => $b['order']->id, 'order_attempt_id' => $bAttempt->id, 'mode' => 'test',
            'policy_version' => ReadBuyerCancellation::POLICY_VERSION, 'proof' => ReadBuyerCancellation::PROOF,
            'inventory_reservation_id' => $bAttempt->inventory_reservation_id, 'promotion_use_id' => null,
            'cancelled_at' => now()->format('Y-m-d H:i:s'), 'evidence_ciphertext' => 'synthetic', 'evidence_hash' => str_repeat('a', 64),
            'canonicalization_version' => 'vasey-json-v1'], $change);
        // Positive control: the unchanged row and an exact release transition are accepted (then rolled back).
        DB::beginTransaction();
        try {
            DB::table('test_buyer_order_cancellations')->insert($valid());
            $this->assertSame(1, DB::table('inventory_reservations')->where('id', $bAttempt->inventory_reservation_id)->update(['state' => 'released']));
        } finally {
            DB::rollBack();
        }
        $refused = [
            'other proof' => $valid(['proof' => 'no_checkout_intent_closed_window_v1']),
            'other policy' => $valid(['policy_version' => 'test-unsent-release-v1']),
            'other mode' => $valid(['mode' => 'live']),
            'other attempt' => $valid(['order_attempt_id' => $f['order']->attempt()->sole()->id]),
            'other reservation' => $valid(['inventory_reservation_id' => $f['order']->attempt()->sole()->inventory_reservation_id]),
            'unbound promotion' => $valid(['promotion_use_id' => $f['order']->attempt()->sole()->promotion_use_id]),
            'before the order' => $valid(['cancelled_at' => $b['order']->created_at->subSecond()->format('Y-m-d H:i:s')]),
            'short public id' => $valid(['public_id' => 'short']),
            'empty evidence' => $valid(['evidence_ciphertext' => '']),
            'duplicate of the retained cancellation' => array_merge((array) DB::table('test_buyer_order_cancellations')->sole(), ['id' => null, 'public_id' => (string) Str::uuid()]),
        ];
        foreach ($refused as $label => $row) {
            unset($row['id']);
            $this->assertDatabaseRefuses(fn () => DB::table('test_buyer_order_cancellations')->insert($row), $label);
        }
        // An order with a checkout intent: its otherwise valid row is refused.
        $intentOrder = CheckoutFixtures::prepared();
        $intentAttempt = $intentOrder['order']->attempt()->sole();
        app(HostedCheckout::class)->start($intentOrder['order']->public_id, InventoryFixtures::OWNER);
        $this->assertDatabaseRefuses(fn () => DB::table('test_buyer_order_cancellations')->insert($valid(['order_id' => $intentOrder['order']->id,
            'order_attempt_id' => $intentAttempt->id, 'inventory_reservation_id' => $intentAttempt->inventory_reservation_id])), 'checkout intent exists');
        // With a valid row for B in place, a release that also rewrites the attempt binding is refused.
        foreach (['attempt_id' => (string) Str::uuid(), 'pending_at' => $bAttempt->expires_at->subSeconds(5)->format('Y-m-d H:i:s'),
            'expired_at' => now()->format('Y-m-d H:i:s')] as $field => $value) {
            DB::beginTransaction();
            try {
                DB::table('test_buyer_order_cancellations')->insert($valid());
                $this->assertDatabaseRefuses(fn () => DB::table('inventory_reservations')->where('id', $bAttempt->inventory_reservation_id)
                    ->update(['state' => 'released', $field => $value]), 'release rewriting '.$field);
            } finally {
                DB::rollBack();
            }
        }
        $this->assertDatabaseRefuses(fn () => DB::table('test_buyer_order_cancellations')->update(['cancelled_at' => now()->addMinute()]));
        $this->assertDatabaseRefuses(fn () => DB::table('test_buyer_order_cancellations')->delete());
        foreach ([['state' => 'pending'], ['state' => 'consumed', 'consumed_at' => now()]] as $change) {
            $this->assertDatabaseRefuses(fn () => DB::table('inventory_reservations')->where('id', $f['order']->attempt()->sole()->inventory_reservation_id)->update($change));
        }
        $this->assertDatabaseHas('inventory_reservations', ['id' => $bAttempt->inventory_reservation_id, 'state' => 'pending']);
        $this->assertSame(1, TestBuyerOrderCancellation::count());
    }

    public function test_an_exclusive_hold_is_freed_at_once_for_another_buyer(): void
    {
        $f = CheckoutFixtures::prepared(true);
        $this->refused('INVENTORY_UNAVAILABLE', 409, fn () => app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']));
        $this->cancel($f);
        $quote = app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']);
        app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER);
        $this->assertDatabaseHas('inventory_reservations', ['quote_id' => $quote->id, 'state' => 'held']);
        $this->assertDatabaseHas('inventory_reservations', ['quote_id' => $f['order']->quote_id, 'state' => 'released']);
        $this->assertDatabaseCount('exclusive_sales', 0);
    }

    private function postEmpty(string $url, string $body = '{}'): TestResponse
    {
        return $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
    }

    private function assertPrivate(TestResponse $response): TestResponse
    {
        return $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vary', 'Cookie')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_http_cancel_is_owned_private_exact_and_rate_limited_like_the_checkout_start(): void
    {
        $f = InventoryFixtures::selection();
        $quoteId = $this->postJson('/quotes', ['items' => $f['items']], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('quote.id');
        $quote = Quote::where('public_id', $quoteId)->sole();
        app(PriceQuote::class)->create($quoteId, $quote->owner_key);
        $orderId = $this->postJson('/orders', OrderFixtures::request($quote, $quote->owner_key), ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('order.id');
        $url = '/orders/'.$orderId.'/cancel';
        $this->assertPrivate($this->getJson('/orders/'.$orderId.'/checkout')->assertOk())->assertJsonPath('checkout.buyerCancel', 'available');
        // Every POST here shares the `quotes-create` budget (10 a minute) with quote, order and checkout writes, as intended.
        foreach (['[]', '{"force":true}'] as $body) {
            $this->assertPrivate($this->postEmpty($url, $body)->assertUnprocessable());
        }
        $this->postEmpty($url.'?confirm=1')->assertUnprocessable();
        $this->assertDatabaseCount('test_buyer_order_cancellations', 0);
        $response = $this->assertPrivate($this->postEmpty($url)->assertOk())->assertJsonPath('checkout.buyerCancel', 'cancelled')
            ->assertJsonPath('checkout.status', 'not_started')->assertJsonPath('checkout.orderId', $orderId);
        $response->assertDontSee($quote->owner_key, false);
        $this->postEmpty('/orders/'.$orderId.'/checkout')->assertStatus(410)->assertExactJson(['code' => 'CHECKOUT_EXPIRED']);
        $this->assertSame(1, TestBuyerOrderCancellation::count());
        // Another browser session owns nothing here.
        $this->flushSession();
        $this->assertPrivate($this->postEmpty($url)->assertNotFound())->assertExactJson(['code' => 'ORDER_NOT_FOUND']);
        config(['buyer-cancel.enabled' => false]);
        $this->postEmpty('/orders/'.Str::uuid().'/cancel')->assertForbidden()->assertExactJson(['code' => 'ORDER_CANCEL_UNAVAILABLE']);
        $route = Route::getRoutes()->getByName('orders.cancel');
        $this->assertSame(['POST'], $route->methods());
        $start = Route::getRoutes()->getByName('orders.checkout');
        $this->assertContains('throttle:10,1,quotes-create', $route->gatherMiddleware());
        $this->assertSame($start->gatherMiddleware(), $route->gatherMiddleware());
        $this->assertSame([120, 10], [$route->locksFor(), $route->waitsFor()]);
        $this->assertSame([$start->locksFor(), $start->waitsFor()], [$route->locksFor(), $route->waitsFor()]);
    }

    public function test_staff_page_shows_an_order_cancelled_by_its_buyer(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $f = CheckoutFixtures::prepared();
        $this->cancel($f);
        $admin = LicenseFixtures::admin();
        $this->actingAs($admin);
        Livewire::test(ListTestUnpaidOrders::class)->assertSee('Cancelled by buyer');
        Livewire::test(ListTestUnpaidOrders::class)->mountTableAction('releaseHistory', $f['order'])
            ->assertMountedActionModalSee('Cancelled by the buyer before it was sent to Stripe')
            ->assertMountedActionModalSee(TestBuyerOrderCancellation::sole()->public_id);
    }

    public function test_migration_rollback_refuses_retained_history_and_restores_the_previous_guards_when_empty(): void
    {
        $migration = require database_path('migrations/2026_11_12_001000_test_buyer_order_cancellations.php');
        $resources = fn () => DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->whereIn('name', ['inventory_reservations_transition', 'promotion_uses_guard_update'])->orderBy('name')->pluck('sql')->all()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->whereIn('TRIGGER_NAME', ['inventory_reservations_transition', 'promotion_uses_guard_update'])->orderBy('TRIGGER_NAME')->pluck('ACTION_STATEMENT')->all();
        $installed = $resources();
        $migration->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('test_buyer_order_cancellations'));
        $this->assertNotSame($installed, $resources());
        // The never-sent release migration owns the guards again, exactly: its own rollback and reinstall succeed.
        $previous = require database_path('migrations/2026_11_11_001000_test_unsent_order_releases.php');
        $previous->down();
        $previous->up();
        $migration->up();
        $this->assertSame($installed, $resources());
        $f = CheckoutFixtures::prepared();
        $this->cancel($f);
        try {
            $migration->down();
            $this->fail('Retained buyer cancellation history was dropped.');
        } catch (LogicException $error) {
            $this->assertSame('Buyer cancellation history must be retained.', $error->getMessage());
        }
        $this->assertSame(1, TestBuyerOrderCancellation::count());
        $this->assertSame($installed, $resources());
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
