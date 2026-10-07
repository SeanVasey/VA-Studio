<?php

namespace Tests\Feature;

use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class ConsentNativeRaceProbeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native exact record lock only.');
        }
    }

    public function test_real_withdrawal_serializes_a_stale_grant_even_with_old_repeatable_read_snapshot(): void
    {
        ConsentFixtures::configure();
        $f = CustomerFixtures::account();
        $d = storage_path('framework/testing/consent-review-'.Str::uuid());
        (new Filesystem)->makeDirectory($d, 0700, true);
        $ps = [];
        $wait = [];
        $results = [];
        try {
            foreach (['first' => ['grant' => false, 'pause' => true], 'second' => ['grant' => true, 'snapshot' => true]] as $name => $extra) {
                $ps[$name] = new Process([PHP_BINARY, __DIR__.'/consent-native-worker.php'], base_path(), ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'], json_encode(['user' => $f['user']->id, 'directory' => $d, 'name' => $name] + $extra, JSON_THROW_ON_ERROR), 40);
                $ps[$name]->start();
            }
            foreach ($ps as $name => $p) {
                $this->await(fn () => is_file($d.'/ready-'.$name), $ps);
            }
            $first = json_decode(file_get_contents($d.'/ready-first'), true);
            $second = json_decode(file_get_contents($d.'/ready-second'), true);
            $this->assertCount(3, array_unique([getmypid(), $first['pid'], $second['pid']]));
            touch($d.'/start-first');
            $this->await(fn () => is_file($d.'/locked-first'), $ps);
            touch($d.'/start-second');
            $sql = "SELECT requested.INDEX_NAME index_name,requested.LOCK_TYPE lock_type,requested.LOCK_STATUS lock_status FROM performance_schema.data_lock_waits w JOIN performance_schema.threads requester ON requester.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads blocker ON blocker.THREAD_ID=w.BLOCKING_THREAD_ID JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE=w.ENGINE WHERE w.ENGINE='INNODB' AND requester.PROCESSLIST_ID=? AND blocker.PROCESSLIST_ID=? AND requested.OBJECT_SCHEMA=? AND requested.OBJECT_NAME='users' AND requested.LOCK_TYPE='RECORD' AND requested.LOCK_STATUS='WAITING' AND requested.INDEX_NAME='PRIMARY' AND requested.LOCK_DATA=? LIMIT 1";
            $this->await(function () use ($sql, $second, $first, $f, &$wait) {
                $row = DB::selectOne($sql, [$second['connection'], $first['connection'], DB::getDatabaseName(), (string) $f['user']->id]);
                if ($row) {
                    $wait = (array) $row;
                }

return $row !== null;
            }, $ps);
            $this->assertFileExists($d.'/snapshot-second');
            touch($d.'/release-first');
            foreach ($ps as $name => $p) {
                $p->wait();
                $this->assertSame(0, $p->getExitCode(), $p->getOutput().$p->getErrorOutput());
                $results[$name] = json_decode($p->getOutput(), true, 16, JSON_THROW_ON_ERROR);
                $this->assertSame(0, $results[$name]['transaction_level']);
            }
            $this->assertSame('saved', $results['first']['result']);
            $this->assertSame('rejected', $results['second']['result']);
            $this->assertSame(409, $results['second']['status']);
            $this->assertSame(0, $results['second']['snapshot_revision']);
            $this->assertSame('REPEATABLE-READ', $results['second']['isolation']);
            $this->assertSame(1, DB::table('customer_consent_events')->count());
            $this->assertSame(0, DB::table('customer_consent_policies')->count());
            $this->assertSame('withdrawn', app(CustomerConsentPreferences::class)->read($f['principal'], $f['user'])['purposes'][0]['status']);
            file_put_contents(__DIR__.'/'.getenv('CONSENT_PROBE_PREFIX').'-race.json', json_encode(['source' => getenv('CONSENT_REVIEW_SOURCE'), 'mysql' => DB::selectOne('SELECT VERSION() v')->v, 'exact_users_record_wait' => $wait, 'workers' => $results, 'final_revision' => 1, 'final_status' => 'withdrawn', 'events' => 1, 'policies' => 0], JSON_PRETTY_PRINT)."\n");
        } finally {
            foreach ($ps as $p) {
                if ($p->isRunning()) {
                    $p->stop(1);
                }
            }(new Filesystem)->deleteDirectory($d);
        }
    }

    private function await(callable $ready, array $ps): void
    {
        $deadline = microtime(true) + 15;
        do {
            clearstatcache();
            if ($ready()) {
                return;
            }foreach ($ps as $p) {
                if (! $p->isRunning()) {
                    $this->fail($p->getOutput().$p->getErrorOutput());
                }$p->checkTimeout();
            }usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Native worker did not reach exact expected record wait.');
    }
}
