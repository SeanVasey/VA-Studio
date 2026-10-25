<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Grants\Member\MemberActivationHistoryInspection;
use App\Domain\Grants\Member\MemberActivationHistoryReader;
use App\Domain\Grants\Member\MemberGrantPolicy;
use App\Domain\Grants\Member\MemberGrantRows;
use App\Domain\Memberships\Billing\BillingAwardFence;
use App\Domain\Memberships\Production\MembershipAwardContext;
use App\Domain\Memberships\Production\MembershipCustomerIndex;
use App\Domain\Memberships\Production\MembershipCustomerRead;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipLedgerAudit;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipReservationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
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

    public function test_index_locks_only_the_owner_and_reads_everything_else_without_row_locks(): void
    {
        // Review N1/N2: after the owner's T23 lock the index takes no lock at all, so it neither holds nor waits for a
        // row or gap another customer's transaction needs, and it cannot join a lock-order cycle. On MySQL a locking
        // read adds FOR UPDATE; SQLite has no row locks, so the call shape is pinned here and the native NOWAIT tests
        // prove it on InnoDB.
        $source = file_get_contents(base_path('app/Domain/Memberships/Production/MembershipCustomerIndex.php'));
        // The only locking statement written here is the frame's first one, on the owner's users row (re-review R1).
        $this->assertSame(1, substr_count($source, 'FOR UPDATE'));
        $this->assertStringContainsString("'SELECT id FROM `'.\$database.'`.`users` WHERE id = ? FOR UPDATE'", $source);
        $frameAt = strpos($source, '$projection = DB::transaction(function ()');
        $this->assertIsInt($frameAt);
        $frame = substr($source, $frameAt);
        // strpos returns false when a call is missing, and false compares below any int, so pin both positions first.
        $lockAt = strpos($frame, '$this->lockOwnerFirst($primary, $principal->userId);');
        $rowsAt = strpos($frame, 'new MembershipRows');
        $this->assertIsInt($lockAt, 'The frame locks the owner row (re-review R1).');
        $this->assertIsInt($rowsAt);
        $this->assertLessThan($rowsAt, $lockAt,
            'The owner row is locked before the first consistent read (the schema proofs in MembershipRows).');
        $this->assertStringNotContainsString('$rows->one(', $source);
        $this->assertStringNotContainsString('$rows->rows(', $source);
        $this->assertSame(1, substr_count($source, '->lock('));
        preg_match_all('/\$(?:reader|facts)->(plan|profile|definition|definitionByHash|profileByHash)\((?:[^()]|\([^()]*\))*\)/', $source, $calls);
        $this->assertGreaterThanOrEqual(8, count($calls[0]));
        foreach ($calls[0] as $call) {
            $this->assertStringEndsWith(', true)', $call, 'Shared facts are read without a row lock: '.$call);
        }
        $this->assertSame(1, substr_count($source, 'capturePeriod($row[\'id\'], $rows, consistentRead: true)'));
        $this->assertSame(1, substr_count($source, '->capturePeriod('));
        // Re-review R3: both opt-ins default to locking, and every writer keeps that default.
        $capture = (new ReflectionClass(MemberActivationHistoryReader::class))->getMethod('capturePeriod')->getParameters()[2];
        $this->assertSame(['consistentRead', false], [$capture->getName(), $capture->getDefaultValue()]);
        $byHash = (new ReflectionClass(MemberGrantRows::class))->getMethod('definitionByHash')->getParameters()[1];
        $this->assertSame(['committed', false], [$byHash->getName(), $byHash->getDefaultValue()]);
        foreach ([MembershipAwardContext::class => 1, MembershipReservationContext::class => 2, MemberActivationHistoryInspection::class => 1] as $writer => $count) {
            $code = file_get_contents((new ReflectionClass($writer))->getFileName());
            $this->assertSame($count, preg_match_all('/->capturePeriod\(([^()]|\([^()]*\))*\)/', $code, $sites), $writer);
            foreach ($sites[0] as $site) {
                $this->assertSame(1, substr_count($site, ','), $writer.' keeps the locking capture: '.$site);
            }
            $this->assertStringNotContainsString('consistentRead', $code, $writer);
        }
        $reader = file_get_contents((new ReflectionClass(MemberActivationHistoryReader::class))->getFileName());
        $this->assertStringContainsString('\'consistent_read\' => $consistentRead', $reader);
        $this->assertStringContainsString('&& ! $committed && ! ($state[\'consistent_read\'] ?? false) ? \' FOR UPDATE\' : \'\'', $reader);

        // The premise, on the real schema: those rows can never change or disappear, so an unlocked read can only
        // miss a row committed after the read view (a refusal), never see a different one.
        $fixture = $this->memberActivationFixture();
        $rows = ['production_membership_plan_versions' => $fixture['plan']['id'],
            'production_member_definitions' => $fixture['member_authored']->definitionId,
            'production_member_profiles' => $fixture['member_authored']->profileId];
        foreach ($rows as $table => $id) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    $operation === 'update'
                        ? DB::table($table)->where('id', $id)->update(['created_at' => '2000-01-01 00:00:00'])
                        : DB::table($table)->where('id', $id)->delete();
                    $this->fail($operation.' of '.$table.' must be refused by its retention trigger.');
                } catch (QueryException $error) {
                    $this->assertStringContainsString('Retain production', $error->getMessage(), $table.' '.$operation);
                }
            }
            $this->assertSame(1, DB::table($table)->where('id', $id)->count());
        }
        $read = MembershipCustomerRead::begin();
        $this->assertCount(1, (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read)['periods']);
        $read->proveBeforeBytes();
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
        $committed = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use (&$committed): void {
            $committed++;
        });
        $this->refused(fn () => $read->proveBeforeBytes(), 'operation_deadline');
        $this->assertSame(1, $calls);
        // The original deadline stops the re-run inside its transaction: it never reaches its commit.
        $this->assertSame(0, $committed);
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
