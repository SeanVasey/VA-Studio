<?php

namespace Tests\MemberMountGatesProbe;

use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Memberships\Production\MembershipCustomerIndex;
use App\Domain\Memberships\Production\MembershipCustomerRead;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipInvoiceBinding;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Domain\Memberships\Production\MembershipRecords;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Feature\ProductionMembershipBilling\MembershipNativeCustomerIndexTest;

require_once __DIR__.'/HistoricalProofCounter.php';

require_once dirname(__DIR__, 3).'/tests/Feature/ProductionMembershipBilling/MembershipNativeCustomerIndexTest.php';

/** Manual exact-source probe; select only test_physical_pages_with_real_recovered_bindings and serialize runs. */
final class MemberIndexLatencyProbeTest extends MembershipNativeCustomerIndexTest
{
    public function test_physical_pages_with_real_recovered_bindings(): void
    {
        $bindingsCount = (int) getenv('VA_MEMBER_INDEX_BINDINGS');
        $this->assertContains($bindingsCount, [1, 2, 10, 12]);
        $this->assertSame('1', getenv('VA_MEMBER_INDEX_PHYSICAL_PROBE'));
        $this->assertStringStartsWith('8.4.11', DB::connection()->getPdo()->query('SELECT VERSION()')->fetchColumn());
        $fixture = $this->indexFixture();
        $bindings = [$fixture['buyer']['principal']->durableBinding()];
        for ($i = 1; $i < $bindingsCount; $i++) {
            // Respect the actual hourly request policy during fixture creation; clocks are cleared for measurements.
            CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(3601));
            $this->completeIdentity($this->requestIdentity('recover'));
            $current = (new ProductionCustomerSessions)->authenticate('buyer@example.test', 'MailboxPassword123');
            $this->assertNotNull($current);
            $next = $current['principal']->durableBinding();
            $this->assertNotSame($bindings[array_key_last($bindings)]['verification_observation_id'], $next['verification_observation_id']);
            $fixture['buyer'] = $current;
            $bindings[] = $next;
        }
        $this->assertCount($bindingsCount, array_unique(array_column($bindings, 'verification_observation_id')));
        $this->seedOlder($fixture, $bindings);
        $owner = $fixture['buyer']['principal']->accountId;
        $ordered = DB::table(MembershipPeriodRecords::PERIODS)->where('account_id', $owner)->orderByDesc('period_start')->orderByDesc('id')->pluck('id')->all();
        $this->assertCount(100, $ordered);
        $observations = [];
        $sequence = 10;
        foreach ([null, $ordered[49], $ordered[89]] as $page => $cursor) {
            for ($repeat = 1; $repeat <= 3; $repeat++) {
                $award = (new ReflectionMethod(MembershipNativeCustomerIndexTest::class, 'otherCustomerAward'))->invoke($this, $fixture, ++$sequence);
                $this->assertNotSame($owner, $award['buyer']['principal']->accountId, 'The competing award belongs to another actual account.');
                $worker = (new ReflectionMethod(MembershipNativeCustomerIndexTest::class, 'awardWorker'))->invoke($this, $award);
                $directory = (new ReflectionProperty(MembershipNativeCustomerIndexTest::class, 'directory'))->getValue($this);
                $writerConnection = json_decode(file_get_contents($directory.'/ready-0'), true, 8, JSON_THROW_ON_ERROR)['connection_id'];
                $parentConnection = (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
                $signal = (object) ['started' => false, 'active' => true, 'overlap' => null, 'blocked' => false, 'wait_seconds' => 0.0, 'observed' => false, 'lock_trace' => null];
                $listener = static function () use ($signal, $directory): void {
                    if ($signal->active && ! $signal->started) {
                        $signal->started = true;
                        touch($directory.'/start-0');
                    }
                };
                DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, $listener);
                DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($signal, $worker, $writerConnection, $parentConnection, $fixture, $award): void {
                    if (! $signal->active || ! $signal->started || $signal->observed) {
                        return;
                    }
                    $signal->observed = true;
                    $this->assertSame(1, DB::transactionLevel());
                    $this->assertTrue(DB::connection()->getPdo()->inTransaction());
                    $start = hrtime(true);
                    $deadline = $start + 40_000_000_000;
                    do {
                        $trace = $this->writerWaitTrace($parentConnection, $writerConnection, $fixture, $award);
                        if ($trace !== null) {
                            $signal->blocked = true;
                            $signal->lock_trace = $trace;
                            $signal->overlap = 'writer_waits_on_initial_owned_index';
                            break;
                        }
                        if (! $worker->isRunning()) {
                            $signal->overlap = 'award_finished_during_owned_index';
                        } elseif ((new ReflectionMethod(MembershipNativeCustomerIndexTest::class, 'holdsRow'))->invoke($this, $writerConnection,
                            'production_membership_plan_versions', $fixture['plan']['id'])) {
                            $signal->overlap = 'award_holds_plan_during_owned_index';
                        }
                        if ($signal->overlap === null) {
                            usleep(20000);
                        }
                    } while ($signal->overlap === null && hrtime(true) < $deadline);
                    $signal->wait_seconds += (hrtime(true) - $start) / 1e9;
                });
                // Both the latency clock and the measured index use real wall/monotonic time.
                CarbonImmutable::setTestNow();
                $pdo = DB::connection()->getPdo();
                $counters = static fn (): array => array_map('intval', $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Questions','Com_show_create_table','Com_show_create_trigger')")->fetchAll(PDO::FETCH_KEY_PAIR));
                $before = $counters();
                $load = sys_getloadavg();
                $start = hrtime(true);
                $failure = null;
                $index = null;
                try {
                    $read = MembershipCustomerRead::begin();
                    $index = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read, before: $cursor);
                    $read->proveBeforeBytes();
                } catch (\Throwable $error) {
                    $failure = $error;
                } finally {
                    $signal->active = false;
                    $seconds = (hrtime(true) - $start) / 1e9;
                }
                $after = $counters();
                $worker->wait();
                $writer = json_decode($worker->getOutput(), true, 16, JSON_THROW_ON_ERROR);
                $startPosition = $cursor === null ? 0 : array_search($cursor, $ordered, true) + 1;
                $audited = array_slice($ordered, $startPosition, 11);
                if ($cursor !== null) {
                    $audited[] = $cursor;
                }
                $distinct = DB::table(MembershipPeriodRecords::PERIODS)->whereIn('id', $audited)->distinct()->count('owner_binding_hash');
                $delta = [];
                foreach ($after as $key => $value) {
                    $delta[$key] = $value - $before[$key];
                }
                $observation = ['binding_pool' => $bindingsCount, 'page' => ['first', 'middle', 'last'][$page], 'repeat' => $repeat,
                    'index_outcome' => $failure === null ? 'verified' : 'refused',
                    'probe_valid' => $failure === null && $worker->getExitCode() === 0 && $writer['error_class'] === null
                        && $signal->started && $signal->overlap !== null && ! $signal->blocked && count($index['periods']) === 10,
                    'displayed_rows' => $index === null ? null : count($index['periods']),
                    'exception_class' => $failure === null ? null : $failure::class,
                    'reason' => $failure instanceof MembershipException ? $failure->reason : null,
                    'planned_audited_rows' => count($audited), 'planned_distinct_bindings' => $distinct, 'seconds' => $seconds,
                    'load_before' => $load, 'statement_deltas' => $delta, 'writer_error' => $writer['error_class'],
                    'writer_seconds' => $writer['elapsed_seconds'], 'observed_overlap' => $signal->overlap,
                    'overlap_observer_seconds' => $signal->wait_seconds, 'writer_blocked_by_index' => $signal->blocked,
                    'observed_owned_commit' => 'initial-only', 'lock_trace' => $signal->lock_trace];
                $observations[] = $observation;
                fwrite(STDOUT, 'MEMBER_INDEX_PHYSICAL '.json_encode($observation, JSON_THROW_ON_ERROR).PHP_EOL);
                if ($failure !== null) {
                    throw $failure;
                }
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $this->assertNull($writer['error_class'], json_encode($writer));
                $this->assertTrue($signal->started);
                $this->assertNotNull($signal->overlap, 'Real award overlap was not observed.');
                $this->assertFalse($signal->blocked, 'A sampled writer wait on the owned index was observed.');
                $this->assertCount(10, $index['periods']);
                (new Filesystem)->deleteDirectory($directory);
            }
        }
        // Separate unmeasured proof-count read. Instrumentation delegates the exact original MAC primitive.
        $this->assertSame([], config('app.previous_keys'));
        HistoricalProofCounter::$counts = ['capture' => 0, 'current' => 0, 'committed' => 0];
        HistoricalProofCounter::$active = true;
        try {
            $read = MembershipCustomerRead::begin();
            (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read, before: $ordered[49]);
            $read->proveBeforeBytes();
        } finally {
            HistoricalProofCounter::$active = false;
        }
        $this->assertSame(['capture' => 2 * $bindingsCount, 'current' => 2 * $bindingsCount, 'committed' => 2 * $bindingsCount], HistoricalProofCounter::$counts);
        fwrite(STDOUT, 'MEMBER_INDEX_PROOF_COUNTS '.json_encode(['binding_pool' => $bindingsCount, 'counts' => HistoricalProofCounter::$counts], JSON_THROW_ON_ERROR).PHP_EOL);

        // Also prove mixed retained bindings remain reachable through the entire 100-row history.
        $seen = [];
        $cursor = null;
        do {
            $read = MembershipCustomerRead::begin();
            $index = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read, before: $cursor);
            $read->proveBeforeBytes();
            $ids = array_column($index['periods'], 'id');
            $seen = [...$seen, ...$ids];
            $cursor = $ids[array_key_last($ids)];
        } while ($index['hasMore']);
        $this->assertSame($ordered, $seen);
        fwrite(STDOUT, 'MEMBER_INDEX_PHYSICAL_WORST '.json_encode(['binding_pool' => $bindingsCount,
            'seconds' => max(array_column($observations, 'seconds')), 'observations' => count($observations)], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    /** Safe synthetic-fixture lock/plan evidence, only when this exact award session waits on the index. */
    private function writerWaitTrace(int $parent, int $writer, array $fixture, array $award): ?array
    {
        $watch = (new ReflectionMethod(MembershipNativeCustomerIndexTest::class, 'independent'))->invoke($this);
        $query = $watch->prepare('SELECT b.OBJECT_NAME blocking_table,b.INDEX_NAME blocking_index,b.LOCK_TYPE blocking_type,
            b.LOCK_MODE blocking_mode,b.LOCK_DATA blocking_data,r.OBJECT_NAME requesting_table,r.INDEX_NAME requesting_index,
            r.LOCK_MODE requesting_mode,r.LOCK_DATA requesting_data,wt.PROCESSLIST_INFO waiting_sql
            FROM performance_schema.data_lock_waits w
            JOIN performance_schema.threads bt ON bt.THREAD_ID=w.BLOCKING_THREAD_ID
            JOIN performance_schema.threads wt ON wt.THREAD_ID=w.REQUESTING_THREAD_ID
            JOIN performance_schema.data_locks b ON b.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID AND b.ENGINE=w.ENGINE
            JOIN performance_schema.data_locks r ON r.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND r.ENGINE=w.ENGINE
            WHERE bt.PROCESSLIST_ID=? AND wt.PROCESSLIST_ID=?');
        $query->execute([$parent, $writer]);
        $edges = $query->fetchAll(PDO::FETCH_ASSOC);
        if ($edges === []) {
            return null;
        }
        $database = DB::connection()->getDatabaseName();
        $this->assertMatchesRegularExpression('/\A[a-zA-Z0-9_]+\z/D', $database);
        $table = '`'.$database.'`.`production_identity_verifications`';
        $plans = [];
        foreach (['index_owner' => $fixture['buyer']['principal']->accountId, 'award_owner' => $award['buyer']['principal']->accountId] as $label => $account) {
            $origin = $watch->prepare('SELECT id FROM `'.$database.'`.`production_identity_origins` WHERE account_id=?');
            $origin->execute([$account]);
            $originId = $origin->fetchColumn();
            $explain = $watch->prepare('EXPLAIN FORMAT=JSON SELECT * FROM '.$table.' WHERE origin_id = ? ORDER BY id LIMIT 129 FOR UPDATE');
            $explain->execute([$originId]);
            $plans[$label] = ['account_id' => $account, 'origin_id' => (int) $originId,
                'sql' => 'SELECT * FROM '.$table.' WHERE origin_id = ? ORDER BY id LIMIT 129 FOR UPDATE',
                'plan' => json_decode($explain->fetchColumn(), true, 64, JSON_THROW_ON_ERROR)];
        }
        foreach ($edges as &$edge) {
            foreach (['blocking', 'requesting'] as $side) {
                if ($edge[$side.'_table'] === 'production_identity_verifications' && $edge[$side.'_index'] === 'PRIMARY'
                    && ctype_digit((string) $edge[$side.'_data'])) {
                    $row = $watch->prepare('SELECT id,account_id,origin_id,sequence FROM '.$table.' WHERE id=?');
                    $row->execute([$edge[$side.'_data']]);
                    $edge[$side.'_fixture_row'] = $row->fetch(PDO::FETCH_ASSOC);
                }
            }
        }
        unset($edge);

        $history = $watch->prepare("SELECT SQL_TEXT FROM performance_schema.events_statements_history_long
            WHERE THREAD_ID=(SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID=?)
            AND SQL_TEXT LIKE '%production_identity_verifications%' AND SQL_TEXT LIKE '%FOR UPDATE%'
            ORDER BY EVENT_ID DESC LIMIT 6");
        $history->execute([$parent]);

        return ['edges' => $edges, 'history_plans' => $plans, 'recent_blocker_history_sql' => $history->fetchAll(PDO::FETCH_COLUMN)];
    }

    private function seedOlder(array $fixture, array $bindings): void
    {
        $real = (array) DB::table(MembershipPeriodRecords::PERIODS)->where('id', $fixture['reservation']['period_id'])->sole();
        $award = (array) DB::table(MembershipPeriodRecords::EVENTS)->where('period_id', $real['id'])->where('kind', 'award')->sole();
        $plan = (array) DB::table(MembershipRecords::TABLE)->where('id', $real['plan_version_id'])->sole();
        $policy = MembershipPolicyVersions::document($plan)->binding($plan['id']);
        $invoice = MembershipPeriodRecords::historicalInvoice($real, $award, $policy, (int) $real['identity_origin_id']);
        for ($i = 1; $i < 100; $i++) {
            $move = static fn (?string $at): ?string => $at === null ? null : gmdate('Y-m-d H:i:s', strtotime($at.' UTC') - $i * 2678400);
            $copy = new MembershipInvoiceBinding(hash('sha256', 'physical-invoice-'.$i), hash('sha256', 'physical-graph-'.$i),
                $invoice->providerScopeHash, $invoice->amountMinor, $invoice->currency, $move($invoice->periodStart), $move($invoice->periodEnd),
                $invoice->planPolicyHash, $invoice->originalTermsHash, $bindings[$i % count($bindings)], $invoice->provenance,
                $move($invoice->paidAt), $invoice->planVersionId);
            [$period, $event] = MembershipPeriodRecords::create($copy, $policy, (int) $real['identity_origin_id'], $fixture['buyer']['principal']->durableBinding());
            DB::table(MembershipPeriodRecords::PERIODS)->insert($period);
            DB::table(MembershipPeriodRecords::EVENTS)->insert($event);
        }
    }
}
