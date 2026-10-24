<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Grants\Member\MemberActivationHistoryReader;
use App\Domain\Grants\Member\MemberGrantPolicy;
use App\Domain\Memberships\Billing\BillingAwardFence;
use App\Domain\Memberships\Production\MembershipCustomerIndex;
use App\Domain\Memberships\Production\MembershipCustomerRead;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipLedgerAudit;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\Support\MembershipAwardMonotonicClock;
use Tests\TestCase;

// PHPUnit loads test files before executing the suite, before PHP caches these production call sites.
class_exists(MembershipAwardMonotonicClock::class);

/**
 * C4e availability fixes on the C4d customer index. F3: the index and its pre-bytes re-run take the owner lock but
 * never the global billing award fence (the native NOWAIT evidence is MembershipNativeCustomerIndexTest). F4: the
 * pre-bytes proof runs under the index's original 60-second deadline and never renews it. The synthetic monotonic
 * clock proves deadline semantics only, never physical execution timing.
 */
class MembershipCustomerIndexAvailabilityTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberActivationFixtures;

    protected function setUp(): void
    {
        MembershipAwardMonotonicClock::reset();
        parent::setUp();
        $this->memberActivationSetup();
    }

    protected function tearDown(): void
    {
        MembershipAwardMonotonicClock::reset();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_index_takes_the_owner_lock_but_no_global_award_fence(): void
    {
        // The index reads with raw PDO statements, which framework query events do not see, and SQLite has no row
        // locks to probe. This source census pins the F3 decision on every engine; the native NOWAIT test proves it
        // on InnoDB. Neither the index nor anything it calls may take the fence or the billing evidence lock.
        $source = file_get_contents(base_path('app/Domain/Memberships/Production/MembershipCustomerIndex.php'));
        foreach (['BillingAwardFence', 'MembershipBillingEvidence', 'SealedMembershipPaidInvoice', BillingAwardFence::TABLE] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
        $this->assertSame(1, substr_count($source, '$access->lock($principal, $actor, $rows->current())'));
        foreach ([MemberActivationHistoryReader::class, MembershipLedgerAudit::class, MembershipPeriodRecords::class, MemberGrantPolicy::class] as $collaborator) {
            $called = file_get_contents((new ReflectionClass($collaborator))->getFileName());
            $this->assertStringNotContainsString('BillingAwardFence', $called, $collaborator);
            $this->assertStringNotContainsString('MembershipBillingEvidence::lock', $called, $collaborator);
        }
        // Behaviour is unchanged: the index and its pre-bytes re-run still answer the owner.
        $fixture = $this->memberActivationFixture();
        $read = MembershipCustomerRead::begin();
        $index = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read);
        $read->proveBeforeBytes();
        $this->assertCount(1, $index['periods']);
    }

    public function test_pre_bytes_inside_the_original_deadline_is_released(): void
    {
        $fixture = $this->memberActivationFixture();
        MembershipAwardMonotonicClock::freeze();
        $read = MembershipCustomerRead::begin();
        (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read);
        MembershipAwardMonotonicClock::advance(59);
        $read->proveBeforeBytes();
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_pre_bytes_after_the_original_deadline_refuses_and_is_not_renewed(): void
    {
        $fixture = $this->memberActivationFixture();
        MembershipAwardMonotonicClock::freeze();
        $read = MembershipCustomerRead::begin();
        (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read);
        MembershipAwardMonotonicClock::advance(61);
        $this->refused(fn () => $read->proveBeforeBytes(), 'operation_deadline');
        // One use: the expired read is spent, and a fresh read starts its own new budget.
        $this->refused(fn () => $read->proveBeforeBytes(), 'foreign_customer_read');
        $fresh = MembershipCustomerRead::begin();
        (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $fresh);
        $fresh->proveBeforeBytes();
    }

    public function test_pre_bytes_re_run_keeps_the_original_deadline_inside_its_own_transaction(): void
    {
        $fixture = $this->memberActivationFixture();
        MembershipAwardMonotonicClock::freeze();
        $read = MembershipCustomerRead::begin();
        (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read);
        MembershipAwardMonotonicClock::advance(30);
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use (&$calls): void {
            // The re-run starts inside the original budget (30 s used) and then crosses it: a renewed 60-second
            // budget from the start of the re-run would still admit this read.
            $calls++;
            MembershipAwardMonotonicClock::advance(31);
        });
        $this->refused(fn () => $read->proveBeforeBytes(), 'operation_deadline');
        $this->assertSame(1, $calls);
        $this->assertSame(0, DB::transactionLevel());
    }

    private function refused(callable $work, string $reason): void
    {
        try {
            $work();
            $this->fail('Expected an explicit membership refusal.');
        } catch (MembershipException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
