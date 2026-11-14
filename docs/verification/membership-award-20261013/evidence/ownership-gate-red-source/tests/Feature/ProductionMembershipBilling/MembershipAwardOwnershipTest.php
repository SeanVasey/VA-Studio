<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Production\MemberGrantAuthority;
use App\Domain\Memberships\Production\MembershipAwardConfiguration;
use App\Domain\Memberships\Production\MembershipAwardContext;
use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipEligibleLicenseAuthority;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPaidInvoiceProof;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipPolicyFactsAuthority;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\SealedMembershipPaidInvoice;
use App\Domain\Memberships\Production\SealedMembershipPolicyFacts;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipAwardFixtures;
use Tests\TestCase;

class MembershipAwardOwnershipTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipAwardFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->awardSetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_direct_context_cannot_write_using_policy_facts_and_only_two_real_capabilities(): void
    {
        $fixture = $this->awardFixture();
        $this->app->instance(MembershipPaidInvoiceAuthority::class, new SealedMembershipPaidInvoice);
        $this->app->instance(MembershipPolicyFactsAuthority::class, new SealedMembershipPolicyFacts);
        $this->assertFalse($this->app->bound(MembershipEligibleLicenseAuthority::class));
        $this->assertFalse($this->app->bound(MemberGrantAuthority::class));
        $reason = DB::transaction(function () use ($fixture): ?string {
            try {
                $this->directContext($fixture, new MembershipRows);

                return null;
            } catch (MembershipException $error) {
                return $error->reason;
            }
        });
        // This transaction commits normally: refusal must precede every period/event write.
        $this->assertSame(['owned_invocation_required', 0, 0], [$reason,
            DB::table(MembershipPeriodRecords::PERIODS)->count(), DB::table(MembershipPeriodRecords::EVENTS)->count()]);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_invoice_callback_cannot_construct_a_second_context_on_the_owned_rows(): void
    {
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $reason = null;
        $calls = 0;
        $callback = function (MembershipRows $rows) use ($fixture, &$reason, &$calls): void {
            $calls++;
            try {
                $this->directContext($fixture, $rows);
            } catch (MembershipException $error) {
                $reason = $error->reason;
            }
        };
        $authority = new class(new SealedMembershipPaidInvoice, $callback) implements MembershipPaidInvoiceAuthority
        {
            private bool $entered = false;

            public function __construct(private readonly SealedMembershipPaidInvoice $delegate, private readonly Closure $callback) {}

            public function lock(string $locator, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipPaidInvoiceProof
            {
                if (! $this->entered) {
                    $this->entered = true;
                    ($this->callback)($rows);
                }

                return $this->delegate->lock($locator, $principal, $actor, $rows);
            }

            public function proveCurrent(MembershipPaidInvoiceProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void
            {
                $this->delegate->proveCurrent($proof, $principal, $actor, $rows);
            }
        };
        $this->app->instance(MembershipPaidInvoiceAuthority::class, $authority);
        $result = (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertSame(['owned_invocation_required', 1], [$reason, $calls]);
        $this->assertSame($result['period_id'], DB::table(MembershipPeriodRecords::PERIODS)->sole()->id);
        $this->assertSame($result['award_event_id'], DB::table(MembershipPeriodRecords::EVENTS)->sole()->id);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_capability_resolution_cannot_remove_a_remaining_capability_before_capture(): void
    {
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $resolutions = 0;
        $this->app->bind(MembershipPaidInvoiceAuthority::class, function () use (&$resolutions): SealedMembershipPaidInvoice {
            if (++$resolutions === 2) {
                $this->app->forgetInstance(MemberGrantAuthority::class);
            }

            return new SealedMembershipPaidInvoice;
        });
        $reason = null;
        try {
            (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
        } catch (MembershipException $error) {
            $reason = $error->reason;
        }
        $this->assertGreaterThanOrEqual(2, $resolutions);
        $this->assertSame(['source_capability_absent', 0, 0], [$reason,
            DB::table(MembershipPeriodRecords::PERIODS)->count(), DB::table(MembershipPeriodRecords::EVENTS)->count()]);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function directContext(array $fixture, MembershipRows $rows): MembershipAwardContext
    {
        $policy = new MembershipPolicy;
        $billing = new BillingPolicy;

        return new MembershipAwardContext($rows, new MembershipAwardConfiguration, $policy, $policy->facts(),
            $billing, $billing->current(), $fixture['buyer']['principal'], $fixture['buyer']['user'],
            hrtime(true) + 10_000_000_000, $fixture['invoice']['id']);
    }
}
