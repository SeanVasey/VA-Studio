<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingReconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/**
 * Two retrievals of the same invoice give one invoice identity and at most two ordered observations (the one that began first is
 * refused if it appends second).
 * Native MySQL uses two independent processes released by one barrier. SQLite (single process, one
 * in-memory database) runs the same two retrievals sequentially; it does not prove concurrency.
 */
class BillingNativeDedupRaceTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_two_retrievals_of_one_invoice_give_one_identity_and_two_ordered_observations(): void
    {
        F::configure();
        $binding = F::binding();
        $expected = 2;
        if (DB::getDriverName() === 'mysql') {
            $results = $this->race($binding['id']);
            // Both workers begin together, so either may begin first. If the one that began first appends second, its snapshot is
            // older than the tail's and the append refuses it (review R-6, BillingLedger::append); that is a normal end, not a
            // lost retrieval, so the invariants are one identity and one contiguous chain holding exactly the saved observations.
            foreach ($results as $result) {
                $this->assertTrue($result['result'] === 'saved' || ($result['result'] === 'denied' && $result['reason'] === 'superseded_retrieval'), json_encode($results));
            }
            $saved = array_values(array_filter($results, fn (array $result): bool => $result['result'] === 'saved'));
            $this->assertNotEmpty($saved, json_encode($results));
            $expected = count($saved);
            $this->assertSame(range(1, $expected), array_column($saved, 'sequence'));
            $this->assertCount(1, array_unique(array_column($saved, 'invoice_id')));
            $this->assertSame([0, 0], array_column($results, 'transaction_level'));
        } else {
            CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(F::PERIOD_START + 3600));
            try {
                foreach ([1, 2] as $attempt) {
                    (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
                }
            } finally {
                CarbonImmutable::setTestNow();
            }
        }
        $invoices = DB::table('production_membership_billing_invoices')->get();
        $this->assertCount(1, $invoices);
        $chain = (new BillingLedger)->observations($invoices[0]->id);
        $this->assertSame(range(1, $expected), array_column($chain, 'sequence'));
        $this->assertSame(array_fill(0, $expected, 'settled'), array_column($chain, 'outcome'));
        for ($index = 1; $index < $expected; $index++) {
            $this->assertSame($chain[$index - 1]['seal'], $chain[$index]['prior_seal']);
        }
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    private function race(string $bindingId): array
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/membership-billing-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        try {
            foreach ([0, 1] as $worker) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/membership-billing-race-worker.php')], base_path(),
                    $this->environment($directory, $worker), json_encode(['binding_id' => $bindingId], JSON_THROW_ON_ERROR), 60);
                $process->start();
                $processes[] = $process;
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
            $connections = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1'),
                (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id];
            $this->assertCount(3, array_unique($connections));
            touch($directory.'/start');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            usort($results, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);

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
            'VA_MEMBERSHIP_BILLING_RACE_ONLY' => '1', 'VA_MEMBERSHIP_BILLING_RACE_DIRECTORY' => $directory, 'VA_MEMBERSHIP_BILLING_RACE_WORKER' => (string) $worker];
    }
}
