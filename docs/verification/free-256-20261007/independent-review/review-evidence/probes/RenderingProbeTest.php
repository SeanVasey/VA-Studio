<?php

namespace Tests\ReviewProbes\Free256;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\RenderedContract;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantFiles;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderInput;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** Independent review probe (not part of the suite). Question 3: originals, write-once store and renderer isolation. */
final class RenderingProbeTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->outside = sys_get_temp_dir().'/va-free256-outside-'.bin2hex(random_bytes(6));
        mkdir($this->outside, 0700);
        $this->beforeApplicationDestroyed(function (): void {
            foreach (glob($this->outside.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->outside);
        });
    }

    public function test_rerender_is_byte_identical_recovery_is_by_hash_and_rewrite_or_truncation_is_refused(): void
    {
        [$origin, $owner] = $this->origin();
        $documents = new ProductionFreeGrantDocuments;
        $documents->render($origin['id']);
        $original = (array) DB::table('production_free_originals')->first();
        $path = $this->privateRoot.'/'.ProductionFreeGrantFiles::path($origin['id'], $original['claim_id']);
        $bytes = file_get_contents($path);
        $payload = json_decode(Crypt::decryptString(DB::table('production_free_origins')->value('payload_ciphertext')), true);
        $hashes = [];
        for ($i = 0; $i < 3; $i++) {
            $hashes[] = app(ProductionFreeGrantRendererProcess::class)->render(ProductionFreeGrantRenderInput::fromOrigin($payload), $payload['profile'])->sha256;
        }
        $this->assertSame([$original['sha256'], $original['sha256'], $original['sha256']], $hashes);
        $this->assertTrue($documents->recover($origin['id'])['identical']);
        // Write-once: a second store at the same origin/claim never replaces the file.
        $rendered = new RenderedContract($bytes, $original['sha256'], strlen($bytes), 1, $original['text_digest'], $original['profile_hash']);
        $this->refuses(fn () => (new ProductionFreeGrantFiles)->store($origin['id'], $original['claim_id'], $rendered), 'storage_failed');
        $this->assertSame($bytes, file_get_contents($path));
        // A hard link to the original (nlink 2) makes it unavailable rather than silently served.
        link($path, $this->outside.'/hardlink.pdf');
        $this->refuses(fn () => $documents->recover($origin['id']), 'original_unavailable');
        unlink($this->outside.'/hardlink.pdf');
        $this->assertTrue($documents->recover($origin['id'])['identical']);
        // Truncation (root ignores 0400) is detected by size/hash before any byte is delivered; nothing is repaired.
        $seal = (new ProductionFreeGrantLibrary)->show($origin['id'], $owner['principal'], $owner['user'])['originSeal'];
        $downloads = new ProductionFreeGrantDownloads;
        $authorization = $downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'contract'], $owner['principal'], $owner['user']);
        $handle = fopen($path, 'r+b');
        ftruncate($handle, 64);
        fclose($handle);
        $this->refuses(fn () => $documents->recover($origin['id']), 'original_unavailable');
        $this->refuses(fn () => $downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']), 'original_unavailable');
        $this->assertSame(64, filesize($path));
        $this->assertSame(0, DB::table('production_free_redemptions')->count());
        $this->assertSame(1, DB::table('production_free_originals')->count());
        fwrite(STDERR, "PROBE render.identical: 3 fresh child renders == stored sha {$original['sha256']}; second store refused; hardlink -> original_unavailable; truncation -> recover and contract redeem refused, file left as found, 0 redemptions\n");
    }

    public function test_symlinked_claim_file_and_directory_are_refused_and_outside_targets_untouched(): void
    {
        [$origin] = $this->origin();
        $target = $this->outside.'/victim.pdf';
        file_put_contents($target, 'outside victim');
        $this->renderer(function () use ($origin, $target): void {
            $claim = DB::table('production_free_document_work')->orderByDesc('ordinal')->value('claim_id');
            $this->mkdirs($origin['id'], null);
            $directory = $this->privateRoot.'/contracts/production-free-v1/'.$origin['id'].'/'.$claim;
            mkdir($directory, 0700);
            symlink($target, $directory.'/original.pdf');
        });
        $this->refuses(fn () => (new ProductionFreeGrantDocuments)->render($origin['id']), 'storage_failed');
        $this->assertSame('outside victim', file_get_contents($target));
        // Dangling symlink: PHP's plain-file fopen('x') resolves the link before O_EXCL, so an EMPTY file is created at
        // the outside target before sameFile() refuses. No rendered byte is written there and nothing is published.
        $dangling = $this->outside.'/created-through-link.pdf';
        $this->renderer(function () use ($origin, $dangling): void {
            $claim = DB::table('production_free_document_work')->orderByDesc('ordinal')->value('claim_id');
            $directory = $this->privateRoot.'/contracts/production-free-v1/'.$origin['id'].'/'.$claim;
            mkdir($directory, 0700);
            symlink($dangling, $directory.'/original.pdf');
        });
        $this->refuses(fn () => (new ProductionFreeGrantDocuments)->render($origin['id']), 'storage_failed');
        $this->assertFileExists($dangling);
        $this->assertSame(0, filesize($dangling));
        $createdMode = sprintf('%o', fileperms($dangling) & 0777);
        @unlink($dangling);
        // Symlinked claim directory pointing outside the private root.
        $this->renderer(function () use ($origin): void {
            $claim = DB::table('production_free_document_work')->orderByDesc('ordinal')->value('claim_id');
            symlink($this->outside, $this->privateRoot.'/contracts/production-free-v1/'.$origin['id'].'/'.$claim);
        });
        $this->refuses(fn () => (new ProductionFreeGrantDocuments)->render($origin['id']), 'storage_failed');
        $this->assertSame(['victim.pdf'], array_values(array_diff(scandir($this->outside), ['.', '..'])));
        $this->assertSame(0, DB::table('production_free_originals')->count());
        $this->assertSame(['claimed', 'failed', 'claimed', 'failed', 'claimed', 'failed'], DB::table('production_free_document_work')->orderBy('ordinal')->pluck('kind')->all());
        // Symlinked origin directory (planted before the first claim of a second origin).
        [$second] = $this->origin('second@example.test');
        $this->mkdirs(null, null);
        symlink($this->outside, $this->privateRoot.'/contracts/production-free-v1/'.$second['id']);
        $this->app->forgetInstance(ProductionFreeGrantRendererProcess::class);
        $this->app->offsetUnset(ProductionFreeGrantRendererProcess::class);
        $this->refuses(fn () => (new ProductionFreeGrantDocuments)->render($second['id']), 'storage_failed');
        $this->assertSame(['victim.pdf'], array_values(array_diff(scandir($this->outside), ['.', '..'])));
        fwrite(STDERR, "PROBE render.symlink: dangling-link target created OUTSIDE the private root as an empty file mode {$createdMode} before refusal; symlinked original.pdf (live and dangling), symlinked claim dir and symlinked origin dir all refused (storage_failed); outside dir unchanged; each failure appended its own 'failed' row\n");
    }

    public function test_path_traversal_in_origin_or_claim_segments_is_refused(): void
    {
        [$origin] = $this->origin();
        $documents = new ProductionFreeGrantDocuments;
        $files = new ProductionFreeGrantFiles;
        $rendered = new RenderedContract("%PDF-1.4\n".str_repeat('x', 40)."\n%%EOF", hash('sha256', "%PDF-1.4\n".str_repeat('x', 40)."\n%%EOF"), 55, 1, str_repeat('a', 64), str_repeat('b', 64));
        foreach (['../../../../tmp', '..', $origin['id'].'/..', strtoupper($origin['id']), $origin['id']."\0"] as $segment) {
            $this->refuses(fn () => $documents->render($segment), 'invalid_input');
            $this->refuses(fn () => $files->store($segment, $origin['id'], $rendered), 'storage_failed');
            $this->refuses(fn () => $files->store($origin['id'], $segment, $rendered), 'storage_failed');
        }
        foreach (['contracts/production-free-v1/../../../etc/passwd', 'contracts/production-free-v1/'.$origin['id'].'/../x/original.pdf', '/etc/passwd'] as $path) {
            $this->refuses(fn () => $files->verify(['disk' => 'local', 'storage_path' => $path, 'pdf_hash' => str_repeat('a', 64), 'size_bytes' => 64, 'page_count' => 1, 'profile_hash' => str_repeat('b', 64)]), 'original_unavailable');
        }
        $this->assertFileDoesNotExist($this->privateRoot.'/contracts');
        fwrite(STDERR, "PROBE render.traversal: '..', nested '..', uppercase and NUL segments refused for render (invalid_input) and store (storage_failed) in origin and claim positions; traversal storage paths refused by verify; nothing created\n");
    }

    public function test_renderer_child_isolation_and_bounded_input(): void
    {
        [$origin] = $this->origin();
        $captured = null;
        $this->app->bind(ProductionFreeGrantRendererProcess::class, function () use (&$captured) {
            return new ProductionFreeGrantRendererProcess(
                function (array $command, string $cwd, array $environment, string $payload) use (&$captured): Process {
                    $captured = compact('command', 'cwd', 'environment', 'payload');

                    return new Process($command, $cwd, $environment, $payload, 60);
                });
        });
        putenv('VA_REVIEW_SECRET_PROBE=leak-me');
        try {
            (new ProductionFreeGrantDocuments)->render($origin['id']);
        } finally {
            putenv('VA_REVIEW_SECRET_PROBE');
        }
        $this->assertNotNull($captured);
        $environment = $captured['environment'];
        $this->assertFalse($environment['VA_REVIEW_SECRET_PROBE']);
        $this->assertSame([], array_keys(array_filter($environment, fn ($v, $k) => $v !== false && ! in_array($k, ['LANG', 'LC_ALL', 'TZ', 'TMPDIR', 'LD_LIBRARY_PATH'], true), ARRAY_FILTER_USE_BOTH)));
        $command = $captured['command'];
        $this->assertSame('-n', $command[1]);
        $ini = [];
        foreach ($command as $index => $part) {
            if ($part === '-d' && str_contains($command[$index + 1], '=') && ! str_starts_with($command[$index + 1], 'extension=')) {
                [$key, $value] = explode('=', $command[$index + 1], 2);
                $ini[$key] = $value;
            }
        }
        $this->assertSame('0', $ini['allow_url_fopen']);
        $this->assertStringContainsString(base_path(), $ini['open_basedir']);
        // Run the same command and environment with a reach probe instead of the renderer script.
        $reach = [...array_slice($command, 0, -1), __DIR__.'/renderer-child-reach.php', base_path(), base_path('storage/app/.gitignore')];
        $environment['TMPDIR'] = sys_get_temp_dir();
        $process = new Process($reach, $captured['cwd'], $environment, null, 30);
        $process->run();
        $result = json_decode($process->getOutput(), true);
        $this->assertIsArray($result, $process->getErrorOutput());
        $this->assertFalse($result['env_app_key']);
        $this->assertFalse($result['http_fetch']);
        $this->assertFalse($result['fsockopen_callable']);
        $this->assertFalse($result['proc_open_callable']);
        // Oversized input is refused before any child process starts.
        $payload = json_decode(Crypt::decryptString(DB::table('production_free_origins')->value('payload_ciphertext')), true);
        $input = ProductionFreeGrantRenderInput::fromOrigin($payload);
        $input['terms']['text'] = str_repeat('a', 1048577);
        $captured = null;
        try {
            app(ProductionFreeGrantRendererProcess::class)->render($input, $payload['profile']);
            $this->fail('Oversized input must be refused');
        } catch (ContractIssuanceException $error) {
            $this->assertSame('unsupported_input', $error->reason);
        }
        $this->assertNull($captured);
        fwrite(STDERR, 'PROBE render.isolation: open_basedir='.$ini['open_basedir'].' reach='.json_encode($result, JSON_UNESCAPED_SLASHES)."; oversized input refused before spawn\n");
    }

    private function mkdirs(?string $origin, ?string $claim): void
    {
        foreach (['contracts', 'contracts/production-free-v1', $origin === null ? null : 'contracts/production-free-v1/'.$origin] as $part) {
            if ($part !== null && ! is_dir($this->privateRoot.'/'.$part)) {
                mkdir($this->privateRoot.'/'.$part, 0700);
            }
        }
    }

    private function origin(string $email = 'free-owner@example.test'): array
    {
        static $definition = null;
        $definition = $email === 'free-owner@example.test' ? $this->openDefinition() : $definition;
        $owner = $this->customer($email);
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);

        return [$grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']), $owner];
    }

    private function renderer(Closure $hook): void
    {
        $this->app->bind(ProductionFreeGrantRendererProcess::class, fn () => new ProductionFreeGrantRendererProcess(
            function (array $command, string $cwd, array $environment, string $payload) use ($hook): Process {
                $hook();

                return new Process($command, $cwd, $environment, $payload, 60);
            }));
    }

    private function refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.$reason);
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
