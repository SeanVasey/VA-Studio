<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionIdentity\SchemaProofFrame;
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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDO;
use ReflectionClass;
use ReflectionProperty;
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
        $frame = substr($source, strpos($source, '$projection = DB::transaction(function ()'));
        $this->assertLessThan(strpos($frame, 'new MembershipRows'), strpos($frame, '$this->lockOwnerFirst($primary, $principal->userId);'),
            'The owner row is locked before the first consistent read (the schema proofs in MembershipRows).');
        $this->assertStringNotContainsString('$rows->one(', $source);
        $this->assertStringNotContainsString('$rows->rows(', $source);
        $this->assertSame(1, substr_count($source, '->lock('));
        preg_match_all('/\$(?:reader|facts)->(plan|profile|definition|definitionByHash|profileByHash)\((?:[^()]|\([^()]*\))*\)/', $source, $calls);
        $this->assertGreaterThanOrEqual(8, count($calls[0]));
        foreach ($calls[0] as $call) {
            $this->assertStringEndsWith(', true)', $call, 'Shared facts are read without a row lock: '.$call);
        }
        $this->assertSame(1, substr_count($source, 'capturePeriod($row[\'id\'], $rows, consistentRead: true, schema: $schema)'));
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

    public function test_index_proves_schema_once_per_owned_frame_and_completely_in_its_terminal_phases(): void
    {
        // F9: the index reads every period inside one owned frame with G1's frame-scoped schema proofs, member-grant
        // proofs included, and verifies each distinct retained buyer binding once. Its raw phases prove the member-grant
        // schema once around all periods. Every phase that releases or compares stays complete: the owned terminal
        // phase begins before the current identity and grant proofs, and the committed frame proves in full.
        $source = file_get_contents(base_path('app/Domain/Memberships/Production/MembershipCustomerIndex.php'));
        // strpos returns false for a missing call, and false compares below any int: every position is pinned first.
        $at = function (string $haystack, string $needle, bool $last = false): int {
            $position = $last ? strrpos($haystack, $needle) : strpos($haystack, $needle);
            $this->assertIsInt($position, 'Missing: '.$needle);

            return $position;
        };
        $frame = substr($source, $at($source, '$projection = DB::transaction(function ()'));
        $this->assertSame(1, substr_count($source, '$owned = new MembershipAwardTransaction($connection, $primary, memberGrantProofs: true);'));
        $this->assertLessThan($at($frame, '$owned = new MembershipAwardTransaction('), $at($frame, '$this->lockOwnerFirst($primary, $principal->userId);'));
        $this->assertLessThan($at($frame, '$rows = new MembershipRows($owned);'), $at($frame, '$owned = new MembershipAwardTransaction('));
        $terminal = $at($frame, '$owned->beginTerminalProof();');
        $this->assertSame(1, substr_count($source, '->beginTerminalProof();'));
        foreach (['$history->currentCallbacks(', '$access->proveHistoricalBindingCurrent('] as $memo) {
            $this->assertLessThan($terminal, $at($frame, $memo), $memo.' runs in the memo phase.');
        }
        $current = substr($frame, 0, $at($frame, 'return $result;'));
        foreach (['$grants->proveCurrent(', '$access->proveCurrent(', '$history->currentRawAll(', '$rows->assertCurrent();'] as $complete) {
            $this->assertGreaterThan($terminal, $at($current, $complete, true), $complete.' runs in the terminal phase.');
        }
        foreach (['->currentRaw(', '->committedRaw(', '->committedCallbacks('] as $perPeriod) {
            $this->assertStringNotContainsString($perPeriod, $source);
        }
        $this->assertSame(1, substr_count($source, '$history->committedCallbacksAll('));
        $this->assertSame(1, substr_count($source, '$history->committedRawAll('));
        $this->assertSame(1, substr_count($source, '->verifyHistoricalBinding('));
        $this->assertSame(1, substr_count($source, '$state[\'historical\'][$key] ??= $access->verifyHistoricalBinding($buyer, $rows->current());'));
        // Writers keep their complete member-grant proofs: only the index opts in.
        foreach (['MembershipAwardObserver', 'MembershipReservationObserver'] as $observer) {
            $code = file_get_contents(base_path('app/Domain/Memberships/Production/'.$observer.'.php'));
            $this->assertStringContainsString('new MembershipAwardTransaction($this->connection, $this->primary);', $code);
        }
        foreach (['MemberActivationReadObserver', 'MemberOriginalObserver', 'MemberActivationObserver'] as $observer) {
            $code = file_get_contents(base_path('app/Domain/Grants/Member/'.$observer.'.php'));
            $this->assertStringContainsString('new MembershipAwardTransaction($this->connection, $this->primary);', $code);
        }
        $this->assertSame(1, preg_match_all('/memberGrantProofs: true/', implode("\n", array_map(
            static fn (string $file): string => (string) file_get_contents($file), glob(base_path('app/Domain/{Memberships,Grants}/*/*.php'), GLOB_BRACE)))));

        $fixture = $this->memberActivationFixture();
        $read = MembershipCustomerRead::begin();
        $this->assertCount(1, (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read)['periods']);
        $read->proveBeforeBytes();
    }

    public function test_index_terminal_phase_proves_in_full_after_remembered_proofs(): void
    {
        // As MemberObserverTerminalProofTest for the writers: while the index frame still answers from memory, a table
        // appears under a reserved identity index name. On MySQL another session creates it, which takes no metadata
        // lock and moves no frame token, so only a complete proof sees it, and the owned terminal phase must refuse it
        // before the index commits (the committed frame would refuse it only after the commit). On SQLite the
        // same-connection DDL moves the catalog token, so the next guard proves in full at once.
        $fixture = $this->memberActivationFixture();
        $collision = 'pia_address_unique';
        $other = null;
        if (DB::getDriverName() === 'mysql') {
            $config = DB::connection()->getConfig();
            $address = empty($config['unix_socket']) ? 'host='.$config['host'].';port='.$config['port'] : 'unix_socket='.$config['unix_socket'];
            $other = new PDO('mysql:'.$address.';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $other->exec('SET SESSION lock_wait_timeout=3, innodb_lock_wait_timeout=3');
        }
        $primary = DB::connection()->getPdo();
        $seen = (object) ['frame' => null, 'terminal' => null, 'remembered' => null];
        $real = Crypt::getFacadeRoot();
        $hook = Mockery::mock();
        $hook->shouldReceive('encryptString')->andReturnUsing(fn ($plain) => $real->encryptString($plain));
        $hook->shouldReceive('decryptString')->andReturnUsing(function ($ciphertext) use ($real, $seen, $primary, $other, $collision) {
            if ($seen->frame === null && DB::transactionLevel() === 1) {
                $frames = (new ReflectionProperty(SchemaProofFrame::class, 'frames'))->getValue();
                $frame = $frames !== null && isset($frames[$primary]) ? $frames[$primary]->get() : null;
                $remembered = $frame instanceof SchemaProofFrame ? array_keys((new ReflectionProperty(SchemaProofFrame::class, 'proofs'))->getValue($frame)) : [];
                if (in_array('identity', $remembered, true) && in_array('membership', $remembered, true)) {
                    $seen->frame = $frame;
                    $seen->terminal = (new ReflectionProperty(SchemaProofFrame::class, 'terminal'))->getValue($frame);
                    $seen->remembered = $remembered;
                    $sql = 'CREATE TABLE '.$collision.' (id INTEGER PRIMARY KEY)';
                    $other === null ? $primary->exec($sql) : $other->exec($sql);
                }
            }

            return $real->decryptString($ciphertext);
        });
        $committed = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use (&$committed): void {
            $committed++;
        });
        Crypt::swap($hook);
        try {
            (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user']);
            $this->fail('The index admitted a reserved-name collision.');
        } catch (MembershipException $error) {
            $this->assertNotSame('operation_deadline', $error->reason);
        } finally {
            Crypt::swap($real);
            $other?->exec('DROP TABLE IF EXISTS '.$collision);
        }
        $this->assertInstanceOf(SchemaProofFrame::class, $seen->frame, 'No owned index frame was remembering proofs.');
        $this->assertFalse($seen->terminal);
        $this->assertNotEmpty(array_filter($seen->remembered, static fn (string $guard): bool => str_starts_with($guard, 'member_grant:')),
            'Member-grant proofs are remembered in the index frame.');
        // Refused inside the index transaction, before its commit.
        $this->assertSame(0, $committed);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($primary->inTransaction());
        if ($other !== null) {
            // MySQL: the refusal came from the owned terminal phase, which disabled the memo first.
            $this->assertTrue((new ReflectionProperty(SchemaProofFrame::class, 'terminal'))->getValue($seen->frame));
        } else {
            // SQLite: the collision was the refused transaction's own DDL and rolled back with it.
            $this->assertFalse(DB::getSchemaBuilder()->hasTable($collision));
        }
        // The index answers again once the collision is gone.
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
