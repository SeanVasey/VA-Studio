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
 * Codex P1 on PR #54 (`BillingReconciliation.php:42`), native. Two independent PHP processes on two MySQL connections retrieve one
 * invoice. Retrieval A takes its start position and stalls BEFORE its first provider read; retrieval B takes a later start and reads
 * a settled graph; the provider then reverses the payment and A reads the reversal. A start position allocated before a read does
 * not order the reads, so with start-only ordering B's older settled read could become the tail after A's reversal, or A's newer
 * reversal could be dismissed as superseded. With the read bracketed by start and end positions both orders converge on the
 * reversal. SQLite runs the same interleavings in one process (BillingRetrievalIntervalOrderingTest); this case is native only.
 */
class BillingNativeIntervalRetrievalRaceTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const REFUND = ['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]];

    public function test_a_retrieval_stalled_before_its_reads_appends_the_reversal_and_the_overlapping_older_settled_read_cannot_follow_it(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL only: two processes need one shared server database; SQLite is covered by BillingRetrievalIntervalOrderingTest.');
        }
        F::configure();
        $binding = F::binding();
        $seed = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);

        $results = $this->race($binding['id'], 'reversal_first');

        $this->assertSame(['saved', 2, 'reversed'], [$results['a']['result'], $results['a']['sequence'], $results['a']['outcome']], json_encode($results));
        $this->assertSame(['denied', 'concurrent_retrieval'], [$results['b']['result'], $results['b']['reason']], json_encode($results));
        $this->assertSame([0, 0], [$results['a']['transaction_level'], $results['b']['transaction_level']]);
        $this->assertNotSame($results['a']['connection_id'], $results['b']['connection_id']);
        $this->assertNotSame($results['a']['pid'], $results['b']['pid']);

        $ledger = new BillingLedger;
        $chain = $ledger->observations($seed['invoice_id']);
        $this->assertSame(['settled', 'reversed'], array_column($chain, 'outcome'));
        $this->assertNull($ledger->currentSettled($seed['invoice_id'], time()), 'The older settled read must never become the tail.');
        // B's two positions were issued (start after A's start, end after A's end) but back no observation.
        $this->assertSame(6, DB::table('production_membership_billing_positions')->where('kind', 'retrieval')->count());
        $this->assertSame(1, DB::table('production_membership_billing_invoices')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    public function test_a_retrieval_stalled_before_its_reads_is_refused_for_retry_after_an_overlapping_settled_append_and_the_retry_records_the_reversal(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL only: two processes need one shared server database; SQLite is covered by BillingRetrievalIntervalOrderingTest.');
        }
        F::configure();
        $binding = F::binding();
        $seed = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);

        $results = $this->race($binding['id'], 'settled_first');

        $this->assertSame(['saved', 2, 'settled'], [$results['b']['result'], $results['b']['sequence'], $results['b']['outcome']], json_encode($results));
        $this->assertSame(['denied', 'concurrent_retrieval'], [$results['a']['result'], $results['a']['reason']], json_encode($results),
            'A read that began before the tail\'s read ended is retried, not ended as superseded.');
        $this->assertNotSame($results['a']['connection_id'], $results['b']['connection_id']);
        $this->assertGreaterThan($results['b']['position'], $results['b']['end_position']);

        // The job's retry reads afresh, after B's read ended, and is admitted.
        $retry = (new BillingReconciliation(new RehearsalBillingGateway(F::graph(self::REFUND))))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['reversed', 3], [$retry['outcome'], $retry['sequence']]);
        $ledger = new BillingLedger;
        $this->assertSame(['settled', 'settled', 'reversed'], array_column($ledger->observations($seed['invoice_id']), 'outcome'));
        $this->assertNull($ledger->currentSettled($seed['invoice_id'], time()));
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    private function race(string $bindingId, string $scenario): array
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/membership-billing-interval-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        try {
            foreach (['a', 'b'] as $worker => $role) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/membership-billing-interval-race-worker.php')], base_path(),
                    $this->environment($directory, $worker), json_encode(['binding_id' => $bindingId, 'role' => $role, 'scenario' => $scenario], JSON_THROW_ON_ERROR), 120);
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
            $this->assertFileExists($directory.'/ready-0');
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
            'VA_MEMBERSHIP_BILLING_INTERVAL_RACE_ONLY' => '1', 'VA_MEMBERSHIP_BILLING_INTERVAL_RACE_DIRECTORY' => $directory,
            'VA_MEMBERSHIP_BILLING_INTERVAL_RACE_WORKER' => (string) $worker];
    }
}
