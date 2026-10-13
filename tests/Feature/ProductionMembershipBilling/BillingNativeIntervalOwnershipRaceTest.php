<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingSchema;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BillingIntervalFixtures;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class BillingNativeIntervalOwnershipRaceTest extends TestCase
{
    use BillingIntervalFixtures;
    use FinalizationDatabaseMigrations;

    public function test_independent_transactions_with_older_snapshots_cannot_share_any_endpoint_across_ledgers(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent-process REPEATABLE READ endpoint arbitration requires native MySQL.');
        }
        F::configure();
        $binding = F::binding();
        foreach (['start_start', 'start_end', 'end_start', 'end_end'] as $pair) {
            [$a, $b, $c, $d, $e] = $this->positions(5);
            $one = $this->intervalRows($binding, $b, $d);
            [$start, $end] = match ($pair) {
                'start_start' => [$b, $e], 'start_end' => [$a, $b], 'end_start' => [$d, $e], 'end_end' => [$a, $d],
            };
            $two = $this->intervalRows($binding, $start, $end);
            $rows = [BillingSchema::TABLES[2] => $one[BillingSchema::TABLES[2]], BillingSchema::TABLES[5] => $two[BillingSchema::TABLES[5]]];
            $results = $this->race($rows);
            $saved = array_filter($results, fn ($result) => $result['result'] === 'saved');
            $this->assertCount(1, $saved, json_encode($results));
            foreach ($results as $result) {
                if ($result['result'] === 'denied') {
                    $this->assertTrue($result['sqlstate'] === '45000' || ($result['sqlstate'] === '40001' && $result['driver_code'] === 1213), json_encode($result));
                }
            }
            $this->assertSame(1, DB::table(BillingSchema::TABLES[2])->where('id', $rows[BillingSchema::TABLES[2]]['id'])->count()
                + DB::table(BillingSchema::TABLES[5])->where('id', $rows[BillingSchema::TABLES[5]]['id'])->count());
            $this->assertSame([0, 0], array_column($results, 'transaction_level'));
        }
        $this->assertSame(0, DB::table('production_membership_paid_periods')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    private function race(array $rows): array
    {
        $directory = storage_path('framework/testing/membership-interval-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $db = DB::connection()->getConfig();
        $processes = [];
        try {
            foreach (array_keys($rows) as $index => $table) {
                $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
                    'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VA_MEMBERSHIP_INTERVAL_RACE_ONLY' => '1', 'VA_MEMBERSHIP_INTERVAL_RACE_DIRECTORY' => $directory, 'VA_MEMBERSHIP_INTERVAL_RACE_WORKER' => (string) $index];
                $process = new Process([PHP_BINARY, base_path('tests/Support/membership-interval-race-worker.php')], base_path(), $environment,
                    json_encode(['table' => $table, 'row' => $rows[$table]], JSON_THROW_ON_ERROR), 60);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 40;
            while (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Interval worker exited: '.$process->getOutput().$process->getErrorOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Interval workers did not reach the shared barrier.');
                }
                usleep(10000);
                clearstatcache();
            }
            $connections = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1'),
                (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn()];
            $this->assertCount(3, array_unique($connections));
            touch($directory.'/start');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));

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
}
