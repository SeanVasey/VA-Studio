<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\ManageTestDeliveryControl;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Delivery\TestDeliveryStaffOperations;
use App\Filament\Resources\TestTrackOrderResource;
use App\Filament\Resources\TestTrackOrderResource\Pages\ListTestTrackOrders;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\ActivationFixtures;
use Tests\Support\CheckoutFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * Staff view of Stripe-test track orders and the per-order delivery control, over the real Filament/Livewire boundary and the real
 * payment, finalization, contract, activation and delivery services (fake Stripe gateway, synthetic renderer; no network).
 */
class TestTrackOrderResourceTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $actor;

    private object $gateway;

    private string $originalEnv = 'testing';

    private int $orders = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = $this->app['env'];
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        DeliveryFixtures::configure();
        Queue::fake();
        $this->gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $this->app->instance(PrepareTestDeliveryStream::class, DeliveryFixtures::observingStreams());
        $this->actor = LicenseFixtures::admin();
        $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        $this->app->instance('env', $this->originalEnv);
        parent::tearDown();
    }

    /** A new order through Stripe test checkout under its own session and PaymentIntent ids (all orders share one fake gateway). */
    private function started(): array
    {
        $n = ++$this->orders;
        $this->gateway->onCreate = fn (array $params): array => CheckoutFixtures::session($params, 'cs_test_TRACKSTAFF'.$n);
        $fixture = PaymentFixtures::started($this->gateway);
        $this->gateway->session['payment_intent'] = 'pi_TRACKSTAFF'.$n;
        $this->gateway->payment['id'] = 'pi_TRACKSTAFF'.$n;

        return $fixture;
    }

    /** One order taken exactly to the named stage by the real services. */
    private function order(string $stage): array
    {
        $fixture = $this->started();
        if ($stage === 'unpaid') {
            return $fixture;
        }
        $fixture = FinalizationFixtures::confirm($fixture);
        if ($stage === 'paid') {
            return $fixture;
        }
        $fixture = ContractFixtures::finalize($fixture);
        if ($stage === 'granted') {
            return $fixture;
        }
        $fixture = ActivationFixtures::issue($fixture);

        return $stage === 'contracted' ? $fixture : DeliveryFixtures::activate($fixture, enable: false);
    }

    private function trackList(): Testable
    {
        $this->actingAs($this->actor);

        return Livewire::test(ListTestTrackOrders::class);
    }

    private function refused(string $body): Notification
    {
        return Notification::make()->title('Not done')->body($body)->danger();
    }

    private function done(string $body): Notification
    {
        return Notification::make()->title('Done')->body($body)->success();
    }

    private function audit(string $action): array
    {
        return DB::table('audit_events')->where('action', $action)->orderBy('id')->get()->map(fn (object $row): array => [
            $row->actor_id, json_decode($row->context, true)])->all();
    }

    private function staffAudit(string $order, string $result, ?int $version, string $reference): array
    {
        return [$this->actor->id, ['order_public_id' => $order, 'result' => $result, 'control_version' => $version,
            'reference_hash' => hash('sha256', $reference), 'test_only' => true]];
    }

    private function issue(array $f): void
    {
        app(IssueTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $f['grant']->public_id, 'contract', (string) Str::uuid());
    }

    private function deliveryRefused(array $f, string $reason): void
    {
        try {
            $this->issue($f);
            $this->fail('Delivery was authorized.');
        } catch (DeliveryException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    public function test_the_page_is_staff_only_and_absent_wherever_the_delivery_control_cannot_act(): void
    {
        $this->order('ready');
        $this->get('/admin/test-track-orders')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/test-track-orders')->assertForbidden();
        $this->actingAs($this->actor);
        $this->assertTrue(TestTrackOrderResource::canAccess());
        $this->assertTrue(TestTrackOrderResource::shouldRegisterNavigation());
        $this->assertSame('Test commerce', TestTrackOrderResource::getNavigationGroup());
        $this->get('/admin/test-track-orders')->assertOk()->assertSee('Test track orders');
        foreach (['create', 'update', 'delete', 'deleteAny', 'view', 'restore', 'replicate'] as $ability) {
            $this->assertFalse(TestTrackOrderResource::can($ability), $ability);
        }

        // Staff without the catalog permission are refused, and an already open Livewire page is rechecked.
        $component = Livewire::test(ListTestTrackOrders::class)->assertSuccessful();
        $this->actor->forceFill(['is_admin' => false])->save();
        $this->actingAs($this->actor->fresh());
        $this->assertFalse(TestTrackOrderResource::canAccess());
        $component->call('$refresh')->assertForbidden();
        $this->get('/admin/test-track-orders')->assertForbidden();
        $this->actor->forceFill(['is_admin' => true])->save();

        // Exactly where the console command refuses: production, live mode or no valid configured account.
        foreach ([fn () => $this->app->instance('env', 'production'), fn () => config(['payments.stripe.mode' => 'live']),
            fn () => config(['payments.stripe.account_id' => 'not-an-account'])] as $close) {
            $this->app->instance('env', $this->originalEnv);
            config(['payments.stripe.mode' => 'test', 'payments.stripe.account_id' => CheckoutFixtures::ACCOUNT]);
            $this->actingAs($this->actor->fresh());
            $open = Livewire::test(ListTestTrackOrders::class)->assertSuccessful();
            $close();
            $this->assertFalse(TestTrackOrderResource::canAccess());
            $this->assertFalse(TestTrackOrderResource::shouldRegisterNavigation());
            $this->assertFalse(TestTrackOrderResource::can('viewAny'));
            $this->get('/admin/test-track-orders')->assertNotFound();
            $open->call('$refresh')->assertNotFound();
        }
        $this->app->instance('env', $this->originalEnv);
        config(['payments.stripe.mode' => 'test', 'payments.stripe.account_id' => CheckoutFixtures::ACCOUNT]);

        // With the owner-delivery switch off the page stays open (the command can still block); enabling is refused by the service.
        config(['delivery.test_access_enabled' => false]);
        $this->assertTrue(TestTrackOrderResource::canAccess());
        $this->get('/admin/test-track-orders')->assertOk();
    }

    public function test_the_table_shows_progress_without_buyer_identity_provider_ids_or_file_paths(): void
    {
        $ready = $this->order('ready');
        $contracted = $this->order('contracted');
        $granted = $this->order('granted');
        $paid = $this->order('paid');
        $unpaid = $this->order('unpaid');
        $html = $this->actingAs($this->actor)->get('/admin/test-track-orders')->assertOk()
            ->assertSee([$ready['order']->public_id, $contracted['order']->public_id, $granted['order']->public_id, $paid['order']->public_id, $unpaid['order']->public_id])
            ->assertSee(['Awaiting payment', 'Paid, grant pending', 'Granted, contract pending', 'Contract issued, activation pending', 'Ready, delivery not enabled'])
            ->assertSee('Guest')->assertSee((string) DB::table('tracks')->where('id', $ready['revision']->track_id)->value('title'))->getContent();
        $payment = VerifiedPayment::query()->where('order_id', $ready['order']->id)->sole();
        $private = [InventoryFixtures::OWNER, 'cs_test_TRACKSTAFF1', $payment->provider_payment_intent_id, CheckoutFixtures::ACCOUNT,
            'payload_ciphertext', 'evidence_ciphertext', 'contracts/', 'purchased/'];
        foreach ($private as $value) {
            $this->assertStringNotContainsString($value, $html, $value);
        }

        $service = app(TestDeliveryStaffOperations::class);
        $rows = $service->orders()->get()->keyBy('public_id');
        $this->assertCount(5, $rows);
        $this->assertSame(['Ready, delivery not enabled', 'Contract issued, activation pending', 'Granted, contract pending', 'Paid, grant pending', 'Awaiting payment'],
            array_map(fn (array $f): string => $service->stage($rows[$f['order']->public_id]), [$ready, $contracted, $granted, $paid, $unpaid]));

        // "Awaiting delivery" lists activated orders whose delivery is not enabled, and drops one once it is enabled.
        $this->trackList()->filterTable('delivery', 'awaiting')->assertCanSeeTableRecords([$ready['order']])
            ->assertCanNotSeeTableRecords([$contracted['order'], $granted['order'], $paid['order'], $unpaid['order']]);
        $this->assertTrue($service->controlDelivery($this->actor, $ready['order']->public_id, 'enable', 0, 'ALPHA-TRACK-1')['ok']);
        $this->trackList()->filterTable('delivery', 'awaiting')->assertCanNotSeeTableRecords([$ready['order']]);
        $this->trackList()->assertCanSeeTableRecords([$ready['order']])->assertSee('Delivery enabled')->assertSee('Enabled (version 1)');
    }

    public function test_enable_provisions_then_enables_is_attributed_opens_downloads_and_block_closes_them(): void
    {
        $f = $this->order('ready');
        $order = $f['order'];
        $this->deliveryRefused($f, 'blocked');
        $component = $this->trackList()->assertTableActionVisible('enableDelivery', $order)->assertTableActionHidden('blockDelivery', $order);
        $component->mountTableAction('enableDelivery', $order)->assertTableActionDataSet(['expected_version' => 0])
            ->assertMountedActionModalSee('Enabling lets the buyer download')
            ->fillForm(['reference' => 'not a reference'])->callMountedTableAction()->assertHasTableActionErrors(['reference']);
        $this->assertSame(0, TestDeliveryControl::query()->count());

        $this->trackList()->mountTableAction('enableDelivery', $order)->fillForm(['reference' => 'ALPHA-TRACK-1'])->callMountedTableAction()
            ->assertNotified($this->done('Delivery is now enabled (version 1).'));
        $control = TestDeliveryControl::query()->sole();
        $this->assertSame([false, 1], [$control->blocked, $control->control_version]);
        // The control service's own audit events (the command's names), then the staff attribution with the resulting version.
        $this->assertSame([[$this->actor->id, ['order_public_id' => $order->public_id, 'blocked' => true, 'control_version' => 0,
            'reference_hash' => hash('sha256', 'ALPHA-TRACK-1'), 'test_only' => true]]], $this->audit('commerce.delivery.control_provisioned'));
        $this->assertSame([[$this->actor->id, ['order_public_id' => $order->public_id, 'blocked' => false, 'control_version' => 1,
            'reference_hash' => hash('sha256', 'ALPHA-TRACK-1'), 'test_only' => true]]], $this->audit('commerce.delivery.control_changed'));
        $this->assertSame([$this->staffAudit($order->public_id, 'accepted', 1, 'ALPHA-TRACK-1')], $this->audit('commerce.delivery.staff_action.delivery_enable'));
        $this->issue($f);

        $this->trackList()->assertTableActionHidden('enableDelivery', $order)->assertTableActionVisible('blockDelivery', $order)
            ->mountTableAction('blockDelivery', $order)->assertTableActionDataSet(['expected_version' => 1])
            ->fillForm(['reference' => 'ALPHA-TRACK-1/withdrawn'])->callMountedTableAction()
            ->assertNotified($this->done('Delivery is now blocked (version 2).'));
        $this->assertSame([true, 2], [TestDeliveryControl::query()->sole()->blocked, TestDeliveryControl::query()->sole()->control_version]);
        $this->assertSame([$this->staffAudit($order->public_id, 'accepted', 2, 'ALPHA-TRACK-1/withdrawn')], $this->audit('commerce.delivery.staff_action.delivery_block'));
        $this->deliveryRefused($f, 'blocked');

        // Re-enabling from the page carries the current version.
        $this->trackList()->assertTableActionVisible('enableDelivery', $order)->mountTableAction('enableDelivery', $order)
            ->assertTableActionDataSet(['expected_version' => 2])->fillForm(['reference' => 'ALPHA-TRACK-2'])->callMountedTableAction()
            ->assertNotified($this->done('Delivery is now enabled (version 3).'));
        $this->assertSame(1, TestDeliveryControl::query()->count());
    }

    public function test_enable_is_refused_without_verified_payment_grant_contract_activation_or_access_and_records_no_control(): void
    {
        $service = app(TestDeliveryStaffOperations::class);
        $cases = [
            'unpaid' => 'This order has no verified test payment, so delivery cannot be enabled.',
            'paid' => 'This order has no grant yet, so delivery cannot be enabled.',
            'granted' => 'The license contract is not issued yet, so delivery cannot be enabled.',
            'contracted' => 'The order is not activated yet. The test commerce pipeline activates it after the contract is issued; try again in a few minutes.',
        ];
        $fixtures = [];
        foreach (array_keys($cases) as $stage) {
            $fixtures[$stage] = $this->order($stage);
        }
        $component = $this->trackList();
        foreach ($cases as $stage => $message) {
            $order = $fixtures[$stage]['order'];
            $component->assertTableActionHidden('enableDelivery', $order)->assertTableActionHidden('blockDelivery', $order);
            $this->assertSame(['ok' => false, 'message' => $message], $service->controlDelivery($this->actor, $order->public_id, 'enable', 0, 'ALPHA-REFUSED'));
            $this->assertSame([$this->staffAudit($order->public_id, 'refused', null, 'ALPHA-REFUSED')],
                array_values(array_filter($this->audit('commerce.delivery.staff_action.delivery_enable'), fn (array $row): bool => $row[1]['order_public_id'] === $order->public_id)));
        }
        $this->assertSame(0, TestDeliveryControl::query()->count());
        $this->assertSame([], $this->audit('commerce.delivery.control_provisioned'));

        // Owner delivery switched off: enabling is refused before anything is provisioned; blocking an enabled order still works.
        $ready = $this->order('ready');
        config(['delivery.test_access_enabled' => false]);
        $this->assertSame(['ok' => false, 'message' => 'Test delivery access is switched off or not configured on this host. Nothing changed.'],
            $service->controlDelivery($this->actor, $ready['order']->public_id, 'enable', 0, 'ALPHA-OFF'));
        $this->assertSame(0, TestDeliveryControl::query()->count());
        config(['delivery.test_access_enabled' => true]);
        $this->assertTrue($service->controlDelivery($this->actor, $ready['order']->public_id, 'enable', 0, 'ALPHA-ON')['ok']);
        config(['delivery.test_access_enabled' => false]);
        $this->assertSame(['ok' => true, 'message' => 'Delivery is now blocked (version 2).'],
            $service->controlDelivery($this->actor, $ready['order']->public_id, 'block', 1, 'ALPHA-OFF'));

        // An unknown order and an invalid reference are refused without reaching the control service.
        $this->assertSame(['ok' => false, 'message' => 'This order was not found.'], $service->controlDelivery($this->actor, (string) Str::uuid(), 'enable', 0, 'ALPHA-1'));
        $this->assertFalse($service->controlDelivery($this->actor, $ready['order']->public_id, 'block', 2, 'bad reference')['ok']);
        $this->assertSame(2, TestDeliveryControl::query()->sole()->control_version);
    }

    public function test_a_replayed_or_simultaneous_click_leaves_one_control_and_a_stale_dialog_is_refused(): void
    {
        $f = $this->order('ready');
        $order = $f['order'];
        // Two staff open the dialog at version 0; both submit. One change is recorded. The page no longer offers the second
        // (the action is hidden once enabled), and the service answers a second submission with the state it found.
        $first = $this->trackList()->mountTableAction('enableDelivery', $order)->assertTableActionDataSet(['expected_version' => 0])->fillForm(['reference' => 'STAFF-A']);
        $second = $this->trackList()->mountTableAction('enableDelivery', $order)->assertTableActionDataSet(['expected_version' => 0])->fillForm(['reference' => 'STAFF-B']);
        $first->callMountedTableAction()->assertNotified($this->done('Delivery is now enabled (version 1).'));
        $second->callMountedTableAction();
        $this->assertSame(['ok' => true, 'message' => 'Delivery is already enabled (version 1). Nothing changed.'],
            app(TestDeliveryStaffOperations::class)->controlDelivery($this->actor, $order->public_id, 'enable', 0, 'STAFF-B'));
        $this->assertSame(1, TestDeliveryControl::query()->count());
        $this->assertSame([false, 1], [TestDeliveryControl::query()->sole()->blocked, TestDeliveryControl::query()->sole()->control_version]);
        $this->assertCount(1, $this->audit('commerce.delivery.control_provisioned'));
        $this->assertCount(1, $this->audit('commerce.delivery.control_changed'));
        $this->assertSame([$this->staffAudit($order->public_id, 'accepted', 1, 'STAFF-A'), $this->staffAudit($order->public_id, 'unchanged', 1, 'STAFF-B')],
            $this->audit('commerce.delivery.staff_action.delivery_enable'));
        // A replay at the current version is the same no-op.
        $this->assertSame(['ok' => true, 'message' => 'Delivery is already enabled (version 1). Nothing changed.'],
            app(TestDeliveryStaffOperations::class)->controlDelivery($this->actor, $order->public_id, 'enable', 1, 'STAFF-A'));
        $this->assertCount(1, $this->audit('commerce.delivery.control_changed'));

        // A change recorded after a block dialog opened makes its version stale: the block is refused and nothing changes.
        $stale = $this->trackList()->mountTableAction('blockDelivery', $order)->assertTableActionDataSet(['expected_version' => 1])->fillForm(['reference' => 'STAFF-C']);
        app(ManageTestDeliveryControl::class)->handle($order->public_id, true, 1, 'CONSOLE-1');
        app(ManageTestDeliveryControl::class)->handle($order->public_id, false, 2, 'CONSOLE-2');
        $stale->callMountedTableAction()->assertNotified($this->refused('Another change was recorded first. Close this dialog, reopen it and review the current state.'));
        $this->assertSame([false, 3], [TestDeliveryControl::query()->sole()->blocked, TestDeliveryControl::query()->sole()->control_version]);
        $this->assertSame([$this->staffAudit($order->public_id, 'refused', 3, 'STAFF-C')], $this->audit('commerce.delivery.staff_action.delivery_block'));
        $this->assertSame(1, TestDeliveryControl::query()->count());
    }

    public function test_the_service_refuses_without_permission_mfa_or_admission_even_when_called_directly(): void
    {
        $f = $this->order('ready');
        $order = $f['order'];
        $service = app(TestDeliveryStaffOperations::class);
        $mounted = $this->trackList()->mountTableAction('enableDelivery', $order)->fillForm(['reference' => 'ALPHA-1']);
        $this->app->instance('env', 'production');
        $mounted->callMountedTableAction()->assertNotFound();
        $this->assertSame(['ok' => false, 'message' => 'Not available.'], $service->controlDelivery($this->actor, $order->public_id, 'enable', 0, 'ALPHA-1'));
        $this->app->instance('env', $this->originalEnv);

        $plain = User::factory()->create();
        $this->assertFalse($service->allows($plain));
        $this->assertSame(['ok' => false, 'message' => 'Not available.'], $service->controlDelivery($plain, $order->public_id, 'enable', 0, 'ALPHA-1'));
        config(['payments.stripe.mode' => 'live']);
        $this->assertSame(['ok' => false, 'message' => 'Not available.'], $service->controlDelivery($this->actor, $order->public_id, 'enable', 0, 'ALPHA-1'));
        config(['payments.stripe.mode' => 'test']);

        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $noMfa = LicenseFixtures::admin();
            Livewire::actingAs($noMfa)->test(ListTestTrackOrders::class)->assertForbidden();
            $this->assertFalse($service->allows($noMfa));
            $this->assertSame(['ok' => false, 'message' => 'Not available.'], $service->controlDelivery($noMfa, $order->public_id, 'enable', 0, 'ALPHA-1'));
            $this->assertSame(0, TestDeliveryControl::query()->count());
            $this->assertSame([], $this->audit('commerce.delivery.staff_action.delivery_enable'));
            // The enrolled admin is admitted under the same requirement.
            $this->assertTrue($service->allows($this->actor));
            $this->assertTrue($service->controlDelivery($this->actor, $order->public_id, 'enable', 0, 'ALPHA-1')['ok']);
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_the_console_command_and_the_page_operate_the_same_control(): void
    {
        $f = $this->order('ready');
        $id = $f['order']->public_id;
        $this->assertSame(0, Artisan::call('vasey:control-test-delivery', ['order' => $id, 'action' => 'block', '--expected-version' => '0', '--reference' => 'CONSOLE-1']));
        $this->assertSame(0, Artisan::call('vasey:control-test-delivery', ['order' => $id, 'action' => 'enable', '--expected-version' => '0', '--reference' => 'CONSOLE-1']));
        $this->assertStringContainsString('enabled version=1', Artisan::output());
        $this->trackList()->assertSee('Enabled (version 1)')->mountTableAction('blockDelivery', $f['order'])
            ->assertTableActionDataSet(['expected_version' => 1])->fillForm(['reference' => 'PAGE-1'])->callMountedTableAction()
            ->assertNotified($this->done('Delivery is now blocked (version 2).'));
        $this->assertSame(0, Artisan::call('vasey:control-test-delivery', ['order' => $id, 'action' => 'enable', '--expected-version' => '2', '--reference' => 'CONSOLE-2']));
        $this->assertStringContainsString(TestDeliveryControl::query()->sole()->public_id.' enabled version=3', Artisan::output());
        // The console's change stays unattributed, as before; the page's change is attributed.
        $this->assertSame([null, $this->actor->id], array_slice(array_column($this->audit('commerce.delivery.control_changed'), 0), 0, 2));
        $this->issue($f);
    }

    public function test_orders_of_another_stripe_account_or_live_mode_are_neither_listed_nor_operable(): void
    {
        $own = $this->order('ready');
        $foreign = $this->order('unpaid');
        $live = $this->order('unpaid');
        // Synthetic stand-ins for checkout evidence recorded under another account and in live mode (the evidence is otherwise immutable).
        DB::unprepared('DROP TRIGGER IF EXISTS checkout_intents_immutable_update');
        DB::table('checkout_intents')->where('order_id', $foreign['order']->id)->update(['account_id' => 'acct_FOREIGNTRACK']);
        DB::table('checkout_intents')->where('order_id', $live['order']->id)->update(['mode' => 'live']);

        $this->trackList()->assertCanSeeTableRecords([$own['order']])->assertCanNotSeeTableRecords([$foreign['order'], $live['order']]);
        $service = app(TestDeliveryStaffOperations::class);
        $this->assertSame([$own['order']->public_id], $service->orders()->pluck('public_id')->all());
        foreach ([$foreign, $live] as $f) {
            foreach (['enable', 'block'] as $action) {
                $this->assertSame(['ok' => false, 'message' => 'This order was not found.'],
                    $service->controlDelivery($this->actor, $f['order']->public_id, $action, 0, 'ALPHA-OUTSIDE'));
            }
        }
        $this->assertSame(0, TestDeliveryControl::query()->count());
    }

    public function test_a_change_committed_between_the_state_check_and_the_control_call_is_reported_as_the_state_found(): void
    {
        $f = $this->order('ready');
        $id = $f['order']->public_id;
        // Another operator enables the order right after this call provisioned it, before this call's own enable runs.
        $raced = false;
        AuditEvent::created(function (AuditEvent $event) use (&$raced, $id): void {
            if ($event->action === 'commerce.delivery.control_provisioned' && ! $raced) {
                $raced = true;
                DB::afterCommit(fn () => app(ManageTestDeliveryControl::class)->handle($id, false, 0, 'OTHER-OPERATOR'));
            }
        });
        $this->assertSame(['ok' => true, 'message' => 'Delivery is already enabled (version 1). Nothing changed.'],
            app(TestDeliveryStaffOperations::class)->controlDelivery($this->actor, $id, 'enable', 0, 'ALPHA-RACE'));
        $this->assertTrue($raced);
        $this->assertSame([false, 1], [TestDeliveryControl::query()->sole()->blocked, TestDeliveryControl::query()->sole()->control_version]);
        $this->assertCount(1, $this->audit('commerce.delivery.control_changed'));
        $this->assertSame(hash('sha256', 'OTHER-OPERATOR'), $this->audit('commerce.delivery.control_changed')[0][1]['reference_hash']);
        $this->assertSame([$this->staffAudit($id, 'unchanged', 1, 'ALPHA-RACE')], $this->audit('commerce.delivery.staff_action.delivery_enable'));
    }

    public function test_a_refused_second_step_says_the_control_was_recorded_as_blocked(): void
    {
        $f = $this->order('ready');
        $id = $f['order']->public_id;
        // Owner delivery is switched off after this call provisioned the control and before it enables it.
        AuditEvent::created(function (AuditEvent $event): void {
            if ($event->action === 'commerce.delivery.control_provisioned') {
                config(['delivery.test_access_enabled' => false]);
            }
        });
        $this->assertSame(['ok' => false, 'message' => 'The delivery control was recorded as blocked (version 0); delivery was not enabled: '
            .'Test delivery access is switched off or not configured on this host.'],
            app(TestDeliveryStaffOperations::class)->controlDelivery($this->actor, $id, 'enable', 0, 'ALPHA-STEP2'));
        $this->assertSame([true, 0], [TestDeliveryControl::query()->sole()->blocked, TestDeliveryControl::query()->sole()->control_version]);
        $this->assertSame([$this->staffAudit($id, 'refused', 0, 'ALPHA-STEP2')], $this->audit('commerce.delivery.staff_action.delivery_enable'));
        $this->deliveryRefused($f, 'unavailable');
        $this->trackList()->assertSee('Blocked (version 0)')->assertSee('Delivery blocked');
    }
}
