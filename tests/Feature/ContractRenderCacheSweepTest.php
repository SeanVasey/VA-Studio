<?php

namespace Tests\Feature;

use App\Console\Commands\SweepContractRenderCache;
use App\Domain\Contracts\ContractFiles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `vasey:contract-render-cache-sweep` removes renderer workspaces a killed parent left under `contracts/render-cache`, at
 * least an hour old, and nothing else: no fresh workspace, no entry that is not a workspace, no link target, nothing outside.
 */
final class ContractRenderCacheSweepTest extends TestCase
{
    /** The site user's uid the sweep runs as: this process's, or a stand-in when the suite runs as root. */
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->uid = posix_geteuid() === 0 ? 4242 : posix_geteuid();
        $this->app->instance(SweepContractRenderCache::EUID_BINDING, $this->uid);
    }

    /** Gives a directory to the stand-in site user (only needed, and only possible, when the suite runs as root). */
    private function own(string $path): string
    {
        if (posix_geteuid() === 0) {
            chown($path, $this->uid);
        }

        return $path;
    }

    /** @return array{0: int, 1: string} */
    private function sweep(array $options = []): array
    {
        $exit = Artisan::call('vasey:contract-render-cache-sweep', $options);

        return [$exit, trim(Artisan::output())];
    }

    public function test_it_removes_only_stale_workspaces_and_never_follows_a_link(): void
    {
        $this->assertSame([0, 'Removed 0 stale render workspace(s); left 0 other entries in place.'], $this->sweep());
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path('contracts'));

        $outside = Storage::disk('local')->path('outside');
        mkdir($outside.'/kept', 0700, true);
        file_put_contents($outside.'/kept/target.txt', 'synthetic');
        $old = time() - 7200;

        $stale = $this->own((new ContractFiles)->createRendererWorkspace()->path);
        mkdir($stale.'/nested', 0700);
        file_put_contents($stale.'/signature.png', 'synthetic');
        file_put_contents($stale.'/nested/cache.bin', 'synthetic');
        symlink($outside.'/kept', $stale.'/link-to-dir');
        symlink($outside.'/kept/target.txt', $stale.'/nested/link-to-file');
        touch($stale, $old);
        $fresh = (new ContractFiles)->createRendererWorkspace();
        $this->own($fresh->path);
        file_put_contents($fresh->path.'/signature.png', 'synthetic');
        $cache = dirname($stale);
        // Entries that are not workspaces: a link under a workspace's name, a file, a directory under another name.
        symlink($outside.'/kept', $cache.'/'.str_repeat('a', 32));
        file_put_contents($cache.'/'.str_repeat('b', 32), 'synthetic');
        mkdir($cache.'/not-a-workspace', 0700);
        touch($cache.'/not-a-workspace', $old);
        // Another cache's old workspace is outside this sweep.
        mkdir(Storage::disk('local')->path('contracts/paid-render-cache/'.str_repeat('c', 32)), 0700, true);
        touch(Storage::disk('local')->path('contracts/paid-render-cache/'.str_repeat('c', 32)), $old);

        $this->assertSame([1, 'Removed 1 stale render workspace(s); left 3 other entries in place.'], $this->sweep());
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertFileExists($outside.'/kept/target.txt');
        $this->assertSame('synthetic', file_get_contents($outside.'/kept/target.txt'));
        $this->assertTrue(is_link($cache.'/'.str_repeat('a', 32)));
        $this->assertFileExists($cache.'/'.str_repeat('b', 32));
        $this->assertDirectoryExists($cache.'/not-a-workspace');
        $this->assertDirectoryExists(Storage::disk('local')->path('contracts/paid-render-cache/'.str_repeat('c', 32)));
        // The fresh workspace is untouched and still closes as its render would close it.
        $this->assertFileExists($fresh->path.'/signature.png');
        $fresh->close();
        $this->assertDirectoryDoesNotExist($fresh->path);

        unlink($cache.'/'.str_repeat('a', 32));
        unlink($cache.'/'.str_repeat('b', 32));
        rmdir($cache.'/not-a-workspace');
        $this->assertSame([0, 'Removed 0 stale render workspace(s); left 0 other entries in place.'], $this->sweep());
    }

    public function test_a_linked_cache_root_is_refused_and_a_short_age_is_invalid(): void
    {
        $target = Storage::disk('local')->path('elsewhere');
        mkdir($target.'/'.str_repeat('d', 32), 0700, true);
        $this->own($target.'/'.str_repeat('d', 32));
        touch($target.'/'.str_repeat('d', 32), time() - 7200);
        mkdir(Storage::disk('local')->path('contracts'), 0700);
        symlink($target, Storage::disk('local')->path('contracts/render-cache'));
        $this->assertSame(1, $this->sweep()[0]);
        $this->assertDirectoryExists($target.'/'.str_repeat('d', 32));

        // A cache directory that is not private is refused too.
        unlink(Storage::disk('local')->path('contracts/render-cache'));
        mkdir(Storage::disk('local')->path('contracts/render-cache/'.str_repeat('e', 32)), 0700, true);
        $this->own(Storage::disk('local')->path('contracts/render-cache/'.str_repeat('e', 32)));
        touch(Storage::disk('local')->path('contracts/render-cache/'.str_repeat('e', 32)), time() - 7200);
        chmod(Storage::disk('local')->path('contracts/render-cache'), 0755);
        $this->assertSame(1, $this->sweep()[0]);
        $this->assertDirectoryExists(Storage::disk('local')->path('contracts/render-cache/'.str_repeat('e', 32)));

        foreach (['3599', '0', 'soon'] as $age) {
            $this->assertSame(2, $this->sweep(['--older-than' => $age])[0], $age);
        }
        $this->assertDirectoryExists($target.'/'.str_repeat('d', 32));
    }

    /** Review of #251 (P3): never as root, only the sweeping user's own workspaces, and an absent root is not an error. */
    public function test_it_refuses_root_leaves_other_users_workspaces_and_sweeps_nothing_without_a_root(): void
    {
        $stale = $this->own((new ContractFiles)->createRendererWorkspace()->path);
        touch($stale, time() - 7200);

        $this->app->instance(SweepContractRenderCache::EUID_BINDING, 0);
        $this->assertSame([1, 'Refusing to sweep as root: run the scheduler as the site user that renders contracts.'], $this->sweep());
        $this->assertDirectoryExists($stale);

        $this->app->instance(SweepContractRenderCache::EUID_BINDING, $this->uid + 1);
        $this->assertSame([1, 'Removed 0 stale render workspace(s); left 1 other entry in place.'], $this->sweep());
        $this->assertDirectoryExists($stale);

        $this->app->instance(SweepContractRenderCache::EUID_BINDING, $this->uid);
        $this->assertSame([0, 'Removed 1 stale render workspace(s); left 0 other entries in place.'], $this->sweep());
        $this->assertDirectoryDoesNotExist($stale);

        // A fresh host without the private disk root: nothing to sweep, and nothing is created.
        $absent = Storage::disk('local')->path('').'absent-root';
        config(['filesystems.disks.local.root' => $absent]);
        $this->assertSame([0, 'Removed 0 stale render workspace(s); left 0 other entries in place.'], $this->sweep());
        $this->assertFileDoesNotExist($absent);
    }

    public function test_it_is_scheduled_hourly_with_a_short_overlap_lock(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'vasey:contract-render-cache-sweep'));
        $this->assertCount(1, $events);
        $this->assertSame(['47 * * * *', true, 10], [$events->first()->expression, $events->first()->withoutOverlapping, $events->first()->expiresAt]);
    }
}
