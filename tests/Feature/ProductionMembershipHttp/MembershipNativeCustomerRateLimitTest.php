<?php

namespace Tests\Feature\ProductionMembershipHttp;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;

final class MembershipNativeCustomerRateLimitTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberActivationFixtures;

    public function test_native_database_limiter_caps_independent_process_admissions_and_releases_its_lock(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent-process admission requires the private native MySQL cache.');
        }
        $this->memberActivationSetup();
        $fixture = $this->memberActivationFixture();
        $directory = storage_path('framework/testing/member-admission-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $configuration = [];
        foreach (['production-customer-identity', 'production-memberships', 'member-grants'] as $parent) {
            $configuration[$parent] = config($parent);
        }
        $configuration['cache.default'] = 'array';
        $configuration['cache.limiter'] = 'native-member-admission';
        $configuration['cache.stores.native-member-admission'] = ['driver' => 'database', 'connection' => null,
            'table' => 'cache', 'lock_connection' => null, 'lock_table' => 'cache_locks'];
        file_put_contents($directory.'/configuration.json', json_encode($configuration, JSON_THROW_ON_ERROR));
        $workers = [];
        try {
            foreach ([0, 1] as $id) {
                $workers[$id] = new Process([PHP_BINARY, base_path('tests/Support/membership-customer-limiter-worker.php'), $directory, (string) $id, '8'], base_path(), ['APP_KEY' => config('app.key')], timeout: 90);
                $workers[$id]->start();
            }
            $deadline = hrtime(true) + 40_000_000_000;
            while ((! file_exists($directory.'/ready-0') || ! file_exists($directory.'/ready-1')) && hrtime(true) < $deadline) {
                foreach ($workers as $worker) {
                    $this->assertTrue($worker->isRunning(), $worker->getErrorOutput().$worker->getOutput());
                }
                usleep(20000);
            }
            $this->assertFileExists($directory.'/ready-0');
            $this->assertFileExists($directory.'/ready-1');
            touch($directory.'/start');
            $outcomes = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput().$worker->getOutput());
                $result = json_decode($worker->getOutput(), true, 8, JSON_THROW_ON_ERROR);
                $outcomes = [...$outcomes, ...$result['outcomes']];
            }
            $this->assertLessThanOrEqual(10, count(array_filter($outcomes, static fn (int $status): bool => $status === 200)));
            $this->assertSame([], array_values(array_diff($outcomes, [200, 429, 503])));
            // Fill any slots conservatively refused by nonblocking contention; exhaustion must remain at ten total.
            $last = new Process([PHP_BINARY, base_path('tests/Support/membership-customer-limiter-worker.php'), $directory, 'last', '12'], base_path(), ['APP_KEY' => config('app.key')], timeout: 90);
            $workers[] = $last;
            $last->run();
            $this->assertSame(0, $last->getExitCode(), $last->getErrorOutput().$last->getOutput());
            $result = json_decode($last->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            $outcomes = [...$outcomes, ...$result['outcomes']];
            $this->assertSame(10, count(array_filter($outcomes, static fn (int $status): bool => $status === 200)));
            $this->assertSame(429, $outcomes[array_key_last($outcomes)]);
            $this->assertTrue($result['lock_available_after']);
            $concurrentCounts = array_count_values(array_slice($outcomes, 0, 16));
            // Deterministic real database contention, independently of the concurrent scheduler's interleaving.
            config($configuration);
            CarbonImmutable::setTestNow();
            $principal = $fixture['buyer']['principal'];
            $key = 'production-memberships-customer:'.hash('sha256', json_encode([$principal->provenance, $principal->accountId, 'reads'], JSON_THROW_ON_ERROR));
            $held = Cache::store('native-member-admission')->getStore()->lock($key.':admission', 180);
            $this->assertTrue($held->get());
            try {
                $blocked = new Process([PHP_BINARY, base_path('tests/Support/membership-customer-limiter-worker.php'), $directory, 'blocked', '2'],
                    base_path(), ['APP_KEY' => config('app.key')], timeout: 90);
                $workers[] = $blocked;
                $blocked->run();
                $this->assertSame(0, $blocked->getExitCode(), $blocked->getErrorOutput().$blocked->getOutput());
                $blockedResult = json_decode($blocked->getOutput(), true, 8, JSON_THROW_ON_ERROR);
                $this->assertTrue($held->isOwnedByCurrentProcess(), 'Parent lease must still protect the full bounded child operation.');
                $this->assertSame([503, 503], $blockedResult['outcomes']);
                $this->assertFalse($blockedResult['lock_available_after']);
            } finally {
                $this->assertTrue($held->release());
            }
            fwrite(STDOUT, 'MEMBER_NATIVE_ADMISSION '.json_encode(['concurrent_outcomes' => $concurrentCounts,
                'total_admissions' => count(array_filter($outcomes, static fn (int $status): bool => $status === 200)),
                'held_lock_outcomes' => $blockedResult['outcomes'], 'lock_available_after_admissions' => $result['lock_available_after']], JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            (new Filesystem)->deleteDirectory($directory);
            CarbonImmutable::setTestNow();
        }
    }
}
