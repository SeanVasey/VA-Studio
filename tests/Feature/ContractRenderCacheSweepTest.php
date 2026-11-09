<?php

namespace Tests\Feature;

use App\Console\Commands\SweepContractRenderCache;
use App\Domain\Contracts\ContractFiles;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\IsolatedContractRenderer;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberOriginalBudget;
use App\Domain\Grants\Member\MemberOriginalFiles;
use App\Domain\Grants\Member\MemberOriginalIoFrame;
use App\Domain\Grants\Paid\PaidGrantFiles;
use App\Domain\Grants\PaidTax\PaidTaxFiles;
use App\Support\CanonicalJson;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * `vasey:contract-render-cache-sweep` removes renderer workspaces a killed parent left in the contract render caches
 * (`contracts/render-cache`, `paid-render-cache`, `paid-tax-render-cache`, `member-render-cache`), at least an hour old,
 * and nothing else: no fresh workspace, no entry that is not a workspace, no link target, nothing outside those caches.
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

    /** The command's report: one line per cache, `[removed, left]` for the given caches and nothing for the others. */
    private function report(array $counts = [], array $refused = []): string
    {
        $lines = [];
        foreach (ContractFiles::RENDER_CACHES as $cache) {
            if (in_array($cache, $refused, true)) {
                $lines[] = $cache.': could not be swept ('.ContractIssuanceException::class.').';

                continue;
            }
            [$removed, $left] = $counts[$cache] ?? [0, 0];
            $lines[] = sprintf('%s: removed %d stale render workspace(s); left %d other entr%s in place.', $cache, $removed, $left, $left === 1 ? 'y' : 'ies');
        }

        return implode("\n", $lines);
    }

    /** A real workspace of the given cache, created as its renderer creates it. */
    private function workspace(string $cache): string
    {
        // The member I/O frame snapshots resolved database connections, as a member render has them.
        DB::connection()->getPdo();

        return match ($cache) {
            'render-cache' => (new ContractFiles)->createRendererWorkspace()->path,
            'paid-render-cache' => (new PaidGrantFiles)->createRendererWorkspace()->path,
            'paid-tax-render-cache' => (new PaidTaxFiles)->createRendererWorkspace()->path,
            'member-render-cache' => (new MemberOriginalFiles)->createRendererWorkspace(MemberOriginalIoFrame::captureIdle(), MemberOriginalBudget::start())->path,
        };
    }

    public function test_it_removes_only_stale_workspaces_and_never_follows_a_link(): void
    {
        $this->assertSame([0, $this->report()], $this->sweep());
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
        // A directory under contracts/ that is not a render cache (as originals' directories are) is outside the sweep.
        $foreign = Storage::disk('local')->path('contracts/other-render-cache/'.str_repeat('c', 32));
        mkdir($foreign, 0700, true);
        $this->own($foreign);
        touch($foreign, $old);

        $this->assertSame([1, $this->report(['render-cache' => [1, 3]])], $this->sweep());
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertFileExists($outside.'/kept/target.txt');
        $this->assertSame('synthetic', file_get_contents($outside.'/kept/target.txt'));
        $this->assertTrue(is_link($cache.'/'.str_repeat('a', 32)));
        $this->assertFileExists($cache.'/'.str_repeat('b', 32));
        $this->assertDirectoryExists($cache.'/not-a-workspace');
        $this->assertDirectoryExists($foreign);
        // The fresh workspace is untouched and still closes as its render would close it.
        $this->assertFileExists($fresh->path.'/signature.png');
        $fresh->close();
        $this->assertDirectoryDoesNotExist($fresh->path);

        unlink($cache.'/'.str_repeat('a', 32));
        unlink($cache.'/'.str_repeat('b', 32));
        rmdir($cache.'/not-a-workspace');
        $this->assertSame([0, $this->report()], $this->sweep());
    }

    public function test_a_linked_cache_root_is_refused_and_a_short_age_is_invalid(): void
    {
        $target = Storage::disk('local')->path('elsewhere');
        mkdir($target.'/'.str_repeat('d', 32), 0700, true);
        $this->own($target.'/'.str_repeat('d', 32));
        touch($target.'/'.str_repeat('d', 32), time() - 7200);
        mkdir(Storage::disk('local')->path('contracts'), 0700);
        symlink($target, Storage::disk('local')->path('contracts/render-cache'));
        $this->assertSame([1, $this->report(refused: ['render-cache'])], $this->sweep());
        $this->assertDirectoryExists($target.'/'.str_repeat('d', 32));

        // A cache directory that is not private is refused too.
        unlink(Storage::disk('local')->path('contracts/render-cache'));
        mkdir(Storage::disk('local')->path('contracts/render-cache/'.str_repeat('e', 32)), 0700, true);
        $this->own(Storage::disk('local')->path('contracts/render-cache/'.str_repeat('e', 32)));
        touch(Storage::disk('local')->path('contracts/render-cache/'.str_repeat('e', 32)), time() - 7200);
        chmod(Storage::disk('local')->path('contracts/render-cache'), 0755);
        $this->assertSame([1, $this->report(refused: ['render-cache'])], $this->sweep());
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
        $this->assertSame([1, $this->report(['render-cache' => [0, 1]])], $this->sweep());
        $this->assertDirectoryExists($stale);

        $this->app->instance(SweepContractRenderCache::EUID_BINDING, $this->uid);
        $this->assertSame([0, $this->report(['render-cache' => [1, 0]])], $this->sweep());
        $this->assertDirectoryDoesNotExist($stale);

        // A fresh host without the private disk root: nothing to sweep, and nothing is created.
        $absent = Storage::disk('local')->path('').'absent-root';
        config(['filesystems.disks.local.root' => $absent]);
        $this->assertSame([0, $this->report()], $this->sweep());
        $this->assertFileDoesNotExist($absent);
    }

    public static function renderCaches(): array
    {
        return ['paid' => ['paid-render-cache'], 'paid-tax' => ['paid-tax-render-cache'], 'member' => ['member-render-cache']];
    }

    /**
     * Follow-up of #251: the paid, paid-tax and member caches hold only ephemeral workspaces (their originals live under
     * `contracts/paid`, `contracts/paid-tax-v2` and `contracts/production-member-v1`), so they are swept like `render-cache`.
     */
    #[DataProvider('renderCaches')]
    public function test_it_sweeps_each_render_cache_with_the_same_rules_and_leaves_the_other_caches_alone(string $cache): void
    {
        $old = time() - 7200;
        $outside = Storage::disk('local')->path('outside');
        mkdir($outside, 0700, true);
        file_put_contents($outside.'/target.txt', 'synthetic');
        $stale = $this->own($this->workspace($cache));
        mkdir($stale.'/nested', 0700);
        file_put_contents($stale.'/nested/cache.bin', 'synthetic');
        symlink($outside.'/target.txt', $stale.'/link-to-file');
        touch($stale, $old);
        $fresh = $this->own($this->workspace($cache));
        $root = dirname($stale);
        $this->assertSame(Storage::disk('local')->path('contracts/'.$cache), $root);
        // Entries that are not workspaces: a link under a workspace's name, a file, a directory under another name.
        symlink($outside, $root.'/'.str_repeat('a', 32));
        file_put_contents($root.'/'.str_repeat('b', 32), 'synthetic');
        mkdir($root.'/not-a-workspace', 0700);
        touch($root.'/not-a-workspace', $old);
        // Every other cache holds a stale workspace of its own and a fresh one; durable originals' directories sit beside them.
        $others = [];
        foreach (array_diff(ContractFiles::RENDER_CACHES, [$cache]) as $other) {
            $others[$other] = [$this->own($this->workspace($other)), $this->own($this->workspace($other))];
            touch($others[$other][0], $old);
        }
        foreach (['paid', 'paid-tax-v2', 'production-member-v1', 'test'] as $originals) {
            mkdir(Storage::disk('local')->path('contracts/'.$originals.'/'.str_repeat('f', 32)), 0700, true);
            $this->own(Storage::disk('local')->path('contracts/'.$originals.'/'.str_repeat('f', 32)));
            touch(Storage::disk('local')->path('contracts/'.$originals.'/'.str_repeat('f', 32)), $old);
        }

        // Each cache is counted on its own line; the others' stale workspaces go too, and nothing else.
        $counts = array_fill_keys(ContractFiles::RENDER_CACHES, [1, 0]);
        $counts[$cache] = [1, 3];
        $this->assertSame([1, $this->report($counts)], $this->sweep());
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($fresh);
        $this->assertSame('synthetic', file_get_contents($outside.'/target.txt'));
        $this->assertTrue(is_link($root.'/'.str_repeat('a', 32)));
        $this->assertFileExists($root.'/'.str_repeat('b', 32));
        $this->assertDirectoryExists($root.'/not-a-workspace');
        foreach ($others as [$otherStale, $otherFresh]) {
            $this->assertDirectoryDoesNotExist($otherStale);
            $this->assertDirectoryExists($otherFresh);
        }
        foreach (['paid', 'paid-tax-v2', 'production-member-v1', 'test'] as $originals) {
            $this->assertDirectoryExists(Storage::disk('local')->path('contracts/'.$originals.'/'.str_repeat('f', 32)));
        }

        // Another user's stale workspace is left and counted; a linked or non-private cache is refused and the others are still swept.
        $foreign = $this->own($this->workspace($cache));
        touch($foreign, $old);
        $this->app->instance(SweepContractRenderCache::EUID_BINDING, $this->uid + 1);
        // (Every entry of another owner is counted, so each cache's fresh workspace is counted too.)
        $counts = array_fill_keys(ContractFiles::RENDER_CACHES, [0, 1]);
        $counts[$cache] = [0, 5];
        $this->assertSame([1, $this->report($counts)], $this->sweep());
        $this->assertDirectoryExists($foreign);
        $this->app->instance(SweepContractRenderCache::EUID_BINDING, $this->uid);
        $sibling = $this->own($this->workspace('render-cache'));
        touch($sibling, $old);
        chmod($root, 0750);
        $this->assertSame([1, $this->report(['render-cache' => [1, 0]], [$cache])], $this->sweep());
        $this->assertDirectoryExists($foreign);
        $this->assertDirectoryDoesNotExist($sibling);
        chmod($root, 0700);
        $this->assertSame([1, $this->report([$cache => [1, 3]])], $this->sweep());
        $this->assertDirectoryDoesNotExist($foreign);
    }

    /** Follow-up of #251: a workspace-named directory that is not mode 0700 is never removed, only counted. */
    public function test_a_workspace_named_directory_that_is_not_private_is_left_and_counted(): void
    {
        $old = time() - 7200;
        foreach (ContractFiles::RENDER_CACHES as $cache) {
            $loose = $this->own($this->workspace($cache));
            file_put_contents($loose.'/kept.bin', 'synthetic');
            chmod($loose, 0750);
            touch($loose, $old);
        }
        $this->assertSame([1, $this->report(array_fill_keys(ContractFiles::RENDER_CACHES, [0, 1]))], $this->sweep());
        foreach (ContractFiles::RENDER_CACHES as $cache) {
            $this->assertCount(1, glob(Storage::disk('local')->path('contracts/'.$cache).'/*/kept.bin'), $cache);
        }
    }

    /**
     * Follow-up of #251: a workspace swept while its render still holds it (the parent was stalled past the cutoff). The
     * render's close then finds its workspace gone and refuses `storage_failed`, which the contract work treats as
     * retryable; nothing was stored, so nothing is lost and a retry renders anew.
     */
    public function test_a_workspace_swept_under_a_stalled_render_fails_that_render_retryably(): void
    {
        foreach (['render-cache', 'paid-render-cache', 'paid-tax-render-cache'] as $cache) {
            $workspace = match ($cache) {
                'render-cache' => (new ContractFiles)->createRendererWorkspace(),
                'paid-render-cache' => (new PaidGrantFiles)->createRendererWorkspace(),
                'paid-tax-render-cache' => (new PaidTaxFiles)->createRendererWorkspace(),
            };
            $this->own($workspace->path);
            touch($workspace->path, time() - 7200);
            $this->assertSame([0, $this->report([$cache => [1, 0]])], $this->sweep());
            try {
                $workspace->close();
                $this->fail('A swept workspace closed cleanly.');
            } catch (ContractIssuanceException $error) {
                $this->assertSame('storage_failed', $error->reason, $cache);
            }
        }
        DB::connection()->getPdo();
        $member = (new MemberOriginalFiles)->createRendererWorkspace(MemberOriginalIoFrame::captureIdle(), MemberOriginalBudget::start());
        $this->own($member->path);
        touch($member->path, time() - 7200);
        $this->assertSame([0, $this->report(['member-render-cache' => [1, 0]])], $this->sweep());
        try {
            $member->close();
            $this->fail('A swept member workspace closed cleanly.');
        } catch (MemberGrantException $error) {
            $this->assertSame('storage_failed', $error->reason);
        }

        // The same race through a real render: the sweep runs after the workspace exists and before the child answers.
        $bytes = "%PDF-1.7\nSynthetic transport-only envelope\n%%EOF\n";
        $profile = ['test' => 'synthetic-profile'];
        $reply = ['pdf_base64' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes),
            'page_count' => 1, 'text_digest' => hash('sha256', 'synthetic'), 'profile_hash' => CanonicalJson::hash($profile)];
        $renderer = new IsolatedContractRenderer(function ($command, $root, $environment, $payload) use ($reply) {
            $this->own($environment['TMPDIR']);
            touch($environment['TMPDIR'], time() - 7200);
            $this->assertSame([0, $this->report(['render-cache' => [1, 0]])], $this->sweep());

            return new Process([PHP_BINARY, '-r', 'stream_get_contents(STDIN); echo base64_decode('.var_export(base64_encode(json_encode($reply)), true).');'],
                $root, $environment, $payload, 60);
        });
        try {
            $renderer->render(['test' => 'synthetic-input'], $profile);
            $this->fail('A render whose workspace was swept succeeded.');
        } catch (ContractIssuanceException $error) {
            $this->assertSame('storage_failed', $error->reason);
        }
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
