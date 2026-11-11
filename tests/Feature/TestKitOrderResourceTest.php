<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\StripeWebhookReceipt;
use App\Domain\Commerce\Payments\ProcessStripeReceipt;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\Production\VaseyKitLicenseProfile;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\Products\SoundKitProduct;
use App\Domain\SoundKits\Purchases\Models\SoundKitContract;
use App\Domain\SoundKits\Purchases\Models\SoundKitDeliveryControl;
use App\Domain\SoundKits\Purchases\Models\SoundKitGrant;
use App\Domain\SoundKits\Purchases\Models\SoundKitOrder;
use App\Domain\SoundKits\Purchases\Models\SoundKitPayment;
use App\Domain\SoundKits\Purchases\SoundKitCheckout;
use App\Domain\SoundKits\Purchases\SoundKitContracts;
use App\Domain\SoundKits\Purchases\SoundKitDelivery;
use App\Domain\SoundKits\Purchases\SoundKitOrders;
use App\Domain\SoundKits\Purchases\SoundKitPayments;
use App\Domain\SoundKits\Purchases\SoundKitStaffOperations;
use App\Filament\Resources\TestKitOrderResource;
use App\Filament\Resources\TestKitOrderResource\Pages\ListTestKitOrders;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\SoundKitProductFixtures;
use Tests\TestCase;

/**
 * BL-13 staff view: the kit test-order resource and its row actions over the real Filament/Livewire boundary and the real domain
 * services (fake Stripe gateway, synthetic kit, licensee and licensor; no network).
 */
class TestKitOrderResourceTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use SoundKitProductFixtures;

    private const LICENSEE = 'Ada Private Licensee';

    private const TEXT = "[SYNTHETIC] TEST KIT LICENSE\n\nNONBINDING TERMS\n\nSynthetic test terms only; no real rights are granted.\n\n"
        ."PURCHASE DETAILS\n\nKit: {KIT_NAME}\nLicensee: {LICENSEE_NAME}\nLicensor: {LICENSOR_NAME}\nPurchase date: {PURCHASE_DATE}\n"
        ."License fee: {LICENSE_FEE}\nOrder reference: {ORDER_REFERENCE}";

    private User $actor;

    private object $gateway;

    private string $originalEnv = 'testing';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = $this->app['env'];
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        PaymentFixtures::configure();
        $this->gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->actor = LicenseFixtures::admin();
        $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $png = $this->signaturePng();
        Storage::disk('local')->put('contracts/kit-signature.png', $png);
        config(['sound-kits.test_checkout_enabled' => true, 'contracts.vasey_kit_license.licensor_name' => 'Synthetic Licensor Line',
            'contracts.vasey_license_v1.signature_path' => 'contracts/kit-signature.png', 'contracts.vasey_license_v1.signature_sha256' => hash('sha256', $png)]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        $this->app->instance('env', $this->originalEnv);
        parent::tearDown();
    }

    private function listed(): SoundKitProduct
    {
        $author = LicenseFixtures::admin();
        $template = LicenseTemplate::create(['name' => 'NONBINDING SYNTHETIC KIT', 'slug' => 'synthetic-kit-'.Str::uuid(), 'type' => 'sound-kit']);
        $draft = app(CreateLicenseDraft::class)->handle($template, ['authored_source' => self::TEXT,
            'structured_terms' => ['schema_version' => 1, 'features' => ['Synthetic grant'], 'required_asset_roles' => ['master_wav']]], $author);
        $review = app(ReviewLicense::class);
        $submitted = $review->submit($draft, $author);
        $approved = $review->approve($submitted, LicenseFixtures::admin(), ['approval_reference' => 'SYNTHETIC-TEST-ONLY',
            'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true]);

        return $this->product('published', [], app(PublishLicense::class)->handle($approved, $author));
    }

    private function order(?SoundKitProduct $product = null): SoundKitOrder
    {
        return app(SoundKitOrders::class)->create(($product ?? $this->listed())->slug, InventoryFixtures::OWNER, (string) Str::uuid(), self::LICENSEE);
    }

    private int $paidCount = 0;

    /** Starts checkout under a fresh session and PaymentIntent id (several orders share one fake gateway), then reports it paid. */
    private function paidSession(SoundKitOrder $order): void
    {
        $n = ++$this->paidCount;
        $this->gateway->onCreate = fn (array $params): array => CheckoutFixtures::session($params, 'cs_test_KITSTAFF'.$n);
        app(SoundKitCheckout::class)->start($order->public_id, InventoryFixtures::OWNER);
        $this->gateway->session['status'] = 'complete';
        $this->gateway->session['payment_status'] = 'paid';
        $this->gateway->session['url'] = null;
        $this->gateway->session['payment_intent'] = 'pi_KITSTAFF'.$n;
        $this->gateway->payment = ['id' => 'pi_KITSTAFF'.$n] + PaymentFixtures::payment($this->gateway->session);
    }

    private function receipt(): StripeWebhookReceipt
    {
        return PaymentFixtures::receipt($this->gateway->session, 'evt_SYNTHETICKITSTAFF'.$this->paidCount);
    }

    private function granted(): SoundKitOrder
    {
        $order = $this->order();
        $this->paidSession($order);
        $this->assertSame('granted', app(ProcessStripeReceipt::class)->handle($this->receipt()->id));

        return $order;
    }

    private function kitList(): Testable
    {
        $this->actingAs($this->actor);

        return Livewire::test(ListTestKitOrders::class);
    }

    private function refused(string $body): Notification
    {
        return Notification::make()->title('Not done')->body($body)->danger();
    }

    private function audit(string $action): array
    {
        return DB::table('audit_events')->where('action', $action)->orderBy('id')->get()->map(fn (object $row): array => [
            $row->actor_id, json_decode($row->context, true)])->all();
    }

    private function tamperRevision(int $revisionId, array $values): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sound_kit_revisions_identity');
        DB::table('sound_kit_revisions')->where('id', $revisionId)->update($values);
    }

    public function test_the_page_is_staff_only_and_closed_with_the_lane_and_in_production(): void
    {
        $this->order();
        $this->get('/admin/test-kit-orders')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/test-kit-orders')->assertForbidden();
        $this->actingAs($this->actor);
        $this->assertTrue(TestKitOrderResource::canAccess());
        $this->assertTrue(TestKitOrderResource::shouldRegisterNavigation());
        $this->get('/admin/test-kit-orders')->assertOk()->assertSee('Test kit orders');
        foreach (['create', 'update', 'delete', 'deleteAny', 'view', 'restore', 'replicate'] as $ability) {
            $this->assertFalse(TestKitOrderResource::can($ability), $ability);
        }

        // Staff without the catalog permission are refused even while the lane is open (and an open Livewire page is rechecked).
        $component = Livewire::test(ListTestKitOrders::class)->assertSuccessful();
        $this->actor->forceFill(['is_admin' => false])->save();
        $this->actingAs($this->actor->fresh());
        $this->assertFalse(TestKitOrderResource::canAccess());
        $component->call('$refresh')->assertForbidden();
        $this->get('/admin/test-kit-orders')->assertForbidden();
        $this->actor->forceFill(['is_admin' => true])->save();

        foreach ([fn () => config(['sound-kits.test_checkout_enabled' => false]), fn () => config(['sound-kits.test_checkout_enabled' => 'true']),
            fn () => $this->app->instance('env', 'production')] as $close) {
            $this->app->instance('env', $this->originalEnv);
            config(['sound-kits.test_checkout_enabled' => true]);
            $this->actingAs($this->actor->fresh());
            $open = Livewire::test(ListTestKitOrders::class)->assertSuccessful();
            $close();
            $this->assertFalse(TestKitOrderResource::canAccess());
            $this->assertFalse(TestKitOrderResource::shouldRegisterNavigation());
            $this->assertFalse(TestKitOrderResource::can('viewAny'));
            $this->get('/admin/test-kit-orders')->assertNotFound();
            $open->call('$refresh')->assertNotFound();
        }
    }

    public function test_the_table_shows_progress_without_the_licensee_provider_ids_or_file_paths(): void
    {
        $unpaid = $this->order();
        $this->paidSession($unpaid);
        $granted = $this->granted();
        $grant = SoundKitGrant::query()->where('sound_kit_order_id', $granted->id)->sole();
        $payment = SoundKitPayment::query()->sole();
        $html = $this->actingAs($this->actor)->get('/admin/test-kit-orders')->assertOk()
            ->assertSee($unpaid->public_id)->assertSee($granted->public_id)->assertSee('Awaiting payment')->assertSee('Granted, contract pending')
            ->assertSee(number_format($granted->price_minor / 100, 2).' USD')->assertSee('Synthetic Kit ')->getContent();
        foreach ([self::LICENSEE, 'Synthetic Licensor Line', $payment->provider_session_id, $payment->provider_payment_intent_id,
            $payment->account_id, $grant->public_id, 'sound-kit-contracts', $granted->owner_key, 'licensee_ciphertext'] as $private) {
            $this->assertStringNotContainsString($private, $html, $private);
        }
        $service = app(SoundKitStaffOperations::class);
        $rows = $service->orders()->get()->keyBy('public_id');
        $this->assertSame(['Awaiting payment', 'Granted, contract pending'], [$service->stage($rows[$unpaid->public_id]), $service->stage($rows[$granted->public_id])]);
        $this->assertSame('Order started', $service->stage($service->orders()->where('public_id', $this->order()->public_id)->sole()));
    }

    public function test_issue_contract_calls_the_service_once_is_attributed_and_then_disappears(): void
    {
        $order = $this->granted();
        $component = $this->kitList()->assertTableActionVisible('issueContract', $order)->assertTableActionHidden('recheckPayment', $order);
        $this->assertSame(0, SoundKitContract::query()->count());
        $component->callTableAction('issueContract', $order)->assertNotified('Done');
        $contract = SoundKitContract::query()->sole();
        $this->assertSame($contract->sha256, hash('sha256', app(SoundKitContracts::class)->bytes($contract)));
        // The service's own audit (no actor, as from the console) plus the staff attribution.
        $this->assertSame([[null, ['grant_public_id' => SoundKitGrant::query()->sole()->public_id, 'sha256' => $contract->sha256,
            'profile' => VaseyKitLicenseProfile::VERSION, 'test_only' => true]]], $this->audit('sound_kit.test_contract.original_recorded'));
        $this->assertSame([[$this->actor->id, ['order_public_id' => $order->public_id, 'result' => 'accepted', 'test_only' => true]]],
            $this->audit('sound_kit.staff_action.contract_issue'));
        $component->assertTableActionHidden('issueContract', $order);
        $this->assertSame(1, SoundKitContract::query()->count());
    }

    public function test_a_contract_refusal_is_shown_and_records_nothing(): void
    {
        $order = $this->granted();
        config(['contracts.vasey_kit_license.licensor_name' => '']);
        $this->kitList()->callTableAction('issueContract', $order)->assertNotified($this->refused('The contract was not issued: unsupported_input.'));
        $this->assertSame(0, SoundKitContract::query()->count());
        $this->assertSame([[$this->actor->id, ['order_public_id' => $order->public_id, 'result' => 'refused', 'test_only' => true]]],
            $this->audit('sound_kit.staff_action.contract_issue'));
    }

    public function test_delivery_enable_and_block_carry_the_version_and_a_stale_or_invalid_request_is_refused_with_its_reason(): void
    {
        $order = $this->granted();
        $component = $this->kitList()->assertTableActionVisible('enableDelivery', $order)->assertTableActionVisible('blockDelivery', $order);
        $component->mountTableAction('enableDelivery', $order)->assertTableActionDataSet(['expected_version' => 0])
            ->assertMountedActionModalSee('Enabling lets the buyer download')
            ->fillForm(['reference' => 'x'])->callMountedTableAction()->assertHasTableActionErrors(['reference']);
        $this->assertSame(0, SoundKitDeliveryControl::query()->count());

        // A change recorded after the dialog opened makes its carried version stale: refused, nothing changes, reason shown.
        $stale = $this->kitList()->mountTableAction('enableDelivery', $order)->fillForm(['reference' => 'STAGING-KIT-1']);
        app(SoundKitDelivery::class)->control($order->public_id, 'block', 0, 'OTHER-OPERATOR-1');
        $stale->callMountedTableAction()->assertNotified($this->refused('Another change was recorded first. Close this dialog, reopen it and review the current state.'));
        $this->assertSame(['blocked', 1], [SoundKitDeliveryControl::query()->sole()->state, SoundKitDeliveryControl::query()->sole()->version]);
        $this->assertSame([[$this->actor->id, ['order_public_id' => $order->public_id, 'result' => 'refused', 'test_only' => true]]],
            $this->audit('sound_kit.staff_action.delivery_enable'));

        // Reopened, it carries the current version and the change is recorded and attributed.
        $fresh = $this->kitList()->assertTableActionHidden('blockDelivery', $order)->assertTableActionVisible('enableDelivery', $order);
        $fresh->mountTableAction('enableDelivery', $order)->assertTableActionDataSet(['expected_version' => 1])
            ->fillForm(['reference' => 'STAGING-KIT-1'])->callMountedTableAction()->assertNotified('Done');
        $control = SoundKitDeliveryControl::query()->sole();
        $this->assertSame(['enabled', 2, hash('sha256', 'STAGING-KIT-1')], [$control->state, $control->version, $control->reference_hash]);
        $this->assertSame([[null, ['order_public_id' => $order->public_id, 'version' => 2, 'test_only' => true]]],
            array_slice($this->audit('sound_kit.test_delivery.enabled'), -1));
        $this->assertSame([$this->actor->id, $this->actor->id], array_column($this->audit('sound_kit.staff_action.delivery_enable'), 0));
        $fresh->assertTableActionHidden('enableDelivery', $order)->assertTableActionVisible('blockDelivery', $order);
        $fresh->mountTableAction('blockDelivery', $order)->assertTableActionDataSet(['expected_version' => 2])
            ->fillForm(['reference' => 'REFUND:re_SYNTHETIC'])->callMountedTableAction()->assertNotified('Done');
        $this->assertSame(['blocked', 3], [SoundKitDeliveryControl::query()->sole()->state, SoundKitDeliveryControl::query()->sole()->version]);
    }

    public function test_recheck_payment_grants_a_paid_order_whose_webhook_never_arrived_and_shows_a_refusal(): void
    {
        $order = $this->order();
        $this->paidSession($order);
        $component = $this->kitList()->assertTableActionVisible('recheckPayment', $order)->assertTableActionHidden('issueContract', $order)
            ->assertTableActionHidden('enableDelivery', $order);
        $this->assertSame(0, SoundKitGrant::query()->count());
        $component->callTableAction('recheckPayment', $order)->assertNotified('Done');
        $this->assertSame([1, 1], [SoundKitPayment::query()->count(), SoundKitGrant::query()->count()]);
        $this->assertSame([[$this->actor->id, ['order_public_id' => $order->public_id, 'result' => 'accepted', 'test_only' => true]]],
            $this->audit('sound_kit.staff_action.payment_recheck'));
        $component->assertTableActionHidden('recheckPayment', $order)->assertTableActionVisible('issueContract', $order);

        // A paid order whose purchased kit no longer matches stays ungranted and says why.
        $second = $this->order();
        $revision = SoundKitRevision::query()->where('public_id', $second->snapshot['kit_revision']['id'])->sole();
        $this->paidSession($second);
        $this->tamperRevision($revision->id, ['archive_size_bytes' => $revision->archive_size_bytes + 1]);
        $component->callTableAction('recheckPayment', $second)->assertNotified($this->refused('Not granted: the payment is verified and recorded, but the grant was refused (awaiting_grant). The scheduled sweep retries it; an operator must inspect it if it keeps failing.'));
        $this->assertSame(1, SoundKitGrant::query()->count());
    }

    public function test_an_action_is_refused_on_the_server_when_the_lane_closes_or_the_permission_is_withdrawn_after_the_dialog_opened(): void
    {
        $order = $this->granted();
        $component = $this->kitList();
        $mounted = $component->mountTableAction('issueContract', $order);
        config(['sound-kits.test_checkout_enabled' => false]);
        $mounted->callMountedTableAction()->assertNotFound();
        $this->assertSame(0, SoundKitContract::query()->count());
        config(['sound-kits.test_checkout_enabled' => true]);

        // The service refuses a user without the permission and a closed lane even when called directly.
        $service = app(SoundKitStaffOperations::class);
        $plain = User::factory()->create();
        $this->assertSame(['ok' => false, 'message' => 'Not available.'], $service->issueContract($plain, $order->public_id));
        $this->assertSame(['ok' => false, 'message' => 'Not available.'], $service->controlDelivery($plain, $order->public_id, 'enable', 0, 'REF-1234'));
        $this->assertSame(['ok' => false, 'message' => 'Not available.'], $service->recheckPayment($plain, $order->public_id));
        config(['sound-kits.test_checkout_enabled' => false]);
        $this->assertSame(['ok' => false, 'message' => 'Not available.'], $service->issueContract($this->actor, $order->public_id));
        $this->assertSame([0, 0], [SoundKitContract::query()->count(), SoundKitDeliveryControl::query()->count()]);
        $this->assertSame([], $this->audit('sound_kit.staff_action.contract_issue'));
    }

    public function test_an_admin_without_mfa_is_refused_by_the_page_and_by_the_service_when_the_panel_requires_it(): void
    {
        $order = $this->granted();
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $noMfa = LicenseFixtures::admin();
            Livewire::actingAs($noMfa)->test(ListTestKitOrders::class)->assertForbidden();
            $service = app(SoundKitStaffOperations::class);
            $this->assertFalse($service->allows($noMfa));
            $this->assertSame(['ok' => false, 'message' => 'Not available.'], $service->issueContract($noMfa, $order->public_id));
            $this->assertSame(['ok' => false, 'message' => 'Not available.'], $service->controlDelivery($noMfa, $order->public_id, 'enable', 0, 'REF-1234'));
            $this->assertSame(['ok' => false, 'message' => 'Not available.'], $service->recheckPayment($noMfa, $order->public_id));
            $this->assertSame([0, 0], [SoundKitContract::query()->count(), SoundKitDeliveryControl::query()->count()]);
            $this->assertSame([], $this->audit('sound_kit.staff_action.contract_issue'));
            // The enrolled admin is still admitted under the same requirement.
            $this->assertTrue($service->allows($this->actor));
            $this->assertTrue($service->issueContract($this->actor, $order->public_id)['ok']);
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_contract_issuance_is_scheduled_tracked_and_closed_with_the_lane(): void
    {
        $this->app->make(ConsoleKernel::class)->bootstrap();
        $event = collect($this->app->make(Schedule::class)->events())->filter(fn (Event $event): bool => str_contains((string) $event->command, 'vasey:issue-test-kit-contracts'))->sole();
        $this->assertSame(['6-59/10 * * * *', true, true], [$event->expression, $event->withoutOverlapping, $event->filtersPass($this->app)]);
        $event->finish($this->app, 1);
        $this->assertSame('failure', DB::table('scheduler_heartbeats')->where('task', 'vasey:issue-test-kit-contracts')->value('last_outcome'));
        $event->finish($this->app, 0);
        $this->assertSame('success', DB::table('scheduler_heartbeats')->where('task', 'vasey:issue-test-kit-contracts')->value('last_outcome'));
        config(['sound-kits.test_checkout_enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));
        config(['sound-kits.test_checkout_enabled' => true]);
        $this->app->instance('env', 'production');
        $this->assertFalse($event->filtersPass($this->app));
        $this->app->instance('env', $this->originalEnv);

        // What the schedule runs gives a paid order its contract with no operator.
        $order = $this->granted();
        $this->assertSame(0, Artisan::call('vasey:issue-test-kit-contracts'));
        $this->assertSame(1, SoundKitContract::query()->count());
        $this->assertSame($order->public_id, SoundKitOrder::query()->sole()->public_id);
    }

    /** A paid order with fabricated payment evidence (distinct provider ids), so many can exist beside the one-session fake gateway. */
    private function fabricatedPaid(SoundKitProduct $product, int $n): SoundKitOrder
    {
        $order = $this->order($product);
        DB::table('sound_kit_checkout_sessions')->insert(['sound_kit_order_id' => $order->id, 'account_id' => $order->account_id,
            'provider_session_id' => 'cs_test_SYNTHETIC'.$n, 'created_at' => now()]);
        DB::table('sound_kit_payments')->insert(['public_id' => (string) Str::uuid(), 'sound_kit_order_id' => $order->id, 'account_id' => $order->account_id,
            'provider_session_id' => 'cs_test_SYNTHETIC'.$n, 'provider_payment_intent_id' => 'pi_SYNTHETIC'.$n, 'stripe_webhook_receipt_id' => null,
            'source' => 'reconciliation', 'amount_minor' => $order->price_minor, 'currency' => $order->currency, 'evidence' => '{}',
            'evidence_hash' => str_repeat('a', 64), 'verified_at' => now()]);

        return $order;
    }

    public function test_permanently_refused_orders_cannot_starve_a_newer_retryable_one(): void
    {
        // 26 older orders: a payment, no grant, and a refusal already audited as payment_changed (their kit's archive row was changed).
        $stuckProduct = $this->listed();
        $orders = [];
        for ($i = 0; $i < 26; $i++) {
            $orders[] = $this->fabricatedPaid($stuckProduct, $i);
        }
        $revision = SoundKitRevision::query()->where('public_id', $orders[0]->snapshot['kit_revision']['id'])->sole();
        $this->tamperRevision($revision->id, ['archive_sha256' => str_repeat('0', 64)]);
        $stuck = [];
        foreach ($orders as $order) {
            $this->assertSame('payment_changed', app(SoundKitPayments::class)->settle($order));
            $stuck[] = $order->public_id;
        }
        $this->assertCount(26, $this->audit('sound_kit.test_grant.refused'));

        // The newer order's grant failed once (audited as retryable here) and is fine now: it must be picked up first, not 27th.
        $retryable = $this->order($this->listed());
        $this->paidSession($retryable);
        $this->assertSame('granted', app(ProcessStripeReceipt::class)->handle($this->receipt()->id));
        DB::unprepared('DROP TRIGGER IF EXISTS sound_kit_grants_immutable_delete');
        DB::table('sound_kit_grants')->where('sound_kit_order_id', $retryable->id)->delete();
        $this->assertSame(0, SoundKitGrant::query()->count());

        $this->assertSame(1, Artisan::call('vasey:finalize-test-kit-grants', ['--limit' => '25']));
        $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));
        $this->assertCount(25, $lines);
        $this->assertSame($retryable->public_id.' granted', $lines[0]);
        $this->assertSame(1, SoundKitGrant::query()->count());
        // Everything else processed was a permanently refused order, oldest first, none skipped past the limit unfairly.
        $this->assertSame(array_map(fn (string $id): string => $id.' payment_changed', array_slice($stuck, 0, 24)), array_slice($lines, 1));
        // A later sweep selects only what is still ungranted: the 26 refused orders, and the granted one is never selected again.
        $this->assertSame(1, Artisan::call('vasey:finalize-test-kit-grants', ['--limit' => '100']));
        $again = array_values(array_filter(explode("\n", trim(Artisan::output()))));
        $this->assertCount(26, $again);
        $this->assertNotContains($retryable->public_id.' granted', $again);
    }

    /** A generated stand-in signature (a zig-zag stroke, plain-PHP PNG). Never a real signature. */
    private function signaturePng(int $width = 600, int $height = 180): string
    {
        $rows = '';
        for ($y = 0; $y < $height; $y++) {
            $rows .= "\0";
            for ($x = 0; $x < $width; $x++) {
                $phase = intdiv($x, 60) % 2 === 0 ? $x % 60 : 60 - $x % 60;
                $rows .= $x >= 40 && $x < $width - 40 && abs($y - (60 + $phase)) <= 2 ? "\x14\x1e\x3c\xff" : "\xff\xff\xff\x00";
            }
        }
        $deflate = "\x78\x01";
        foreach (str_split($rows, 65535) as $index => $block) {
            $last = ($index + 1) * 65535 >= strlen($rows);
            $deflate .= chr($last ? 1 : 0).pack('v', strlen($block)).pack('v', strlen($block) ^ 0xFFFF).$block;
        }
        $deflate .= hash('adler32', $rows, true);
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)).$chunk('IDAT', $deflate).$chunk('IEND', '');
    }
}
