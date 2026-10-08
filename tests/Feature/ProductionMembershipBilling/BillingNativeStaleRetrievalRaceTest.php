<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingReconciliation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/**
 * Review R-6, native. Two independent PHP processes on two MySQL connections retrieve one invoice. The stale one reads a settled
 * graph and stalls before its append; the fresh one starts after that read, sees the refund, and appends first. Without the
 * append-time ordering rule the stale snapshot lands after the `reversed` observation and `currentSettled()` names it. SQLite runs
 * only the in-process simulation in BillingOverlappingRetrievalTest; it cannot prove two connections, so this case is native only.
 */
class BillingNativeStaleRetrievalRaceTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_a_stale_settled_retrieval_from_a_second_process_is_refused_after_a_newer_reversal(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL only: two processes need one shared server database; SQLite is covered by BillingOverlappingRetrievalTest.');
        }
        F::configure();
        $binding = F::binding();
        $seed = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['settled', 1], [$seed['outcome'], $seed['sequence']]);

        $results = $this->race($binding['id']);

        $this->assertSame('saved', $results['fresh']['result'], json_encode($results));
        $this->assertSame([2, 'reversed'], [$results['fresh']['sequence'], $results['fresh']['outcome']]);
        $this->assertSame(['denied', 'superseded_retrieval'], [$results['stale']['result'], $results['stale']['reason']], json_encode($results));
        $this->assertSame([0, 0], [$results['stale']['transaction_level'], $results['fresh']['transaction_level']]);
        $this->assertNotSame($results['stale']['connection_id'], $results['fresh']['connection_id']);
        $this->assertNotSame($results['stale']['pid'], $results['fresh']['pid']);

        $ledger = new BillingLedger;
        $chain = $ledger->observations($seed['invoice_id']);
        $this->assertSame(['settled', 'reversed'], array_column($chain, 'outcome'));
        $this->assertNull($ledger->currentSettled($seed['invoice_id'], time()), 'The stale settled snapshot must never be current evidence.');
        $this->assertSame(1, DB::table('production_membership_billing_invoices')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    /**
     * Codex P1 on PR #54 (review L2-3): the stale worker's clock runs two minutes ahead, so by wall clock its retrieval "began"
     * after the fresh one. The database-issued position still orders it first, and its stale snapshot is refused.
     */
    public function test_a_stale_retrieval_whose_worker_clock_runs_ahead_is_still_refused_after_a_newer_reversal(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL only: two processes need one shared server database; SQLite is covered by BillingClockSkewOrderingTest.');
        }
        F::configure();
        $binding = F::binding();
        $seed = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);

        $results = $this->race($binding['id'], 120);

        $this->assertSame('saved', $results['fresh']['result'], json_encode($results));
        $this->assertSame([2, 'reversed'], [$results['fresh']['sequence'], $results['fresh']['outcome']]);
        $this->assertSame(['denied', 'superseded_retrieval'], [$results['stale']['result'], $results['stale']['reason']], json_encode($results));
        $this->assertNotSame($results['stale']['connection_id'], $results['fresh']['connection_id']);
        $ledger = new BillingLedger;
        $chain = $ledger->observations($seed['invoice_id']);
        $this->assertSame(['settled', 'reversed'], array_column($chain, 'outcome'));
        $this->assertGreaterThan($chain[1]['retrieval_started_at'], $results['stale']['clock'], 'By its skewed clock the stale retrieval began after the fresh one.');
        $this->assertNull($ledger->currentSettled($seed['invoice_id'], time()), 'The stale settled snapshot must never be current evidence.');
        $this->assertSame(1, DB::table('production_membership_billing_invoices')->count());
    }

    private function race(string $bindingId, int $staleClockOffset = 0): array
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/membership-billing-stale-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        try {
            foreach (['stale', 'fresh'] as $worker => $role) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/membership-billing-stale-race-worker.php')], base_path(),
                    $this->environment($directory, $worker), json_encode(['binding_id' => $bindingId, 'role' => $role,
                        'clock_offset_seconds' => $role === 'stale' ? $staleClockOffset : 0], JSON_THROW_ON_ERROR), 120);
                $process->start();
                $processes[$role] = $process;
            }
            $deadline = microtime(true) + 40;
            do {
                clearstatcache();
                if (is_file($directory.'/ready-0') && is_file($directory.'/ready-1')) {
                    break;
                }
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Billing worker exited: '.$process->getOutput().$process->getErrorOutput());
                    }
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertFileExists($directory.'/ready-1');
            touch($directory.'/start');
            $results = [];
            foreach ($processes as $role => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[$role] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $filesystem->deleteDirectory($directory);
        }
    }

    private function environment(string $directory, int $worker): array
    {
        $db = DB::connection()->getConfig();

        return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VA_MEMBERSHIP_BILLING_STALE_RACE_ONLY' => '1', 'VA_MEMBERSHIP_BILLING_STALE_RACE_DIRECTORY' => $directory,
            'VA_MEMBERSHIP_BILLING_STALE_RACE_WORKER' => (string) $worker];
    }
}
