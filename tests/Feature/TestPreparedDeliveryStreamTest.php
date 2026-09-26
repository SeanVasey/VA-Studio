<?php

namespace Tests\Feature;

use App\Domain\Contracts\ContractFiles;
use App\Domain\Contracts\RenderedContract;
use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\PreparedDeliveryStream;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TestPreparedDeliveryStreamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); $this->fakePrivateMediaStorage(); chmod(Storage::disk('local')->path(''), 0700);
    }

    private function target(): array
    {
        $bytes = str_repeat('Synthetic stream fixture ', 100000);
        $target = ['id' => 1, 'track_id' => 1, 'kind' => 'master_wav', 'role' => 'master_wav', 'disk' => 'local',
            'scan_scope' => 'test-only', 'storage_path' => 'media/revisions/11111111-1111-4111-8111-111111111111/master.wav',
            'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)];
        $directory = Storage::disk('local')->path('');
        foreach (explode('/', dirname($target['storage_path'])) as $part) { $directory .= '/'.$part; mkdir($directory, 0700); }
        $path = Storage::disk('local')->path($target['storage_path']); file_put_contents($path, $bytes); chmod($path, 0400);
        return [$target, $path, $bytes];
    }

    private function failure(callable $operation, string $reason = 'target_unavailable'): void
    {
        try { $operation(); $this->fail('An unsafe private stream was prepared.'); }
        catch (DeliveryException $error) { $this->assertSame($reason, $error->reason); }
    }

    private function snapshots(): array
    {
        return glob(Storage::disk('local')->path('delivery/spool/*.snapshot')) ?: [];
    }

    public function test_verified_snapshot_is_read_only_unlinked_and_unchanged_after_source_replacement(): void
    {
        [$target, $path, $bytes] = $this->target(); $prepared = app(PrepareTestDeliveryStream::class)->handle($target);
        $stream = $prepared->stream(); $stat = fstat($stream);
        $this->assertSame($target['sha256'], $prepared->sha256); $this->assertSame(strlen($bytes), $prepared->sizeBytes);
        $this->assertSame(0, $stat['nlink']); $this->assertSame(0400, $stat['mode'] & 07777);
        $this->assertSame('rb', stream_get_meta_data($stream)['mode']); $this->assertSame([], $this->snapshots());
        rename($path, $path.'.retained'); file_put_contents($path, 'replacement must never be streamed'); chmod($path, 0400);
        $received = ''; $maximum = 0;
        $prepared->writeTo(function (string $chunk) use (&$received, &$maximum) { $received .= $chunk; $maximum = max($maximum, strlen($chunk)); });
        $this->assertSame($bytes, $received); $this->assertLessThanOrEqual(1048576, $maximum);
        $this->assertFalse(is_resource($stream)); $this->failure(fn () => $prepared->stream());
    }

    public function test_original_contract_is_copied_from_the_verified_original_without_rerendering(): void
    {
        $bytes = "%PDF-1.7\nSynthetic original contract snapshot\n%%EOF\n";
        $record = app(ContractFiles::class)->store('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222',
            new RenderedContract($bytes, hash('sha256', $bytes), strlen($bytes), 1, hash('sha256', 'text'), hash('sha256', 'profile')));
        $target = ['kind' => 'contract', 'sha256' => $record['pdf_hash']] + $record;
        $prepared = app(PrepareTestDeliveryStream::class)->handle($target);
        unlink(Storage::disk('local')->path($record['storage_path']));
        try { $this->assertSame($bytes, stream_get_contents($prepared->stream())); }
        finally { $prepared->close(); }
        $this->failure(fn () => app(PrepareTestDeliveryStream::class)->handle($target));
        $this->assertSame([], $this->snapshots());
    }

    public function test_three_live_descriptors_hold_the_global_spool_budget_until_closed(): void
    {
        [$target] = $this->target(); $streams = [];
        try {
            for ($i = 0; $i < PrepareTestDeliveryStream::SLOTS; $i++) { $streams[] = app(PrepareTestDeliveryStream::class)->handle($target); }
            $this->failure(fn () => app(PrepareTestDeliveryStream::class)->handle($target));
            $this->assertSame([], $this->snapshots());
            $streams[1]->close();
            $replacement = app(PrepareTestDeliveryStream::class)->handle($target); $replacement->close();
            $this->assertSame($target['size_bytes'], fstat($streams[0]->stream())['size']);
        } finally { foreach ($streams as $stream) { $stream->close(); } }
    }

    public function test_consumer_failure_and_destructor_release_private_handles_and_budget(): void
    {
        [$target] = $this->target(); $adapter = app(PrepareTestDeliveryStream::class);
        $prepared = $adapter->handle($target); $handle = $prepared->stream();
        try { $prepared->writeTo(fn () => throw new \RuntimeException('Synthetic client disconnect')); $this->fail('Consumer failure was ignored.'); }
        catch (\RuntimeException $error) { $this->assertSame('Synthetic client disconnect', $error->getMessage()); }
        $this->assertFalse(is_resource($handle));
        $prepared = $adapter->handle($target); $handle = $prepared->stream(); unset($prepared);
        $this->assertFalse(is_resource($handle));
        $streams = [];
        try { for ($i = 0; $i < 3; $i++) { $streams[] = $adapter->handle($target); } }
        finally { foreach ($streams as $stream) { $stream->close(); } }
        $this->assertSame([], $this->snapshots());
    }

    public static function invalidTargets(): array
    {
        return [['kind'], ['role'], ['scope'], ['hash'], ['bytes'], ['media_cap'], ['pdf_cap'], ['path'], ['disk']];
    }

    #[DataProvider('invalidTargets')]
    public function test_invalid_target_or_bytes_never_leave_a_prepared_snapshot(string $case): void
    {
        [$target] = $this->target();
        if ($case === 'kind') { $target['kind'] = 'preview_tagged'; }
        if ($case === 'role') { $target['role'] = 'stems_zip'; }
        if ($case === 'scope') { unset($target['scan_scope']); }
        if ($case === 'hash') { $target['sha256'] = str_repeat('0', 64); }
        if ($case === 'bytes') { $target['size_bytes']++; }
        if ($case === 'media_cap') { $target['size_bytes'] = DeliveryAssetFiles::MAX_BYTES + 1; }
        if ($case === 'pdf_cap') { $target['kind'] = 'contract'; $target['size_bytes'] = ContractFiles::MAX_BYTES + 1; }
        if ($case === 'path') { $target['storage_path'] = '../'.$target['storage_path']; }
        if ($case === 'disk') { $target['disk'] = 'public'; }
        $this->failure(fn () => app(PrepareTestDeliveryStream::class)->handle($target));
        $this->assertSame([], $this->snapshots());
    }

    public static function injectedFailures(): array
    {
        return [['space'], ['copy'], ['deadline'], ['flush'], ['readback'], ['unlink']];
    }

    #[DataProvider('injectedFailures')]
    public function test_partial_spool_failures_close_unlink_and_release_the_owned_slot(string $failure): void
    {
        [$target] = $this->target();
        $adapter = new class($failure) extends PrepareTestDeliveryStream {
            public function __construct(private string $failure) {}
            protected function freeBytes(string $directory): float|false { return $this->failure === 'space' ? 0.0 : parent::freeBytes($directory); }
            protected function copyTarget(array $target, $destination, int $deadline): void
            {
                if ($this->failure === 'copy') { fwrite($destination, 'partial'); throw new \RuntimeException('Synthetic write failure'); }
                parent::copyTarget($target, $destination, $this->failure === 'deadline' ? hrtime(true) - 1 : $deadline);
            }
            protected function flush($handle): bool
            {
                if ($this->failure === 'flush') { return false; }
                if ($this->failure === 'readback') { fseek($handle, 0); fwrite($handle, 'CORRUPT'); }
                return parent::flush($handle);
            }
            protected function unlinkSpool(string $path): bool { return $this->failure === 'unlink' ? false : parent::unlinkSpool($path); }
        };
        $this->failure(fn () => $adapter->handle($target)); $this->assertSame([], $this->snapshots());
        $streams = [];
        try { for ($i = 0; $i < 3; $i++) { $streams[] = app(PrepareTestDeliveryStream::class)->handle($target); } }
        finally { foreach ($streams as $stream) { $stream->close(); } }
    }

    public function test_source_replacement_during_hashing_discards_the_incomplete_spool(): void
    {
        [$target, $path, $bytes] = $this->target();
        $this->app->instance(DeliveryAssetFiles::class, new class($path, $bytes) extends DeliveryAssetFiles {
            private bool $changed = false;
            public function __construct(private string $path, private string $bytes) {}
            protected function readChunk($input, int $length): string|false
            {
                $chunk = parent::readChunk($input, $length);
                if (! $this->changed) {
                    $this->changed = true; rename($this->path, $this->path.'.old'); file_put_contents($this->path, $this->bytes); chmod($this->path, 0400);
                }
                return $chunk;
            }
        });
        $this->failure(fn () => app(PrepareTestDeliveryStream::class)->handle($target)); $this->assertSame([], $this->snapshots());
    }

    public static function unsafeSpools(): array
    {
        return [['directory_mode'], ['directory_link'], ['lease_link'], ['lease_hardlink'], ['lease_mode'], ['public_subtree']];
    }

    #[DataProvider('unsafeSpools')]
    public function test_unsafe_spool_or_lease_paths_are_rejected_without_changing_foreign_bytes(string $case): void
    {
        [$target] = $this->target(); $root = rtrim(Storage::disk('local')->path(''), '/');
        $prepared = app(PrepareTestDeliveryStream::class)->handle($target); $prepared->close();
        $spool = $root.'/delivery/spool'; $lease = $spool.'/slot-0.lock';
        if ($case === 'directory_mode') { chmod($spool, 0777); }
        if ($case === 'directory_link') { rename($spool, $spool.'.saved'); symlink($spool.'.saved', $spool); }
        if ($case === 'lease_link') { rename($lease, $lease.'.saved'); symlink($lease.'.saved', $lease); }
        if ($case === 'lease_hardlink') { link($lease, $lease.'.linked'); }
        if ($case === 'lease_mode') { chmod($lease, 0600); }
        if ($case === 'public_subtree') { config(['filesystems.links' => [public_path('synthetic-spool') => $spool]]); }
        $this->failure(fn () => app(PrepareTestDeliveryStream::class)->handle($target));
        $this->assertSame('', file_get_contents($lease));
    }

    public function test_existing_crash_residue_is_never_reused_or_deleted_and_all_slots_can_fail_closed(): void
    {
        [$target] = $this->target(); $prepared = app(PrepareTestDeliveryStream::class)->handle($target); $prepared->close();
        $spool = Storage::disk('local')->path('delivery/spool');
        for ($slot = 0; $slot < 3; $slot++) { file_put_contents($spool.'/slot-'.$slot.'.snapshot', 'PRE-EXISTING '.$slot); }
        $this->failure(fn () => app(PrepareTestDeliveryStream::class)->handle($target));
        for ($slot = 0; $slot < 3; $slot++) { $this->assertSame('PRE-EXISTING '.$slot, file_get_contents($spool.'/slot-'.$slot.'.snapshot')); }
    }

    public static function forbiddenEnvironments(): array { return [['production', 'unavailable'], ['local', 'target_unavailable']]; }

    #[DataProvider('forbiddenEnvironments')]
    public function test_production_and_synthetic_scans_outside_testing_are_denied_before_spool_creation(string $environment, string $reason): void
    {
        [$target] = $this->target(); $original = $this->app['env']; $this->app['env'] = $environment;
        try { $this->failure(fn () => app(PrepareTestDeliveryStream::class)->handle($target), $reason); }
        finally { $this->app['env'] = $original; }
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path('delivery'));
    }

    public function test_secondary_transaction_denies_preparation_and_stream_access_before_any_spool_io(): void
    {
        [$target] = $this->target(); $prepared = app(PrepareTestDeliveryStream::class)->handle($target);
        config(['database.connections.stream_guard' => config('database.connections.'.config('database.default'))]);
        $connection = DB::connection('stream_guard'); $connection->beginTransaction();
        try {
            $this->failure(fn () => app(PrepareTestDeliveryStream::class)->handle($target), 'unavailable');
            $this->failure(fn () => $prepared->stream(), 'unavailable');
        } finally { $connection->rollBack(); DB::purge('stream_guard'); $prepared->close(); }
        $this->assertSame([], $this->snapshots());
    }

    private function worker(array $payload): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Support/delivery-stream-worker.php')], base_path(),
            ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false'], json_encode($payload, JSON_THROW_ON_ERROR), 25);
    }

    public function test_independent_processes_hold_exactly_three_snapshot_slots_until_stream_close(): void
    {
        [$target] = $this->target(); $root = rtrim(Storage::disk('local')->path(''), '/');
        // Prepare the stable lease files before racing their independent kernel locks.
        $initial = [];
        for ($slot = 0; $slot < 3; $slot++) { $initial[] = app(PrepareTestDeliveryStream::class)->handle($target); }
        foreach ($initial as $item) { $item->close(); }
        $barrier = $root.'/stream-barrier'; mkdir($barrier, 0700); $processes = [];
        try {
            for ($worker = 0; $worker < 3; $worker++) {
                $process = $this->worker(compact('root', 'target', 'barrier', 'worker') + ['action' => 'hold']);
                $process->start(); $processes[] = $process;
            }
            $deadline = hrtime(true) + 15000000000;
            while (count(glob($barrier.'/ready-*')) !== 3) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) { $this->fail('A spool holder exited before its barrier: '.$process->getErrorOutput()); }
                    $process->checkTimeout();
                }
                if (hrtime(true) > $deadline) { $this->fail('Independent spool holders did not reach their barrier.'); }
                usleep(10000); clearstatcache();
            }
            $blocked = $this->worker(compact('root', 'target') + ['action' => 'once']); $blocked->run();
            $this->assertSame(0, $blocked->getExitCode(), $blocked->getErrorOutput());
            $this->assertSame('target_unavailable', json_decode($blocked->getOutput(), true, 8, JSON_THROW_ON_ERROR)['outcome']);
            touch($barrier.'/release'); $pids = [];
            foreach ($processes as $process) {
                $process->wait(); $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR); $pids[] = $result['pid'];
                $this->assertSame('prepared', $result['outcome']); $this->assertTrue($result['matches']);
                $this->assertSame($target['size_bytes'], $result['bytes']);
            }
            $this->assertCount(4, array_unique([...$pids, getmypid()]));
            $next = app(PrepareTestDeliveryStream::class)->handle($target); $next->close();
            $this->assertSame([], $this->snapshots());
        } finally { foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(0); } } }
    }

    public function test_a_real_interrupted_writer_leaves_one_bounded_residue_per_slot_without_replacing_it(): void
    {
        [$target] = $this->target(); $root = rtrim(Storage::disk('local')->path(''), '/');
        for ($slot = 0; $slot < 3; $slot++) {
            $process = $this->worker(compact('root', 'target') + ['action' => 'crash_copy']); $process->run();
            $this->assertSame(73, $process->getExitCode(), $process->getErrorOutput());
            $this->assertCount($slot + 1, $this->snapshots());
        }
        $this->failure(fn () => app(PrepareTestDeliveryStream::class)->handle($target));
        foreach ($this->snapshots() as $path) { $this->assertSame('CRASH RESIDUE', file_get_contents($path)); }
    }

    public function test_disabling_fsync_fails_closed_and_discards_the_private_snapshot(): void
    {
        [$target] = $this->target(); $root = rtrim(Storage::disk('local')->path(''), '/');
        $before = app(PrepareTestDeliveryStream::class)->handle($target); $before->close();
        $process = new Process([PHP_BINARY, '-d', 'disable_functions=fsync', base_path('tests/Support/delivery-stream-worker.php')], base_path(),
            ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false'], json_encode(compact('root', 'target') + ['action' => 'once'], JSON_THROW_ON_ERROR), 25);
        $process->run(); $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('target_unavailable', json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR)['outcome']);
        $this->assertSame([], $this->snapshots());
        $after = app(PrepareTestDeliveryStream::class)->handle($target); $after->close();
    }

    public function test_a_real_short_physical_write_fails_without_consuming_a_slot_or_retaining_partial_bytes(): void
    {
        if (! function_exists('posix_setrlimit') || ! function_exists('pcntl_signal') || ! defined('SIGXFSZ')) {
            $this->markTestSkipped('Physical short-write injection requires POSIX resource limits and signals.');
        }
        [$target] = $this->target(); $root = rtrim(Storage::disk('local')->path(''), '/');
        $process = $this->worker(compact('root', 'target') + ['action' => 'short_write']); $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('target_unavailable', json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR)['outcome']);
        $this->assertSame([], $this->snapshots());
        $prepared = app(PrepareTestDeliveryStream::class)->handle($target); $prepared->close();
    }

    public function test_one_gibibyte_snapshot_and_output_complete_under_a_128_mib_process_limit(): void
    {
        $root = rtrim(Storage::disk('local')->path(''), '/');
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('tests/Support/delivery-stream-worker.php')], base_path(),
            ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false'], json_encode(['root' => $root, 'action' => 'large'], JSON_THROW_ON_ERROR), 90);
        $process->run(); $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame(DeliveryAssetFiles::MAX_BYTES, $result['bytes']); $this->assertTrue($result['matches']);
        $this->assertLessThan(16777216, $result['peak_delta']); $this->assertSame(0, $result['named_snapshots']);
    }
}
