<?php

namespace Tests\Feature;

use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Delivery\DeliveryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TestDeliveryAssetFilesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); $this->fakePrivateMediaStorage();
        chmod(Storage::disk('local')->path(''), 0700);
    }

    private function fixture(): array
    {
        $bytes = str_repeat('Synthetic private master bytes ', 80_000);
        $entry = ['role' => 'master_wav', 'disk' => 'local',
            'storage_path' => 'media/revisions/11111111-1111-4111-8111-111111111111/master.wav',
            'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)];
        $root = rtrim(Storage::disk('local')->path(''), '/'); $directory = $root;
        foreach (explode('/', dirname($entry['storage_path'])) as $part) { $directory .= '/'.$part; mkdir($directory, 0700); }
        $path = $root.'/'.$entry['storage_path']; file_put_contents($path, $bytes); chmod($path, 0400);

        return [$entry, $path, $bytes];
    }

    private function failure(callable $operation, string $reason = 'asset_unavailable'): void
    {
        try { $operation(); $this->fail('Unsafe purchased asset passed verification.'); }
        catch (DeliveryException $error) { $this->assertSame($reason, $error->reason); }
    }

    public function test_exact_sealed_revision_is_streamed_without_changing_bytes_or_metadata(): void
    {
        [$entry, $path, $bytes] = $this->fixture(); $before = lstat($path);
        (new DeliveryAssetFiles)->verify($entry);
        $this->assertSame($bytes, file_get_contents($path));
        $fields = array_flip(['dev', 'ino', 'size', 'mode', 'nlink', 'mtime', 'ctime']);
        clearstatcache(true, $path);
        $this->assertSame(array_intersect_key($before, $fields), array_intersect_key(lstat($path), $fields));
    }

    public static function unsafeFiles(): array
    {
        return array_map(fn ($case) => [$case], ['missing', 'corrupt', 'size', 'hash', 'unsealed', 'group_readable', 'setuid', 'hardlink', 'symlink', 'directory', 'parent_symlink', 'parent_mode', 'path', 'role', 'disk', 'too_large']);
    }

    #[DataProvider('unsafeFiles')]
    public function test_missing_changed_or_unsafe_files_are_rejected_without_repair(string $case): void
    {
        [$entry, $path, $bytes] = $this->fixture();
        if ($case === 'missing') { unlink($path); }
        if ($case === 'corrupt') { chmod($path, 0600); file_put_contents($path, str_repeat('x', strlen($bytes))); chmod($path, 0400); }
        if ($case === 'size') { $entry['size_bytes']++; }
        if ($case === 'hash') { $entry['sha256'] = str_repeat('0', 64); }
        if ($case === 'unsealed') { chmod($path, 0600); }
        if ($case === 'group_readable') { chmod($path, 0440); }
        if ($case === 'setuid') { chmod($path, 04400); }
        if ($case === 'hardlink') { $this->assertTrue(link($path, $path.'.link')); }
        if ($case === 'symlink') { rename($path, $path.'.saved'); $this->assertTrue(symlink($path.'.saved', $path)); }
        if ($case === 'directory') { unlink($path); mkdir($path, 0700); }
        if ($case === 'parent_symlink') { rename(dirname($path), dirname($path).'.saved'); $this->assertTrue(symlink(dirname($path).'.saved', dirname($path))); }
        if ($case === 'parent_mode') { chmod(dirname($path), 0777); }
        if ($case === 'path') { $entry['storage_path'] = '../'.$entry['storage_path']; }
        if ($case === 'role') { $entry['role'] = 'preview_tagged'; }
        if ($case === 'disk') { $entry['disk'] = 'public'; }
        if ($case === 'too_large') { $entry['size_bytes'] = DeliveryAssetFiles::MAX_BYTES + 1; }
        $this->failure(fn () => (new DeliveryAssetFiles)->verify($entry));
        if ($case === 'missing') { $this->assertFileDoesNotExist($path); }
        if ($case === 'unsealed') { $this->assertSame(0600, fileperms($path) & 0777); }
    }

    public static function unsafeRoots(): array
    {
        return array_map(fn ($case) => [$case], ['serve', 'public', 'non_local', 'root_mode', 'served_same', 'served_parent', 'served_subtree', 'public_subtree', 'public_link', 'existing_public_link', 'reverse_link_chain', 'root_symlink', 'other_served_link', 'future_symlink_subtree', 'filesystem_root', 'dangling_public_root', 'dangling_public_ancestor']);
    }

    #[DataProvider('unsafeRoots')]
    public function test_public_or_unsafe_roots_in_either_direction_are_rejected(string $case): void
    {
        [$entry, $path] = $this->fixture(); $root = rtrim(Storage::disk('local')->path(''), '/');
        if ($case === 'serve') { config(['filesystems.disks.local.serve' => true]); }
        if ($case === 'public') { config(['filesystems.disks.local.visibility' => 'public']); }
        if ($case === 'non_local') { config(['filesystems.disks.local.driver' => 's3']); }
        if ($case === 'root_mode') { chmod($root, 0777); }
        $exposed = match ($case) {
            'served_same' => $root, 'served_parent' => dirname($root), 'served_subtree', 'public_subtree' => $root.'/media/future',
            'filesystem_root' => '/', default => null,
        };
        if ($exposed !== null) { config(['filesystems.disks.exposed' => ['driver' => 'local', 'root' => $exposed, 'serve' => true]]); }
        if ($case === 'public_subtree') { config(['filesystems.disks.exposed.serve' => false, 'filesystems.disks.exposed.visibility' => 'public']); }
        if ($case === 'public_link') { config(['filesystems.links' => [public_path('synthetic-media') => $root.'/media/future']]); }
        if ($case === 'existing_public_link') {
            $link = public_path('synthetic-asset-link-'.basename($root));
            $this->assertTrue(symlink($root.'/media', $link));
            $this->beforeApplicationDestroyed(fn () => unlink($link));
            config(['filesystems.links' => [$link => $root.'/media']]);
        }
        if ($case === 'reverse_link_chain') {
            config(['filesystems.links' => [storage_path('synthetic-link-chain/child') => $root.'/media',
                public_path('synthetic-link') => storage_path('synthetic-link-chain')]]);
        }
        if ($case === 'root_symlink') {
            $alias = $root.'-alias'; $this->assertTrue(symlink($root, $alias));
            $this->beforeApplicationDestroyed(fn () => unlink($alias));
            config(['filesystems.disks.local.root' => $alias]);
        }
        if ($case === 'other_served_link') {
            config(['filesystems.disks.exposed' => ['driver' => 'local', 'root' => storage_path('synthetic-served'), 'serve' => true],
                'filesystems.links' => [storage_path('synthetic-served/link') => $root.'/media']]);
        }
        if ($case === 'future_symlink_subtree') {
            $alias = $root.'-alias'; $this->assertTrue(symlink($root, $alias));
            $this->beforeApplicationDestroyed(fn () => unlink($alias));
            config(['filesystems.disks.exposed' => ['driver' => 'local', 'root' => $alias.'/future', 'visibility' => 'public']]);
        }
        if (in_array($case, ['dangling_public_root', 'dangling_public_ancestor'], true)) {
            $alias = $root.'-dangling-public';
            $this->assertTrue(symlink($root.'/media/future', $alias));
            $this->assertFalse(realpath($alias)); $this->assertTrue(is_link($alias));
            $this->beforeApplicationDestroyed(fn () => unlink($alias));
            config(['filesystems.disks.exposed' => ['driver' => 'local', 'visibility' => 'public',
                'root' => $case === 'dangling_public_root' ? $alias : $alias.'/uncreated-child']]);
        }
        $this->failure(fn () => (new DeliveryAssetFiles)->verify($entry));
        $this->assertFileExists($path); $this->assertDirectoryDoesNotExist($root.'/media/future');
    }

    public function test_missing_root_is_not_created_and_expired_budget_does_not_hash(): void
    {
        [$entry] = $this->fixture();
        $this->failure(fn () => (new DeliveryAssetFiles)->verify($entry, hrtime(true) - 1));
        $missing = Storage::disk('local')->path('never-created');
        config(['filesystems.disks.local.root' => $missing]);
        Storage::forgetDisk('local');
        $this->failure(fn () => (new DeliveryAssetFiles)->verify($entry));
        $this->assertDirectoryDoesNotExist($missing);
    }

    public function test_file_io_is_denied_inside_any_secondary_transaction(): void
    {
        [$entry] = $this->fixture();
        config(['database.connections.delivery_io' => config('database.connections.'.config('database.default'))]);
        $connection = DB::connection('delivery_io'); $connection->beginTransaction();
        try { $this->failure(fn () => (new DeliveryAssetFiles)->verify($entry), 'unavailable'); }
        finally { $connection->rollBack(); DB::purge('delivery_io'); }
    }

    public static function midstreamChanges(): array
    {
        return [['replace_file'], ['replace_directory'], ['hardlink'], ['change_mode'], ['truncate']];
    }

    #[DataProvider('midstreamChanges')]
    public function test_descriptor_and_path_identity_are_rechecked_after_streaming(string $change): void
    {
        [$entry, $path, $bytes] = $this->fixture();
        $files = new class($path, $bytes, $change) extends DeliveryAssetFiles {
            private bool $changed = false;
            public function __construct(private string $path, private string $bytes, private string $change) {}
            protected function readChunk($input, int $length): string|false
            {
                $chunk = parent::readChunk($input, $length);
                if (! $this->changed) {
                    $this->changed = true;
                    if ($this->change === 'replace_file') { rename($this->path, $this->path.'.old'); file_put_contents($this->path, $this->bytes); chmod($this->path, 0400); }
                    if ($this->change === 'replace_directory') { rename(dirname($this->path), dirname($this->path).'.old'); mkdir(dirname($this->path), 0700); file_put_contents($this->path, $this->bytes); chmod($this->path, 0400); }
                    if ($this->change === 'hardlink') { link($this->path, $this->path.'.link'); }
                    if ($this->change === 'change_mode') { chmod($this->path, 0600); }
                    if ($this->change === 'truncate') { chmod($this->path, 0600); file_put_contents($this->path, 'truncated'); chmod($this->path, 0400); }
                }
                return $chunk;
            }
        };
        $this->failure(fn () => $files->verify($entry));
    }
}
