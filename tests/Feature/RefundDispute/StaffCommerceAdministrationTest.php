<?php

namespace Tests\Feature\RefundDispute;

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeHold;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObject;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObservation;
use App\Domain\Commerce\RefundDispute\Models\RefundLineAllocation;
use App\Domain\Commerce\RefundDispute\RefundDisputeActions;
use App\Domain\Commerce\RefundDispute\RefundDisputeException;
use App\Domain\Commerce\RefundDispute\StaffCommerceDirectory;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Filament\Resources\StaffCustomerResource;
use App\Filament\Resources\StaffCustomerResource\Pages\ListStaffCustomers;
use App\Filament\Resources\StaffOrderResource;
use App\Filament\Resources\StaffOrderResource\Pages\ListStaffOrders;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\RefundDisputeFixtures as R;
use Tests\TestCase;

class StaffCommerceAdministrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private object $payments;

    private object $financial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        R::configure();
        Queue::fake();
        [$this->payments, $this->financial] = R::bind($this->app);
        $this->app->instance(PrepareTestDeliveryStream::class, DeliveryFixtures::observingStreams());
    }

    public function test_guests_customers_and_staff_without_required_mfa_cannot_open_either_list(): void
    {
        $f = DeliveryFixtures::ready($this->payments, true);
        $this->get('/admin/test-orders')->assertRedirect();
        $this->actingAs(User::factory()->create());
        $this->get('/admin/test-orders')->assertForbidden();
        $this->get('/admin/test-customers')->assertForbidden();
        $admin = LicenseFixtures::admin();
        $this->actingAs($admin);
        $component = Livewire::test(ListStaffOrders::class)->assertSuccessful()->assertSee($f['order']->public_id);
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $component->call('$refresh')->assertForbidden();
        Livewire::test(ListStaffCustomers::class)->assertForbidden();
        foreach (['create', 'update', 'delete', 'deleteAny', 'view'] as $ability) {
            $this->assertFalse(StaffOrderResource::can($ability, $f['finalization']), $ability);
            $this->assertFalse(StaffCustomerResource::can($ability, $f['finalization']), $ability);
        }
    }

    public function test_staff_record_a_check_allocate_and_suspend_through_the_domain_and_read_customer_detail(): void
    {
        $f = DeliveryFixtures::ready($this->payments, true);
        $originals = R::originals();
        $admin = LicenseFixtures::admin();
        $this->actingAs($admin);
        $totals = array_map(fn (array $line): int => $line['pricing']['total_minor'], $f['original']['lines']);
        R::provider($this->financial, [['re_PRIVATEREFUND', $totals[0], 'succeeded']]);
        $component = Livewire::test(ListStaffOrders::class);
        $this->assertSame(['orderDetail', 'observe', 'allocateRefund', 'suspendLine', 'placeDisputeHold', 'releaseHold', 'qualifyExclusive'],
            array_keys($component->instance()->getTable()->getFlatActions()));
        $component->mountTableAction('observe', $f['finalization'])->callMountedTableAction()->assertHasNoTableActionErrors()
            ->assertNotified('Check refunds and disputes recorded');
        $this->assertSame('partial', RefundDisputeObservation::sole()->refund_state);
        $refund = RefundDisputeObject::sole();
        Livewire::test(ListStaffOrders::class)->mountTableAction('allocateRefund', $f['finalization'])
            ->setTableActionData(['refund' => $refund->public_id, 'lines' => ['0' => (string) ($totals[0] - 1), '1' => '2']])
            ->callMountedTableAction()->assertNotified('Allocate partial refund was not recorded');
        $this->assertSame(0, RefundLineAllocation::count());
        Livewire::test(ListStaffOrders::class)->mountTableAction('allocateRefund', $f['finalization'])
            ->setTableActionData(['refund' => $refund->public_id, 'lines' => ['0' => (string) $totals[0]]])
            ->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Allocate partial refund recorded');
        $this->assertSame($totals[0], (int) RefundLineAllocation::sum('amount_minor'));
        Livewire::test(ListStaffOrders::class)->mountTableAction('placeDisputeHold', $f['finalization'])
            ->callMountedTableAction()->assertNotified('Hold order downloads for dispute was not recorded');
        Livewire::test(ListStaffOrders::class)->mountTableAction('suspendLine', $f['finalization'])
            ->setTableActionData(['grant' => $f['grants'][0]->public_id])->callMountedTableAction()->assertNotified('Suspend line downloads recorded');
        $this->assertSame($f['grants'][0]->id, RefundDisputeHold::sole()->license_grant_id);
        Livewire::test(ListStaffOrders::class)->mountTableAction('orderDetail', $f['finalization'])
            ->assertMountedActionModalSee($f['order']->public_id)->assertMountedActionModalSee('refund suspension')
            ->assertMountedActionModalSee('allocation recorded')->assertMountedActionModalDontSee('re_PRIVATEREFUND')
            ->assertMountedActionModalDontSee('private-financial@example.test');
        Livewire::test(ListStaffCustomers::class)->assertSuccessful()->assertSee('Guest (no account)')
            ->mountTableAction('customerDetail', $f['finalization'])->assertMountedActionModalSee($f['order']->public_id)
            ->assertMountedActionModalSee('guest without an account')->assertMountedActionModalDontSee($f['order']->owner_key)
            ->assertMountedActionModalDontSee('re_PRIVATEREFUND');
        $this->assertSame($originals, R::originals());
    }

    public function test_staff_detail_excludes_a_failed_refund_but_retains_its_allocation_and_status_history(): void
    {
        $f = DeliveryFixtures::ready($this->payments, true);
        $originals = R::originals();
        $admin = LicenseFixtures::admin();
        $this->actingAs($admin);
        R::provider($this->financial, [['re_LATERFAILED', 101, 'succeeded']]);
        R::observe($f['order']->public_id, $admin);
        $refund = RefundDisputeObject::sole();
        app(RefundDisputeActions::class)->allocate($f['order']->public_id, $admin, (string) Str::uuid(),
            R::sequence($f['order']->public_id, $admin), $refund->public_id, [0 => 101]);
        $allocations = RefundLineAllocation::orderBy('id')->get()->toArray();
        $directory = app(StaffCommerceDirectory::class);
        $this->assertSame([101, 0], array_column($directory->orderFor($f['finalization']->id, $admin)['lines'], 'allocatedMinor'));

        R::provider($this->financial, [['re_LATERFAILED', 101, 'failed']]);
        R::observe($f['order']->public_id, $admin);
        $detail = $directory->orderFor($f['finalization']->id, $admin);
        $this->assertSame([0, 0], array_column($detail['lines'], 'allocatedMinor'));
        $this->assertSame(0, $detail['latestObservation']['succeededRefundMinor']);
        $this->assertSame(['succeeded', 'failed'], $detail['providerObjects'][0]['statuses']);
        $this->assertSame($allocations, RefundLineAllocation::orderBy('id')->get()->toArray());
        Livewire::test(ListStaffOrders::class)->mountTableAction('orderDetail', $f['finalization'])
            ->assertMountedActionModalSee('succeeded refunds by allocation 0')
            ->assertMountedActionModalDontSee('succeeded refunds by allocation 101');
        Livewire::test(ListStaffCustomers::class)->mountTableAction('customerDetail', $f['finalization'])
            ->assertMountedActionModalSee('succeeded refunds by allocation 0')
            ->assertMountedActionModalDontSee('succeeded refunds by allocation 101');
        $this->assertSame($originals, R::originals());
    }

    public function test_staff_detail_aggregates_only_succeeded_allocations_with_failed_and_pending_refunds_present(): void
    {
        $f = DeliveryFixtures::ready($this->payments, true);
        $originals = R::originals();
        $admin = LicenseFixtures::admin();
        $this->actingAs($admin);
        R::provider($this->financial, [['re_KEEPONE', 101, 'succeeded'], ['re_KEEPTWO', 203, 'succeeded'],
            ['re_FAIL', 307, 'succeeded'], ['re_PENDING', 409, 'pending']]);
        R::observe($f['order']->public_id, $admin);
        foreach ([101, 203, 307] as $amount) {
            $refund = RefundDisputeObject::where('kind', 'refund')->where('amount_minor', $amount)->sole();
            app(RefundDisputeActions::class)->allocate($f['order']->public_id, $admin, (string) Str::uuid(),
                R::sequence($f['order']->public_id, $admin), $refund->public_id, [0 => $amount]);
        }
        $allocations = RefundLineAllocation::orderBy('id')->get()->toArray();
        R::provider($this->financial, [['re_KEEPONE', 101, 'succeeded'], ['re_KEEPTWO', 203, 'succeeded'],
            ['re_FAIL', 307, 'failed'], ['re_PENDING', 409, 'pending']]);
        R::observe($f['order']->public_id, $admin);
        $directory = app(StaffCommerceDirectory::class);
        $detail = $directory->orderFor($f['finalization']->id, $admin);
        // Independent arithmetic: 101 + 203 succeeded; the retained 307 failed allocation does not count.
        $this->assertSame([304, 0], array_column($detail['lines'], 'allocatedMinor'));
        $this->assertSame(304, $detail['latestObservation']['succeededRefundMinor']);
        $this->assertSame(409, $detail['latestObservation']['pendingRefundMinor']);
        $this->assertSame([304, 0], array_column($directory->customer($f['finalization']->id, $admin)['orders'][0]['lines'], 'allocatedMinor'));
        $this->assertSame(611, (int) RefundLineAllocation::sum('amount_minor'));
        $this->assertSame($allocations, RefundLineAllocation::orderBy('id')->get()->toArray());
        Livewire::test(ListStaffOrders::class)->mountTableAction('orderDetail', $f['finalization'])
            ->assertMountedActionModalSee('succeeded refunds by allocation 304')
            ->assertMountedActionModalDontSee('succeeded refunds by allocation 611');
        $this->assertSame($originals, R::originals());
    }

    public function test_paid_exception_detail_keeps_original_lines_and_shows_unknown_refunds_without_a_complete_case(): void
    {
        $f = PaymentFixtures::started($this->payments);
        $this->travelTo($f['order']->attempt()->sole()->expires_at);
        $f = FinalizationFixtures::confirm($f);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $finalization = OrderFinalization::sole();
        $originals = R::originals();
        $admin = LicenseFixtures::admin();
        $this->actingAs($admin);
        $detail = app(StaffCommerceDirectory::class)->orderFor($finalization->id, $admin);
        $this->assertSame('paid_exception', $detail['outcome']);
        $this->assertSame('attention', $detail['evidence']);
        $this->assertSame($f['original']['lines'][0]['pricing']['total_minor'], $detail['lines'][0]['totalMinor']);
        $this->assertNull($detail['lines'][0]['grant']);
        $this->assertNull($detail['lines'][0]['allocatedMinor']);
        Livewire::test(ListStaffOrders::class)->mountTableAction('orderDetail', $finalization)
            ->assertMountedActionModalSee('succeeded refunds by allocation unknown');
        $this->assertSame($originals, R::originals());
        $this->assertSame(array_fill_keys(R::TABLES, 0), R::counts());
    }

    public function test_unset_policy_is_reported_and_writes_nothing(): void
    {
        $f = DeliveryFixtures::ready($this->payments);
        config(['refund-dispute.policy.dispute_access' => null]);
        $this->actingAs(LicenseFixtures::admin());
        R::provider($this->financial, [['re_FULL', $f['payment']->amount_minor, 'succeeded']]);
        Livewire::test(ListStaffOrders::class)->mountTableAction('observe', $f['finalization'])->callMountedTableAction()
            ->assertNotified('Check refunds and disputes was not recorded');
        $this->assertSame(array_fill_keys(R::TABLES, 0), R::counts());
        $this->assertSame([], $this->financial->calls);
    }

    public function test_the_grouped_customer_list_orders_only_by_aggregates_so_mysql_only_full_group_by_accepts_it(): void
    {
        $f = DeliveryFixtures::ready($this->payments, true);
        $this->actingAs(LicenseFixtures::admin());
        $component = Livewire::test(ListStaffCustomers::class)->assertSuccessful()->assertSee('Guest (no account)');
        foreach ([null, 'last_finalized_at'] as $sort) {
            if ($sort !== null) {
                $component->call('sortTable', $sort)->assertSuccessful();
            }
            $query = $component->instance()->getFilteredSortedTableQuery();
            $orders = $query->getQuery()->orders;
            $this->assertNotEmpty($orders);
            foreach ($orders as $order) {
                // Only raw aggregate expressions: a plain column (Filament's default key sort) is not in the GROUP BY.
                $this->assertSame('Raw', $order['type'] ?? null, json_encode($order));
                $this->assertMatchesRegularExpression('/\A(?:MAX|MIN)\(order_finalizations\.(?:finalized_at|id)\) (?:asc|desc)\z/', (string) $order['sql']);
            }
            $this->assertSame([$f['finalization']->id], $query->get()->pluck('id')->map(fn ($id) => (int) $id)->all());
        }
    }

    public function test_command_dialogs_close_with_a_notice_outside_test_commerce_and_order_evidence_rechecks_authority(): void
    {
        $f = DeliveryFixtures::ready($this->payments);
        $admin = LicenseFixtures::admin();
        $this->actingAs($admin);
        config(['payments.stripe.mode' => 'live']);
        Livewire::test(ListStaffOrders::class)->assertSuccessful()->mountTableAction('observe', $f['finalization'])
            ->assertNotified('Check refunds and disputes is unavailable');
        $this->assertSame([], $this->financial->calls);
        config(['payments.stripe.mode' => 'test']);
        $directory = app(StaffCommerceDirectory::class);
        $this->assertSame($f['order']->public_id, $directory->orderFor($f['finalization']->id, $admin)['order']);
        try {
            $directory->orderFor($f['finalization']->id, User::factory()->create());
            $this->fail('A non-staff user read order evidence.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        try {
            $directory->orderFor($f['finalization']->id + 1000, $admin);
            $this->fail('An out-of-scope finalization was projected.');
        } catch (RefundDisputeException $error) {
            $this->assertSame('not_found', $error->reason);
        }
    }
}
