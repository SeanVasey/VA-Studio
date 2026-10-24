<?php

namespace Tests\Feature\RefundDispute;

use App\Domain\Commerce\RefundDispute\Models\RefundDisputeHold;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObject;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObservation;
use App\Domain\Commerce\RefundDispute\Models\RefundLineAllocation;
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
use Livewire\Livewire;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
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
