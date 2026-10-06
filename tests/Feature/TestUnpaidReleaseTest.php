<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\TestUnpaidRelease;
use App\Domain\Commerce\Models\TestUnpaidReleaseEvent;
use App\Domain\Commerce\Models\TestUnpaidReleaseWork;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Operations\InspectRetainedTestPaymentException;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\UnpaidRelease\ReadUnpaidRelease;
use App\Domain\Commerce\UnpaidRelease\ReleaseTestOrderResources;
use App\Filament\Resources\TestUnpaidOrderResource\Pages\ListTestUnpaidOrders;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\UnpaidReleaseFixtures as F;
use Tests\TestCase;

class TestUnpaidReleaseTest extends TestCase
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

    public static function terminalProofs(): array
    {
        return ['null payment, no promotion' => [false, false], 'canceled payment, promotion' => [true, true]];
    }

    #[DataProvider('terminalProofs')]
    public function test_release_retains_original_evidence_and_is_idempotent_without_provider_writes(bool $paymentIntent, bool $promoted): void
    {
        $f = F::started($this->gateway, $promoted, $paymentIntent);
        Queue::fake();
        $order = $f['order'];
        $original = app(ReadOrder::class)->verify($order);
        $attributes = $order->getAttributes();
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $calls = count($this->gateway->calls);
        $service = app(ReleaseTestOrderResources::class);
        $key = (string) Str::uuid();
        $review = $service->review($order->public_id, $f['admin']);
        $this->assertSame(0, $review['sequence']);
        $this->assertSame('unverified', $review['status']);
        $this->assertSame([$order->id], $service->query()->pluck('orders.id')->all());
        $result = $service->release($order->public_id, $f['admin'], $key, 0);
        $this->assertSame(['testOnly' => true, 'status' => 'released', 'sequence' => 2], $result);
        $this->assertSame($result, $service->release($order->public_id, $f['admin'], $key, 0));
        $this->assertSame(2, TestUnpaidReleaseEvent::count());
        $this->assertSame(1, TestUnpaidRelease::count());
        $this->assertSame($attributes, $order->fresh()->getAttributes());
        $this->assertSame($original, app(ReadOrder::class)->verify($order));
        $after = PaymentFixtures::unchangedBusinessEvidence();
        foreach (['inventory_reservations', 'promotion_uses'] as $table) {
            unset($before[$table], $after[$table]);
        }
        $this->assertSame($before, $after);
        $attempt = $order->attempt()->sole();
        $this->assertDatabaseHas('inventory_reservations', ['id' => $attempt->inventory_reservation_id, 'state' => 'released', 'attempt_id' => $attempt->public_id, 'consumed_at' => null]);
        if ($promoted) {
            $this->assertDatabaseHas('promotion_uses', ['id' => $attempt->promotion_use_id, 'state' => 'released', 'consumed_at' => null]);
        }
        $providerCalls = array_slice($this->gateway->calls, $calls);
        $this->assertSame($paymentIntent ? ['account', 'retrieve', 'payment_intent', 'retrieve'] : ['account', 'retrieve', 'retrieve'], array_column($providerCalls, 'operation'));
        $this->assertSame([0], array_values(array_unique(array_column($providerCalls, 'transaction_level'))));
        $this->assertSame('released', $service->review($order->public_id, $f['admin'])['status']);
        $this->assertNull(TestUnpaidReleaseWork::sole()->claim_token);
        $this->assertSame([], DB::table('verified_payments')->get()->all());
        $this->assertSame([], DB::table('order_finalizations')->get()->all());
        $this->assertNull(app(HostedCheckout::class)->status($order->public_id, InventoryFixtures::OWNER)['url']);
        $this->assertSame('expired', app(HostedCheckout::class)->status($order->public_id, InventoryFixtures::OWNER)['status']);
        Queue::assertNothingPushed();
    }

    public function test_late_confirmed_money_is_retained_without_reopening_released_resources_even_with_pre_cutoff_inspection(): void
    {
        $f = F::started($this->gateway);
        $original = app(ReadOrder::class)->verify($f['order']);
        $expired = $this->gateway->session;
        $this->gateway->session['status'] = 'complete';
        $this->gateway->session['payment_status'] = 'paid';
        $this->gateway->payment = PaymentFixtures::payment($this->gateway->session);
        $inspection = app(VerifyTestPayment::class)->inspect($f['session']->provider_session_id, $f['intent']);
        $this->gateway->session = $expired;
        $this->gateway->payment['status'] = 'canceled';
        $this->gateway->payment['amount_received'] = 0;
        // Retain an expired checkout observation too: the ordinary terminal regression guard must remain.
        app(HostedCheckout::class)->reconcile($f['order']->public_id, InventoryFixtures::OWNER);
        $history = DB::table('checkout_observations')->get()->toJson();
        $this->assertSame('released', app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0)['status']);
        $this->assertSame('awaiting_finalization', app(VerifyTestPayment::class)->commit($inspection, null));
        $this->assertSame($history, DB::table('checkout_observations')->get()->toJson());
        $payment = VerifiedPayment::sole();
        $this->assertTrue($payment->confirmed_at->lessThan($f['order']->attempt()->sole()->expires_at));
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($payment->id));
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($payment->id));
        $this->assertSame('released_attempt', OrderFinalization::sole()->reason);
        $detail = app(InspectRetainedTestPaymentException::class)->handle(OrderFinalization::sole()->public_id, $f['admin']);
        $this->assertSame('verified', $detail['inspectionStatus']);
        $this->assertSame('released', $detail['inventoryState']);
        $this->assertSame('released', $detail['promotionState']);
        $this->assertStringContainsString('Released', view('admin.test-payment-exception-inspection', compact('detail'))->render());
        $this->assertSame($original, app(ReadOrder::class)->verify($f['order']));
        app(ReadUnpaidRelease::class)->verify(TestUnpaidRelease::sole(), $f['order'], $original);
        foreach (['license_grants', 'exclusive_sales', 'pending_entitlements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('fulfillment_outbox', 1);
        $this->assertDatabaseHas('inventory_reservations', ['state' => 'released', 'consumed_at' => null]);
        $this->assertDatabaseHas('promotion_uses', ['state' => 'released', 'consumed_at' => null]);
        $this->assertDatabaseCount('payment_observations', 1);
    }

    public static function nonProofs(): array
    {
        $cases = ['open', 'complete', 'processing', 'requires_capture', 'succeeded', 'received', 'capturable',
            'missing_pi', 'missing_recovery', 'missing_recovered_from', 'recovery_enabled', 'recovered', 'foreign_session',
            'foreign_account', 'foreign_pi', 'foreign_metadata', 'amount', 'currency', 'livemode', 'incomplete_items',
            'second_read_changed', 'provider_error', 'empty_provider_session'];

        return array_combine($cases, array_map(static fn (string $case): array => [$case], $cases));
    }

    #[DataProvider('nonProofs')]
    public function test_nonterminal_incomplete_or_foreign_evidence_never_releases_resources(string $case): void
    {
        $f = F::started($this->gateway);
        $before = PaymentFixtures::unchangedBusinessEvidence();
        if ($case === 'open') {
            $this->gateway->session['status'] = 'open';
            $this->gateway->session['url'] = 'https://checkout.stripe.com/c/pay/'.$f['session']->provider_session_id;
        } elseif ($case === 'complete') {
            $this->gateway->session['status'] = 'complete';
        } elseif (in_array($case, ['processing', 'requires_capture', 'succeeded'], true)) {
            $this->gateway->payment['status'] = $case;
        } elseif ($case === 'received') {
            $this->gateway->payment['amount_received'] = 1;
        } elseif ($case === 'capturable') {
            $this->gateway->payment['amount_capturable'] = 1;
        } elseif ($case === 'missing_pi') {
            unset($this->gateway->session['payment_intent']);
        } elseif ($case === 'missing_recovery') {
            unset($this->gateway->session['after_expiration']);
        } elseif ($case === 'missing_recovered_from') {
            unset($this->gateway->session['recovered_from']);
        } elseif ($case === 'recovery_enabled') {
            $this->gateway->session['after_expiration'] = ['recovery' => ['enabled' => true]];
        } elseif ($case === 'recovered') {
            $this->gateway->session['recovered_from'] = 'cs_test_OLD';
        } elseif ($case === 'foreign_session') {
            $this->gateway->session['id'] = 'cs_test_OTHER';
        } elseif ($case === 'foreign_account') {
            $this->gateway->accountResponse['id'] = 'acct_OTHER';
        } elseif ($case === 'foreign_pi') {
            $this->gateway->payment['id'] = 'pi_OTHER';
        } elseif ($case === 'foreign_metadata') {
            $this->gateway->payment['metadata']['attempt_id'] = (string) Str::uuid();
        } elseif ($case === 'amount') {
            $this->gateway->payment['amount']++;
        } elseif ($case === 'currency') {
            $this->gateway->payment['currency'] = 'gbp';
        } elseif ($case === 'livemode') {
            $this->gateway->payment['livemode'] = true;
        } elseif ($case === 'incomplete_items') {
            $this->gateway->session['line_items']['has_more'] = true;
        } elseif ($case === 'second_read_changed') {
            $count = 0;
            $this->gateway->onRetrieve = function () use (&$count) {
                $response = $this->gateway->session;
                if (++$count === 2) {
                    $response['payment_intent'] = null;
                }

                return $response;
            };
        } elseif ($case === 'provider_error') {
            $this->gateway->onRetrieve = fn () => throw new RuntimeException('synthetic timeout');
        } elseif ($case === 'empty_provider_session') {
            // The scope requires a bound session; a fresh prepared order with an uncertain POST is separate.
            $this->assertTrue($f['session']->exists);
            $this->gateway->onRetrieve = fn () => [];
        }
        $result = app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0);
        $this->assertContains($result['status'], ['not_unpaid', 'attention', 'unavailable']);
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertDatabaseCount('test_unpaid_releases', 0);
        $this->assertDatabaseCount('verified_payments', 0);
        $this->assertDatabaseCount('order_finalizations', 0);
        $this->assertDatabaseCount('test_unpaid_release_events', 2);
        $this->assertNull(TestUnpaidReleaseWork::sole()->claim_token);
    }

    public static function withdrawnControls(): array
    {
        return [['disabled'], ['policy'], ['account'], ['staff'], ['mfa'], ['production']];
    }

    #[DataProvider('withdrawnControls')]
    public function test_authority_policy_and_account_are_rechecked_after_provider_reads(string $control): void
    {
        $f = F::started($this->gateway);
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $this->gateway->onRetrieve = function () use ($control, $f) {
            match ($control) {
                'disabled' => config(['unpaid-release.enabled' => false]),
                'policy' => config(['unpaid-release.policy' => '{}']),
                'account' => config(['payments.stripe.account_id' => 'acct_OTHER']),
                'staff' => DB::table('users')->where('id', $f['admin']->id)->update(['is_admin' => false]),
                'mfa' => Filament::getPanel('admin')->multiFactorAuthentication([], isRequired: true),
                'production' => $this->app->instance('env', 'production'),
            };

            return $this->gateway->session;
        };
        try {
            app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0);
            $this->fail('Withdrawn control was ignored.');
        } catch (RuntimeException|AuthorizationException|ModelNotFoundException) {
            $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
            $this->assertDatabaseCount('test_unpaid_releases', 0);
            $this->assertDatabaseCount('test_unpaid_release_events', 1);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_verified_payment_winning_between_get_and_commit_keeps_pending_resources(): void
    {
        $f = F::started($this->gateway);
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $expired = $this->gateway->session;
        $this->gateway->session['status'] = 'complete';
        $this->gateway->session['payment_status'] = 'paid';
        $this->gateway->payment = PaymentFixtures::payment($this->gateway->session);
        $inspection = app(VerifyTestPayment::class)->inspect($f['session']->provider_session_id, $f['intent']);
        $this->gateway->session = $expired;
        $this->gateway->payment['status'] = 'canceled';
        $this->gateway->payment['amount_received'] = 0;
        $count = 0;
        $this->gateway->onRetrieve = function () use (&$count, $inspection) {
            if (++$count === 2) {
                $this->assertSame('awaiting_finalization', app(VerifyTestPayment::class)->commit($inspection, null));
            }

            return $this->gateway->session;
        };
        $this->assertSame('payment_recorded', app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0)['status']);
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertDatabaseCount('test_unpaid_releases', 0);
        $this->assertDatabaseCount('verified_payments', 1);
    }

    public static function failures(): array
    {
        return [['promotion'], ['audit']];
    }

    #[DataProvider('failures')]
    public function test_release_proof_event_and_both_resource_updates_roll_back_together(string $failure): void
    {
        $f = F::started($this->gateway);
        $before = PaymentFixtures::unchangedBusinessEvidence();
        if ($failure === 'promotion') {
            DB::unprepared(DB::getDriverName() === 'sqlite'
                ? "CREATE TRIGGER synthetic_release_failure BEFORE UPDATE ON promotion_uses WHEN NEW.state = 'released' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END"
                : "CREATE TRIGGER synthetic_release_failure BEFORE UPDATE ON promotion_uses FOR EACH ROW BEGIN IF NEW.state = 'released' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic failure'; END IF; END");
        } else {
            Event::listen('eloquent.creating: '.AuditEvent::class, function ($event) {
                if ($event->action === 'commerce.unpaid_release.observed') {
                    throw new RuntimeException('synthetic audit failure');
                }
            });
        }
        try {
            app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0);
            $this->fail('Partial release was accepted.');
        } catch (RuntimeException) {
            $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
            $this->assertDatabaseCount('test_unpaid_releases', 0);
            $this->assertDatabaseCount('test_unpaid_release_events', 1);
            $this->assertSame(1, TestUnpaidReleaseWork::sole()->sequence);
        } finally {
            if ($failure === 'promotion') {
                DB::unprepared('DROP TRIGGER synthetic_release_failure');
            }
        }
    }

    public function test_exact_request_recovery_uses_fresh_proof_and_stale_actor_or_review_cannot_replay(): void
    {
        $f = F::started($this->gateway);
        $key = (string) Str::uuid();
        $service = app(ReleaseTestOrderResources::class);
        $this->gateway->onRetrieve = function () {
            $this->travel(121)->seconds();

            return $this->gateway->session;
        };
        $this->assertSame('stale', $service->release($f['order']->public_id, $f['admin'], $key, 0)['status']);
        $this->assertDatabaseCount('test_unpaid_release_events', 1);
        $this->gateway->onRetrieve = null;
        $calls = count($this->gateway->calls);
        $this->assertSame('released', $service->release($f['order']->public_id, $f['admin'], $key, 0)['status']);
        $this->assertSame(4, count($this->gateway->calls) - $calls);
        foreach ([[$f['admin'], 1], [LicenseFixtures::admin(), 0]] as [$actor, $sequence]) {
            try {
                $service->release($f['order']->public_id, $actor, $key, $sequence);
                $this->fail('Changed replay accepted.');
            } catch (RuntimeException) {
                $this->assertDatabaseCount('test_unpaid_releases', 1);
            }
        }
        config(['unpaid-release.enabled' => false]);
        $this->assertSame('released', $service->release($f['order']->public_id, $f['admin'], $key, 0)['status']);
    }

    public static function replayCorruptions(): array
    {
        return [['missing'], ['ciphertext'], ['event']];
    }

    #[DataProvider('replayCorruptions')]
    public function test_released_replay_revalidates_its_exact_retained_proof(string $corruption): void
    {
        $f = F::started($this->gateway);
        $key = (string) Str::uuid();
        $service = app(ReleaseTestOrderResources::class);
        $this->assertSame('released', $service->release($f['order']->public_id, $f['admin'], $key, 0)['status']);
        $calls = $this->gateway->calls;
        DB::unprepared('DROP TRIGGER test_unpaid_releases_immutable_'.($corruption === 'missing' ? 'delete' : 'update'));
        if ($corruption === 'missing') {
            DB::table('test_unpaid_releases')->delete();
        } else {
            DB::table('test_unpaid_releases')->update($corruption === 'ciphertext' ? ['evidence_hash' => str_repeat('0', 64)] : ['event_id' => TestUnpaidReleaseEvent::where('kind', 'requested')->sole()->id]);
        }
        try {
            $service->release($f['order']->public_id, $f['admin'], $key, 0);
            $this->fail('Corrupted successful replay accepted.');
        } catch (RuntimeException) {
            $this->assertSame($calls, $this->gateway->calls);
        }
        $this->assertDatabaseHas('inventory_reservations', ['state' => 'released']);
        $this->assertDatabaseCount('test_unpaid_release_events', 2);
    }

    public static function initialRefusals(): array
    {
        return [['production'], ['disabled'], ['staff'], ['unbound'], ['outer_transaction']];
    }

    #[DataProvider('initialRefusals')]
    public function test_invalid_initial_scope_never_calls_the_provider_or_changes_resources(string $case): void
    {
        if ($case === 'unbound') {
            $f = CheckoutFixtures::prepared(true, true);
            $this->gateway->onCreate = fn () => throw new RuntimeException('synthetic ambiguous dispatch');
            try {
                app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER);
            } catch (QuoteException) {
            }
            $f['admin'] = LicenseFixtures::admin();
        } else {
            $f = F::started($this->gateway);
        }
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $calls = $this->gateway->calls;
        if ($case === 'production') {
            $this->app->instance('env', 'production');
        }
        if ($case === 'disabled') {
            config(['unpaid-release.enabled' => false]);
        }
        if ($case === 'staff') {
            $f['admin']->forceFill(['is_admin' => false])->save();
        }
        if ($case === 'outer_transaction') {
            DB::beginTransaction();
        }
        try {
            $result = app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0);
            $this->assertSame('unbound', $case);
            $this->assertSame('unavailable', $result['status']);
        } catch (RuntimeException|AuthorizationException) {
            $this->assertNotSame('unbound', $case);
        } finally {
            $this->app->instance('env', 'testing');
            if ($case === 'outer_transaction') {
                DB::rollBack();
            }
        }
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertSame($calls, $this->gateway->calls);
        $this->assertDatabaseCount('test_unpaid_releases', 0);
        $this->assertDatabaseCount('test_unpaid_release_events', 0);
    }

    public function test_activated_exclusive_selection_stays_unavailable_until_release_commits_then_new_hold_is_allowed(): void
    {
        $f = F::started($this->gateway, false);
        try {
            app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']);
            $this->fail('Another exclusive selection ignored the pending order.');
        } catch (QuoteException $error) {
            $this->assertSame('INVENTORY_UNAVAILABLE', $error->errorCode);
        }
        $this->assertSame('released', app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0)['status']);
        $quote = app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']);
        app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER);
        $this->assertDatabaseHas('inventory_reservations', ['quote_id' => $quote->id, 'state' => 'held']);
        $this->assertDatabaseHas('inventory_reservations', ['quote_id' => $f['order']->quote_id, 'state' => 'released']);
        $this->assertDatabaseCount('exclusive_sales', 0);
    }

    public function test_real_operator_action_releases_then_history_and_replay_show_verified_retained_state(): void
    {
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $f = F::started($this->gateway);
        $this->actingAs($f['admin']);
        $original = app(ReadOrder::class)->verify($f['order']);
        $page = ListTestUnpaidOrders::class;
        $component = Livewire::test($page)->mountTableAction('releaseUnpaid', $f['order']);
        $data = $component->get('mountedActions.0.data');
        $this->assertSame(0, $data['sequence']);
        $this->assertTrue(Str::isUuid($data['request_id']));
        $component->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Test resources released');
        $this->assertDatabaseCount('test_unpaid_releases', 1);
        $calls = $this->gateway->calls;
        Livewire::test($page)->mountTableAction('releaseHistory', $f['order'])
            ->assertMountedActionModalSee(TestUnpaidRelease::sole()->public_id)
            ->assertMountedActionModalSee('released')->assertMountedActionModalDontSee($f['order']->payload_ciphertext);
        Livewire::test($page)->mountTableAction('releaseUnpaid', $f['order'])
            ->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Test resources released');
        $this->assertSame($calls, $this->gateway->calls);
        $this->assertSame($original, app(ReadOrder::class)->verify($f['order']));
        $this->assertDatabaseCount('test_unpaid_release_events', 2);
        $this->assertDatabaseCount('license_grants', 0);
    }
}
