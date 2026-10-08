<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingSchema;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/**
 * Codex P2 on PR #54 (`BillingLedger.php:173`), native. `append()` holds SELECT ... FOR UPDATE on one invoice's identity row for the
 * length of its short append transaction. The invoices insert guard once checked `invoice_ref_hash = ? OR source_invoice_hash = ?`
 * in one subquery; a trigger subquery inside an INSERT is a locking read, and the OR reached every row, so claiming an unrelated
 * invoice waited for another invoice's append lock. The guard is now two point lookups on the two unique indexes. This test holds
 * the lock on invoice A in this process while a second process, with a 3 s lock wait timeout, claims unrelated invoice B and runs
 * a full first retrieval of unrelated invoice C. Both must finish while A's lock is still held. SQLite has one writer and no row
 * locks, so this case is native only.
 */
class BillingNativeCrossInvoiceClaimTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const LOCK_WAIT_TIMEOUT = 3;

    private const HOLD_SECONDS = 20;

    public function test_claiming_and_retrieving_unrelated_invoices_never_waits_for_another_invoices_append_lock(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL only: row locks across two connections; SQLite has a single writer.');
        }
        F::configure();
        $binding = F::binding();
        $seed = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(1, $seed['sequence']);

        [$result, $doneWhileLocked, $held] = $this->whileInvoiceIsLocked($seed['invoice_id'], [
            'binding_id' => $binding['id'], 'claim_invoice' => 'in_SYNTHETICCROSSCLAIM', 'retrieve_invoice' => 'in_SYNTHETICCROSSRETRIEVE',
            'lock_wait_timeout_seconds' => self::LOCK_WAIT_TIMEOUT]);

        $summary = json_encode($result + ['done_while_locked' => $doneWhileLocked, 'held_seconds' => round($held, 3)]);
        fwrite(STDERR, 'OBSERVATION cross-invoice: '.$summary.PHP_EOL);
        $this->assertSame(self::LOCK_WAIT_TIMEOUT, $result['lock_wait_timeout'], $summary);
        $this->assertSame('claimed 36', $result['claim'], 'Claiming an unrelated invoice must not wait for another invoice\'s lock. '.$summary);
        $this->assertSame('saved sequence 1', $result['retrieve'], 'A first retrieval of an unrelated invoice must not wait either. '.$summary);
        $this->assertTrue($doneWhileLocked, 'Both finished while invoice A was still locked. '.$summary);
        $this->assertLessThan(self::LOCK_WAIT_TIMEOUT, $result['claim_seconds'], $summary);
        $this->assertSame(0, $result['transaction_level']);

        $this->assertSame(3, DB::table('production_membership_billing_invoices')->count());
        $this->assertSame([1], array_column((new BillingLedger)->observations($seed['invoice_id']), 'sequence'), 'Invoice A is untouched.');
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    /** @return array{0: array, 1: bool, 2: float} the worker's result, whether it finished while the lock was held, and the hold time */
    private function whileInvoiceIsLocked(string $invoiceId, array $input): array
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/membership-billing-cross-invoice-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $process = new Process([PHP_BINARY, base_path('tests/Support/membership-billing-cross-invoice-worker.php')], base_path(),
            $this->environment($directory), json_encode($input, JSON_THROW_ON_ERROR), 120);
        try {
            $process->start();
            $deadline = microtime(true) + 40;
            while (! is_file($directory.'/ready') && microtime(true) < $deadline) {
                if (! $process->isRunning()) {
                    $this->fail('Billing worker exited: '.$process->getOutput().$process->getErrorOutput());
                }
                usleep(10000);
                clearstatcache();
            }
            $this->assertFileExists($directory.'/ready');
            $this->assertNotSame((int) file_get_contents($directory.'/ready'), (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);

            // Exactly the lock BillingLedger::append() takes (lockIdentity()), held in an open transaction.
            DB::beginTransaction();
            try {
                $statement = DB::connection()->getPdo()->prepare('SELECT id FROM '.(new BillingSchema)->table(BillingSchema::TABLES[1]).' WHERE id = ? FOR UPDATE');
                $statement->execute([$invoiceId]);
                $this->assertCount(1, $statement->fetchAll());
                touch($directory.'/locked');
                $begin = microtime(true);
                do {
                    usleep(20000);
                    clearstatcache();
                    $done = is_file($directory.'/worker-done');
                } while (! $done && microtime(true) - $begin < self::HOLD_SECONDS && $process->isRunning());
                $held = microtime(true) - $begin;
            } finally {
                DB::commit();
            }
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

            return [json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR), $done, $held];
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            $filesystem->deleteDirectory($directory);
        }
    }

    private function environment(string $directory): array
    {
        $db = DB::connection()->getConfig();

        return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VA_MEMBERSHIP_BILLING_CROSS_INVOICE_ONLY' => '1', 'VA_MEMBERSHIP_BILLING_CROSS_INVOICE_DIRECTORY' => $directory];
    }
}
