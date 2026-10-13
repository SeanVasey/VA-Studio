<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPaidInvoiceProof;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\SealedMembershipPaidInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipAwardFixtures;
use Tests\Support\MembershipAwardMonotonicClock;
use Tests\TestCase;

// PHPUnit loads test files before executing the suite, before PHP caches these production call sites.
class_exists(MembershipAwardMonotonicClock::class);

class MembershipAwardDeadlineTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipAwardFixtures;

    protected function setUp(): void
    {
        MembershipAwardMonotonicClock::reset();
        parent::setUp();
        $this->awardSetup();
    }

    protected function tearDown(): void
    {
        MembershipAwardMonotonicClock::reset();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_twenty_seconds_before_proof_mint_remains_inside_the_original_admission_deadline(): void
    {
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use (&$calls): void {
            $calls++;
            MembershipAwardMonotonicClock::advance(20);
        });
        $refusal = null;
        try {
            $result = $this->award($fixture);
        } catch (MembershipException $error) {
            $refusal = $error->reason;
        }
        $this->assertNull($refusal, 'Twenty synthetic seconds must remain inside the original admission deadline.');
        $this->assertSame(1, $calls);
        $this->assertSame($result['period_id'], DB::table(MembershipPeriodRecords::PERIODS)->sole()->id);
        $this->assertSame($result['award_event_id'], DB::table(MembershipPeriodRecords::EVENTS)->sole()->id);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_later_producer_lease_cannot_renew_the_original_operation_deadline(): void
    {
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $authority = new class(new SealedMembershipPaidInvoice) implements MembershipPaidInvoiceAuthority
        {
            public ?MembershipPaidInvoiceProof $proof = null;

            public ?MembershipRows $rows = null;

            public function __construct(private readonly SealedMembershipPaidInvoice $delegate) {}

            public function lock(string $locator, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipPaidInvoiceProof
            {
                $this->rows = $rows;

                return $this->proof = $this->delegate->lock($locator, $principal, $actor, $rows);
            }

            public function proveCurrent(MembershipPaidInvoiceProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void
            {
                $this->delegate->proveCurrent($proof, $principal, $actor, $rows);
            }
        };
        $this->app->instance(MembershipPaidInvoiceAuthority::class, $authority);
        $beginning = 0;
        $newerProofChecks = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use (&$beginning): void {
            $beginning++;
            MembershipAwardMonotonicClock::advance(40);
        });
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($fixture, $authority, &$newerProofChecks): void {
            // At virtual t=61 the original operation expired, while the t=40 producer lease remains valid.
            MembershipAwardMonotonicClock::advance(21);
            $this->assertSame(1, DB::table(MembershipPeriodRecords::PERIODS)->count());
            $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
            $authority->proveCurrent($authority->proof, $fixture['buyer']['principal'], $fixture['buyer']['user'], $authority->rows);
            $newerProofChecks++;
        });
        $this->refused(fn () => $this->award($fixture), 'operation_deadline');
        $this->assertSame([1, 1], [$beginning, $newerProofChecks]);
        $this->assertSame(0, DB::table(MembershipPeriodRecords::PERIODS)->count());
        $this->assertSame(0, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_post_commit_deadline_refuses_a_receipt_without_claiming_the_durable_award_rolled_back(): void
    {
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use (&$calls): void {
            $calls++;
            MembershipAwardMonotonicClock::advance(61);
        });
        $this->refused(fn () => $this->award($fixture), 'committed_receipt_refused');
        $this->assertSame(1, $calls);
        $this->assertSame(1, DB::table(MembershipPeriodRecords::PERIODS)->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function award(array $fixture): array
    {
        return (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
    }

    private function refused(callable $work, string $reason): void
    {
        try {
            $work();
            $this->fail('Expected original admission deadline refusal.');
        } catch (MembershipException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
