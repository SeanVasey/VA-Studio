<?php

namespace Tests\Feature\ProductionMembershipHttp;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;
use Throwable;

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
        $firstFailure = null;
        $cleanupErrors = [];
        $cleanupReceipts = [];
        $epochReceipt = ['classification' => 'UNCLASSIFIED'];
        $stage = 'worker-start';
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
            config($configuration);
            CarbonImmutable::setTestNow();
            $principal = $fixture['buyer']['principal'];
            $key = 'production-memberships-customer:'.hash('sha256', json_encode([$principal->provenance, $principal->accountId, 'reads'], JSON_THROW_ON_ERROR));
            $cache = Cache::store('native-member-admission');
            $stage = 'epoch-baseline';
            if ($cache->get($key) !== null || $cache->get($key.':timer') !== null) {
                throw new RuntimeException('N7_NATIVE_EPOCH_STOP_UNCLASSIFIED: nonempty baseline.');
            }
            $epochStart = hrtime(true);
            $epochWall = time();
            touch($directory.'/start');
            $stage = 'concurrent-and-fill';
            $outcomes = [];
            $observations = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput().$worker->getOutput());
                $result = json_decode($worker->getOutput(), true, 8, JSON_THROW_ON_ERROR);
                $outcomes = [...$outcomes, ...$result['outcomes']];
                $observations = [...$observations, ...$result['epoch']];
            }
            $epochReceipt = ['classification' => 'UNCLASSIFIED', 'start_mono' => $epochStart,
                'observations' => $observations, 'outcomes' => $outcomes];
            if (hrtime(true) - $epochStart >= 55_000_000_000) {
                throw new RuntimeException('N7_NATIVE_EPOCH_STOP_UNCLASSIFIED: concurrent phase consumed the window.');
            }
            $concurrentOutcomes = $outcomes; // Interpret cap ONLY after proving the complete live epoch.
            // Fill any slots conservatively refused by nonblocking contention; exhaustion must remain at ten total.
            $last = new Process([PHP_BINARY, base_path('tests/Support/membership-customer-limiter-worker.php'), $directory, 'last', '12'], base_path(), ['APP_KEY' => config('app.key')], timeout: 90);
            $workers[] = $last;
            $last->run();
            $this->assertSame(0, $last->getExitCode(), $last->getErrorOutput().$last->getOutput());
            $result = json_decode($last->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            $outcomes = [...$outcomes, ...$result['outcomes']];
            $observations = [...$observations, ...$result['epoch']];
            $stage = 'epoch-validity';
            $timer = $cache->get($key.':timer');
            $counter = $cache->get($key);
            $epochEnd = hrtime(true);
            $epochReceipt = ['classification' => 'UNCLASSIFIED', 'start_mono' => $epochStart, 'end_mono' => $epochEnd,
                'start_wall' => $epochWall, 'end_wall' => time(), 'final_timer' => $timer, 'final_counter' => $counter,
                'observations' => $observations, 'outcomes' => $outcomes];
            $this->requireSingleEpoch($epochReceipt);
            $epochReceipt['classification'] = 'ONE_LIVE_60S_EPOCH';
            $stage = 'cap-assertions';
            $this->assertLessThanOrEqual(10, count(array_filter($concurrentOutcomes, static fn (int $status): bool => $status === 200)));
            $this->assertSame([], array_values(array_diff($outcomes, [200, 429, 503])));
            $this->assertSame(10, $counter);
            $this->assertSame(10, count(array_filter($outcomes, static fn (int $status): bool => $status === 200)));
            $this->assertSame(429, $outcomes[array_key_last($outcomes)]);
            $this->assertTrue($result['lock_available_after']);
            $concurrentCounts = array_count_values(array_slice($outcomes, 0, 16));
            // Deterministic real database contention, independently of the concurrent scheduler's interleaving.
            $stage = 'separate-held-lock';
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
                try {
                    if (! $held->release()) {
                        throw new RuntimeException('Parent test lock release unavailable.');
                    }
                } catch (Throwable $error) {
                    $cleanupErrors[] = ['stage' => 'parent-lock-release', 'class' => $error::class];
                }
            }
            fwrite(STDOUT, 'MEMBER_NATIVE_ADMISSION '.json_encode(['concurrent_outcomes' => $concurrentCounts,
                'total_admissions' => count(array_filter($outcomes, static fn (int $status): bool => $status === 200)),
                'held_lock_outcomes' => $blockedResult['outcomes'], 'lock_available_after_admissions' => $result['lock_available_after']], JSON_THROW_ON_ERROR).PHP_EOL);
        } catch (Throwable $error) {
            $firstFailure = $error;
        } finally {
            // No one child's status/stop/receipt error may bypass later registered children.
            try {
                foreach ($workers as $id => $worker) {
                    $receipt = ['worker' => $id, 'terminal' => false, 'exit' => null, 'stop_exit' => null];
                    try {
                        if ($worker->isRunning()) {
                            $receipt['stop_exit'] = $worker->stop(1);
                        }
                    } catch (Throwable $error) {
                        $cleanupErrors[] = ['stage' => 'worker-stop', 'worker' => $id, 'class' => $error::class];
                    } finally {
                        try {
                            $running = $worker->isRunning();
                            $receipt['exit'] = $worker->getExitCode();
                            $receipt['terminal'] = ! $running && $receipt['exit'] !== null;
                            $receipt['running'] = $running;
                            $receipt['signaled'] = $worker->isSignaled();
                            $receipt['signal'] = $receipt['signaled'] ? $worker->getTermSignal() : null;
                        } catch (Throwable $error) {
                            $receipt['terminal'] = false;
                            $receipt['observation_error'] = $error::class;
                        } finally {
                            if (! $receipt['terminal']) {
                                $cleanupErrors[] = ['stage' => 'worker-terminal-unknown', 'worker' => $id];
                            }
                            $cleanupReceipts[] = $receipt;
                        }
                    }
                }
            } finally {
                try {
                    // Retain safe process facts BEFORE deleting only a verified-success rendezvous directory.
                    $this->writeNativeReceipt($directory, $stage, $firstFailure, $epochReceipt, $cleanupReceipts, $cleanupErrors, false);
                    if ($firstFailure === null && $cleanupErrors === []) {
                        if (! (new Filesystem)->deleteDirectory($directory)) {
                            throw new RuntimeException('Verified-process rendezvous cleanup failed.');
                        }
                    } // Failure/uncertainty retains private rendezvous/config evidence.
                } catch (Throwable $error) {
                    $cleanupErrors[] = ['stage' => 'diagnostic-or-directory-cleanup', 'class' => $error::class];
                } finally {
                    try {
                        CarbonImmutable::setTestNow();
                    } catch (Throwable $error) {
                        $cleanupErrors[] = ['stage' => 'clock-reset', 'class' => $error::class];
                    } finally {
                        try {
                            $this->writeNativeReceipt($directory, $stage, $firstFailure, $epochReceipt, $cleanupReceipts, $cleanupErrors, true);
                        } catch (Throwable $error) {
                            $cleanupErrors[] = ['stage' => 'final-receipt-unavailable', 'class' => $error::class];
                        }
                    }
                }
            }
        }
        if ($firstFailure !== null) {
            throw $firstFailure; // First behavioral failure cannot be masked by later cleanup errors.
        }
        $this->assertSame([], $cleanupErrors, 'Native process cleanup UNKNOWN; preserve private receipts; no lease return claim.');
    }

    private function writeNativeReceipt(string $directory, string $stage, ?Throwable $firstFailure, array $epoch,
        array $workers, array $errors, bool $final): void
    {
        $receipt = ['final' => $final, 'stage' => $stage, 'first_failure' => $firstFailure === null ? null : [
            'class' => $firstFailure::class, 'message_sha256' => hash('sha256', $firstFailure->getMessage())],
            'epoch' => $epoch, 'workers' => $workers, 'cleanup_errors' => $errors,
            'process_cleanup_verified' => $final && $errors === [], 'lease_returnable' => false,
            'native_server_cleanup' => 'UNVERIFIED: ROOT audit required'];
        $bytes = json_encode($receipt, JSON_THROW_ON_ERROR);
        $path = $directory.'.cleanup.json';
        $file = fopen($path, 'c+b');
        if ($file === false) {
            throw new RuntimeException('Private native receipt unavailable.');
        }
        try {
            if (! chmod($path, 0600) || ! ftruncate($file, 0) || ! rewind($file)
                || fwrite($file, $bytes) !== strlen($bytes) || ! fflush($file)) {
                throw new RuntimeException('Private native receipt incomplete.');
            }
        } finally {
            fclose($file);
        }
    }

    private function requireSingleEpoch(array $epoch): void
    {
        $timer = $epoch['final_timer'];
        $valid = is_int($timer) && is_int($epoch['final_counter'])
            && $epoch['end_mono'] - $epoch['start_mono'] < 55_000_000_000
            && $epoch['end_wall'] >= $epoch['start_wall'] && $epoch['end_wall'] < $timer
            && count($epoch['observations']) === 28 && count($epoch['outcomes']) === 28;
        foreach ($epoch['observations'] as $observation) {
            foreach (['before', 'after'] as $phase) {
                $sample = $observation[$phase];
                $value = $sample['timer'];
                $valid = $valid && ($value === null || (is_int($value) && $value === $timer && $sample['wall'] < $value && $value - $sample['wall'] <= 60))
                    && $sample['mono'] >= $epoch['start_mono'] && $sample['mono'] <= $epoch['end_mono']
                    && $sample['wall'] >= $epoch['start_wall'] && $sample['wall'] <= $epoch['end_wall'];
            }
            if (in_array($observation['status'], [200, 429], true)) {
                $valid = $valid && $observation['after']['timer'] === $timer;
            }
        }
        if (! $valid) {
            throw new RuntimeException('N7_NATIVE_EPOCH_STOP_UNCLASSIFIED: unavailable/changed/expired sixty-second epoch.');
        }
    }
}
