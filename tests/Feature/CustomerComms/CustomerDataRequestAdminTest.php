<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\DataRights\CustomerDataRights;
use App\Domain\Customers\DataRights\Models\CustomerDataRequest;
use App\Domain\Customers\Models\CustomerAccount;
use App\Filament\Resources\CustomerDataRequestResource\Pages\ListCustomerDataRequests;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CustomerFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use PDOException;
use Tests\TestCase;

/** The staff list resolves deletion requests only through the domain service, with the version the confirmation captured. */
class CustomerDataRequestAdminTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        F::configure();
        config(['customer-data-rights.enabled' => true]);
    }

    private function deletionRequest(): array
    {
        $customer = F::account();
        $principal = app(CustomerAccess::class)->principal($customer['user']);
        $result = app(CustomerDataRights::class)->requestDeletion($principal, $customer['user']);

        return [$customer, CustomerDataRequest::where('public_id', $result['requestId'])->sole()];
    }

    private function staff(): User
    {
        $admin = LicenseFixtures::admin();
        $admin->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        return $admin->fresh();
    }

    public function test_approval_from_the_list_refuses_while_retention_is_unset_and_completes_once_configured(): void
    {
        [$customer, $request] = $this->deletionRequest();
        $this->actingAs($this->staff());
        $this->get('/admin/customer-data-requests')->assertOk()->assertSee($request->public_id);
        Livewire::test(ListCustomerDataRequests::class)->callTableAction('approveDeletion', $request)->assertNotified('Request not changed');
        $this->assertSame('received', $request->fresh()->state);
        $this->assertTrue(CustomerAccount::find($customer['account']->id)->active);

        config(['customer-data-rights.retention' => ['policy_reference' => 'Synthetic SR-1', 'financial_records_years' => 7,
            'consent_records_years' => 3, 'correspondence_records_years' => 2, 'security_records_years' => 1]]);
        Livewire::test(ListCustomerDataRequests::class)->callTableAction('approveDeletion', $request)->assertNotified('Deletion completed');
        $this->assertSame('completed', $request->fresh()->state);
        $this->assertFalse(CustomerAccount::find($customer['account']->id)->active);
        Livewire::test(ListCustomerDataRequests::class)->assertTableActionHidden('approveDeletion', $request->fresh())
            ->assertTableActionHidden('rejectDeletion', $request->fresh());
    }

    public function test_rejection_needs_a_reason_and_staff_without_authority_or_enrolment_cannot_resolve(): void
    {
        [, $request] = $this->deletionRequest();
        $unenrolled = LicenseFixtures::admin();
        $this->actingAs($unenrolled);
        Livewire::test(ListCustomerDataRequests::class)->callTableAction('rejectDeletion', $request, data: ['reason' => 'Synthetic reason'])
            ->assertNotified('Request not changed');
        $this->assertSame('received', $request->fresh()->state);

        $this->actingAs(User::factory()->create());
        $this->get('/admin/customer-data-requests')->assertForbidden();

        $staff = $this->staff();
        $this->actingAs($staff);
        Livewire::test(ListCustomerDataRequests::class)->callTableAction('rejectDeletion', $request, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);
        Livewire::test(ListCustomerDataRequests::class)->callTableAction('rejectDeletion', $request, data: ['reason' => 'Synthetic: customer asked to cancel.'])
            ->assertNotified('Request rejected');
        $this->assertSame(['rejected', $staff->id], [$request->fresh()->state, $request->fresh()->resolved_by]);
        $this->assertSame(0, DB::table('customer_data_exports')->count());
        $this->assertNotNull(Filament::getPanel('admin'));
    }

    public function test_database_contention_that_outlives_the_retries_is_a_try_again_notification_and_other_database_errors_are_not_hidden(): void
    {
        [$customer, $request] = $this->deletionRequest();
        config(['customer-data-rights.retention' => ['policy_reference' => 'Synthetic SR-1', 'financial_records_years' => 7,
            'consent_records_years' => 3, 'correspondence_records_years' => 2, 'security_records_years' => 1]]);
        $this->actingAs($this->staff());
        // Every attempt of the service's transaction fails at its first read of the request, as a lock-wait timeout would.
        $failure = null;
        $attempts = 0;
        DB::beforeExecuting(function (string $sql, array $bindings) use (&$failure, &$attempts): void {
            if ($failure !== null && DB::transactionLevel() > 0 && str_contains($sql, 'customer_data_requests') && str_contains($sql, 'public_id')) {
                $attempts++;
                throw new QueryException(DB::getName(), $sql, $bindings, new PDOException($failure));
            }
        });
        $failure = 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction';
        Livewire::test(ListCustomerDataRequests::class)->callTableAction('approveDeletion', $request)
            ->assertNotified(Notification::make()->danger()->title('Request not changed')->body('The database was busy and nothing was changed. Try again in a moment.')->persistent());
        $this->assertSame(3, $attempts, 'The service retried before giving up.');
        $this->assertSame('received', $request->fresh()->state);
        $this->assertTrue(CustomerAccount::find($customer['account']->id)->active);

        // A database error that is not contention is a defect: it is not turned into a "try again" message.
        $failure = 'SQLSTATE[23000]: Integrity constraint violation: synthetic';
        try {
            Livewire::test(ListCustomerDataRequests::class)->callTableAction('approveDeletion', $request);
            $this->fail('A non-contention database error was hidden.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('synthetic', $error->getMessage());
        }
        $this->assertSame('received', $request->fresh()->state);

        // Once the contention clears, the same action completes.
        $failure = null;
        Livewire::test(ListCustomerDataRequests::class)->callTableAction('approveDeletion', $request->fresh())->assertNotified('Deletion completed');
        $this->assertSame('completed', $request->fresh()->state);
    }
}
