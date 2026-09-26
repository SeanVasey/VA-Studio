<?php

namespace Tests\Feature;

use App\Domain\Contracts\ContractFiles;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\RenderedContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Physical storage tests use envelope bytes, not claims of valid PDF rendering. Renderer tests own PDF validity. */
class TestContractStorageTest extends TestCase
{
    private const REQUEST = '11111111-1111-4111-8111-111111111111';
    private const CLAIM = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp(); $this->fakePrivateMediaStorage();
    }

    private function document(string $marker = 'Synthetic storage-only envelope'): RenderedContract
    {
        $bytes = "%PDF-1.7\n{$marker}\n%%EOF\n";
        return new RenderedContract($bytes, hash('sha256', $bytes), strlen($bytes), 1,
            hash('sha256', $marker), hash('sha256', 'synthetic-storage-profile'));
    }

    private function failure(string $reason, callable $operation): void
    {
        try { $operation(); $this->fail('Unsafe contract file operation succeeded.'); }
        catch (ContractIssuanceException $error) { $this->assertSame($reason, $error->reason); }
    }

    public function test_original_is_exclusive_private_sealed_and_verified_from_retained_bytes(): void
    {
        $files = new ContractFiles; $pdf = $this->document();
        $record = $files->store(self::REQUEST, self::CLAIM, $pdf);
        $this->assertSame('contracts/test/'.self::REQUEST.'/'.self::CLAIM.'/original.pdf', $record['storage_path']);
        $this->assertSame($pdf->pdfBytes, $files->verify($record));
        $path = Storage::disk('local')->path($record['storage_path']);
        $this->assertSame(0400, fileperms($path) & 0777);
        $this->assertSame(0700, fileperms(dirname($path)) & 0777);
        $this->failure('storage_failed', fn () => $files->store(self::REQUEST, self::CLAIM, $this->document('Changed original must never replace bytes')));
        $this->assertSame($pdf->pdfBytes, $files->verify($record));
        $other = $files->store(self::REQUEST, (string) Str::uuid(), $pdf);
        $this->assertNotSame($record['storage_path'], $other['storage_path']);
        $this->assertSame($pdf->pdfBytes, $files->verify($other));
    }

    public static function brokenOriginals(): array
    {
        return array_map(fn ($case) => [$case], ['missing', 'bytes', 'hash', 'size', 'permissions', 'hardlink', 'symlink', 'path', 'disk']);
    }

    #[DataProvider('brokenOriginals')]
    public function test_existing_missing_changed_or_unsafe_original_never_rerenders(string $case): void
    {
        $files = new ContractFiles; $record = $files->store(self::REQUEST, self::CLAIM, $this->document());
        $path = Storage::disk('local')->path($record['storage_path']);
        if ($case === 'missing') { unlink($path); }
        if ($case === 'bytes') { chmod($path, 0600); file_put_contents($path, $this->document('A different retained byte sequence')->pdfBytes); chmod($path, 0400); }
        if ($case === 'hash') { $record['pdf_hash'] = str_repeat('0', 64); }
        if ($case === 'size') { $record['size_bytes']++; }
        if ($case === 'permissions') { chmod($path, 0644); }
        if ($case === 'hardlink') { $this->assertTrue(link($path, dirname($path).'/duplicate-link.pdf')); }
        if ($case === 'symlink') { rename($path, $path.'.saved'); $this->assertTrue(symlink($path.'.saved', $path)); }
        if ($case === 'path') { $record['storage_path'] = '../'.$record['storage_path']; }
        if ($case === 'disk') { $record['disk'] = 'public'; }
        $this->failure('original_unavailable', fn () => $files->verify($record));
        if ($case === 'missing') { $this->assertFileDoesNotExist($path); }
    }

    public static function unsafeDisks(): array
    {
        return [['serve', true], ['visibility', 'public'], ['driver', 's3'], ['prefix', 'contracts']];
    }

    #[DataProvider('unsafeDisks')]
    public function test_new_files_reject_served_public_or_non_local_disk(string $field, mixed $value): void
    {
        config(['filesystems.disks.local.'.$field => $value]);
        $this->failure('storage_failed', fn () => (new ContractFiles)->store(self::REQUEST, self::CLAIM, $this->document()));
        $this->assertFalse(Storage::disk('local')->exists('contracts'));
    }

    public function test_private_named_disk_cannot_hide_a_public_root_or_symlink_component(): void
    {
        $root = Storage::disk('local')->path('');
        // Mark the real root as another public disk: adapter naming does not make it private.
        config(['filesystems.disks.exposed' => ['driver' => 'local', 'root' => $root, 'visibility' => 'public']]);
        $this->failure('storage_failed', fn () => (new ContractFiles)->store(self::REQUEST, self::CLAIM, $this->document()));
        config(['filesystems.disks.exposed' => null]);
        mkdir($root.'/elsewhere', 0700);
        $this->assertTrue(symlink($root.'/elsewhere', $root.'/contracts'));
        $this->failure('storage_failed', fn () => (new ContractFiles)->store(self::REQUEST, self::CLAIM, $this->document()));
        $this->assertSame([], array_values(array_diff(scandir($root.'/elsewhere'), ['.', '..'])));
    }

    public static function exposedSubtrees(): array
    {
        return [['served_disk'], ['public_disk'], ['public_link']];
    }

    #[DataProvider('exposedSubtrees')]
    public function test_a_public_subtree_of_the_private_root_blocks_new_and_retained_originals(string $exposure): void
    {
        $root = rtrim(Storage::disk('local')->path(''), '/');
        $setting = $exposure === 'public_link' ? 'filesystems.links' : 'filesystems.disks.exposed';
        $original = config($setting);
        $exposed = $exposure === 'public_link'
            ? [public_path('synthetic-contracts') => $root.'/contracts']
            : ['driver' => 'local', 'root' => $root.'/contracts',
                'serve' => $exposure === 'served_disk', 'visibility' => $exposure === 'public_disk' ? 'public' : 'private'];
        $files = new ContractFiles;

        // The public target need not exist yet to expose a future original.
        config([$setting => $exposed]);
        $this->failure('storage_failed', fn () => $files->store(self::REQUEST, self::CLAIM, $this->document()));
        $this->failure('storage_failed', fn () => $files->createRendererWorkspace());
        $this->assertDirectoryDoesNotExist($root.'/contracts');

        config([$setting => $original]);
        $record = $files->store(self::REQUEST, self::CLAIM, $this->document());
        config([$setting => $exposed]);
        $this->failure('original_unavailable', fn () => $files->verify($record));
        $this->failure('storage_failed', fn () => $files->store(self::REQUEST, (string) Str::uuid(), $this->document()));
        $this->assertSame($this->document()->pdfBytes, file_get_contents($root.'/'.$record['storage_path']));

        config([$setting => $original]);
        $this->assertSame($this->document()->pdfBytes, $files->verify($record));
    }

    public function test_renderer_cache_cleanup_preserves_other_claims_originals_and_symlink_targets(): void
    {
        $files = new ContractFiles;
        $record = $files->store(self::REQUEST, self::CLAIM, $this->document());
        $first = $files->createRendererWorkspace();
        $second = $files->createRendererWorkspace();
        $this->assertNotSame($first->path, $second->path);
        file_put_contents($first->path.'/partial', 'Synthetic partial cache');
        file_put_contents($second->path.'/retained', 'Another child cache');
        $this->assertTrue(symlink($second->path, $first->path.'/foreign-child'));
        $first->close();
        $this->assertDirectoryDoesNotExist($first->path);
        $this->assertSame('Another child cache', file_get_contents($second->path.'/retained'));
        $this->assertSame($this->document()->pdfBytes, $files->verify($record));
        $second->close();
        $this->assertDirectoryDoesNotExist($second->path);
    }

    public function test_renderer_cache_cleanup_rejects_a_replaced_directory(): void
    {
        $files = new ContractFiles;
        $first = $files->createRendererWorkspace();
        $other = $files->createRendererWorkspace();
        file_put_contents($other->path.'/retained', 'Do not traverse this replacement');
        $this->assertTrue(rename($first->path, $first->path.'.saved'));
        $this->assertTrue(symlink($other->path, $first->path));
        $this->failure('storage_failed', fn () => $first->close());
        $this->assertSame('Do not traverse this replacement', file_get_contents($other->path.'/retained'));
        $this->assertTrue(unlink($first->path));
        $this->assertTrue(rename($first->path.'.saved', $first->path));
        $first->close(); $other->close();
    }

    public function test_missing_original_root_is_not_recreated_by_read_only_verification(): void
    {
        $files = new ContractFiles;
        $record = $files->store(self::REQUEST, self::CLAIM, $this->document());
        $root = rtrim(Storage::disk('local')->path(''), '/');
        $this->assertSame($root, rtrim(config('filesystems.disks.local.root'), '/'));
        Storage::forgetDisk('local');
        $this->assertTrue((new \Illuminate\Filesystem\Filesystem)->deleteDirectory($root));

        $this->failure('original_unavailable', fn () => $files->verify($record));
        $this->assertDirectoryDoesNotExist($root);
    }

    public static function reachablePublicPaths(): array
    {
        return [['reverse_link_chain'], ['other_served_link'], ['future_symlink_subtree'], ['existing_public_link'], ['filesystem_root']];
    }

    #[DataProvider('reachablePublicPaths')]
    public function test_public_link_chains_and_future_targets_cannot_expose_originals_or_renderer_cache(string $case): void
    {
        $files = new ContractFiles;
        $record = $files->store(self::REQUEST, self::CLAIM, $this->document());
        $root = rtrim(config('filesystems.disks.local.root'), '/');
        if ($case === 'reverse_link_chain') {
            // A duplicate initial root must not conceal discovery of the chain's first target.
            config(['filesystems.disks.exposed' => ['driver' => 'local', 'root' => public_path(), 'serve' => true],
                'filesystems.links' => [storage_path('synthetic-contract-chain/child') => $root.'/contracts',
                    public_path('synthetic-contract-chain') => storage_path('synthetic-contract-chain')]]);
        }
        if ($case === 'other_served_link') {
            config(['filesystems.disks.exposed' => ['driver' => 'local', 'root' => storage_path('synthetic-served-contracts'), 'serve' => true],
                'filesystems.links' => [storage_path('synthetic-served-contracts/link') => $root.'/contracts']]);
        }
        if ($case === 'future_symlink_subtree') {
            $alias = $root.'-alias';
            $this->assertTrue(symlink($root, $alias));
            $this->beforeApplicationDestroyed(fn () => unlink($alias));
            config(['filesystems.disks.exposed' => ['driver' => 'local', 'root' => $alias.'/future', 'visibility' => 'public']]);
        }
        if ($case === 'existing_public_link') {
            $link = public_path('synthetic-contract-link-'.basename($root));
            $this->assertTrue(symlink($root.'/contracts', $link));
            $this->beforeApplicationDestroyed(fn () => unlink($link));
            config(['filesystems.links' => [$link => $root.'/contracts']]);
        }
        if ($case === 'filesystem_root') {
            config(['filesystems.disks.exposed' => ['driver' => 'local', 'root' => '/', 'serve' => true]]);
        }

        $this->failure('original_unavailable', fn () => $files->verify($record));
        $this->failure('storage_failed', fn () => $files->store(self::REQUEST, (string) Str::uuid(), $this->document()));
        $this->failure('storage_failed', fn () => $files->createRendererWorkspace());
        $this->assertSame($this->document()->pdfBytes, file_get_contents($root.'/'.$record['storage_path']));
        $this->assertDirectoryDoesNotExist($root.'/future');
        $this->assertDirectoryDoesNotExist($root.'/contracts/render-cache');
    }

    public function test_failed_write_permissions_and_preexisting_path_do_not_change_foreign_bytes(): void
    {
        $root = Storage::disk('local')->path(''); mkdir($root.'/contracts', 0500);
        $this->failure('storage_failed', fn () => (new ContractFiles)->store(self::REQUEST, self::CLAIM, $this->document()));
        chmod($root.'/contracts', 0700);
        $directory = $root.'/contracts/test/'.self::REQUEST.'/'.self::CLAIM;
        mkdir($directory, 0700, true);
        file_put_contents($root.'/foreign.pdf', 'DO NOT CHANGE');
        $this->assertTrue(symlink($root.'/foreign.pdf', $directory.'/original.pdf'));
        $this->failure('storage_failed', fn () => (new ContractFiles)->store(self::REQUEST, self::CLAIM, $this->document()));
        $this->assertSame('DO NOT CHANGE', file_get_contents($root.'/foreign.pdf'));
        $this->assertTrue(is_link($directory.'/original.pdf'));
    }

    public function test_storage_refuses_io_inside_an_open_secondary_database_transaction(): void
    {
        $files = new ContractFiles; $record = $files->store(self::REQUEST, self::CLAIM, $this->document());
        config(['database.connections.contract_io_guard' => config('database.connections.'.config('database.default'))]);
        $connection = DB::connection('contract_io_guard'); $connection->beginTransaction();
        try {
            $this->failure('unavailable', fn () => $files->verify($record));
            $this->failure('unavailable', fn () => $files->store(self::REQUEST, (string) Str::uuid(), $this->document()));
        } finally { $connection->rollBack(); DB::purge('contract_io_guard'); }
        $this->assertSame($this->document()->pdfBytes, $files->verify($record));
    }

    public function test_partial_physical_write_is_not_published_or_overwritten_on_retry(): void
    {
        if (! function_exists('posix_setrlimit') || ! function_exists('pcntl_signal') || ! defined('SIGXFSZ')) {
            $this->markTestSkipped('A forced physical short write requires POSIX resource limits and signals.');
        }
        $root = rtrim(Storage::disk('local')->path(''), '/'); $barrier = $root.'/limited'; mkdir($barrier, 0700);
        touch($barrier.'/release');
        $payload = ['root' => $root, 'barrier' => $barrier, 'worker' => 0, 'action' => 'short_write',
            'request' => self::REQUEST, 'claim' => self::CLAIM, 'bytes' => base64_encode($this->document()->pdfBytes)];
        $process = new Process([PHP_BINARY, base_path('tests/Support/contract-storage-race-worker.php')], base_path(),
            ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false'], json_encode($payload, JSON_THROW_ON_ERROR), 20);
        $process->run(); $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('storage_failed', $result['outcome']); $this->assertArrayNotHasKey('record', $result);
        $path = $root.'/contracts/test/'.self::REQUEST.'/'.self::CLAIM.'/original.pdf';
        $this->assertFileExists($path); $partial = file_get_contents($path);
        $this->assertSame(16, strlen($partial));
        $this->failure('storage_failed', fn () => (new ContractFiles)->store(self::REQUEST, self::CLAIM, $this->document()));
        $this->assertSame($partial, file_get_contents($path));
    }

    public function test_two_independent_processes_cannot_overwrite_the_same_claim_original(): void
    {
        $root = rtrim(Storage::disk('local')->path(''), '/'); $barrier = $root.'/race'; mkdir($barrier, 0700);
        $processes = [];
        try {
            foreach ([0, 1] as $worker) {
                $input = ['root' => $root, 'barrier' => $barrier, 'worker' => $worker,
                    'request' => self::REQUEST, 'claim' => self::CLAIM, 'bytes' => base64_encode($this->document()->pdfBytes)];
                $process = new Process([PHP_BINARY, base_path('tests/Support/contract-storage-race-worker.php')], base_path(),
                    ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false'], json_encode($input, JSON_THROW_ON_ERROR), 20);
                $process->start(); $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (! is_file($barrier.'/ready-0') || ! is_file($barrier.'/ready-1')) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) { $this->fail('Storage race process exited before its barrier.'); }
                    $process->checkTimeout();
                }
                if (microtime(true) >= $deadline) { $this->fail('Storage race barrier timed out.'); }
                usleep(10000); clearstatcache();
            }
            touch($barrier.'/release'); $results = [];
            foreach ($processes as $process) {
                $process->wait(); $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            }
            $outcomes = array_column($results, 'outcome'); sort($outcomes);
            $this->assertSame(['storage_failed', 'stored'], $outcomes);
            $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            $record = collect($results)->firstWhere('outcome', 'stored')['record'];
            $this->assertSame($this->document()->pdfBytes, (new ContractFiles)->verify($record));
        } finally {
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(0); } }
        }
    }
}
