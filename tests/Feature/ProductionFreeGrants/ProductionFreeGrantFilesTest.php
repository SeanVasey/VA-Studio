<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Contracts\RenderedContract;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantFiles;
use Illuminate\Support\Str;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** F-5: original storage must never create a file outside the private root, even through a planted symlink. */
final class ProductionFreeGrantFilesTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->outside = realpath(sys_get_temp_dir()).'/va-free256-outside-'.bin2hex(random_bytes(8));
        $this->beforeApplicationDestroyed(function (): void {
            @chmod($this->outside, 0600);
            @unlink($this->outside);
        });
    }

    public function test_a_dangling_symlink_at_the_original_path_creates_nothing_outside_the_root(): void
    {
        [$origin, $claim, $directory] = $this->claimDirectory();
        symlink($this->outside, $directory.'/original.pdf');
        $this->assertFalse(file_exists($this->outside));

        $this->refuses(fn () => (new ProductionFreeGrantFiles)->store($origin, $claim, $this->rendered()));

        clearstatcache();
        $this->assertFalse(file_exists($this->outside), 'The store created a file at the symlink target.');
        $this->assertFalse(@lstat($this->outside));
        $this->assertTrue(is_link($directory.'/original.pdf'));
        $this->assertSame($this->outside, readlink($directory.'/original.pdf'));
        $this->assertSame(['original.pdf'], $this->entries($directory), 'No temporary name may remain.');
    }

    public function test_a_live_symlink_to_an_existing_outside_file_leaves_it_untouched(): void
    {
        [$origin, $claim, $directory] = $this->claimDirectory();
        file_put_contents($this->outside, 'outside bytes');
        chmod($this->outside, 0600);
        symlink($this->outside, $directory.'/original.pdf');

        $this->refuses(fn () => (new ProductionFreeGrantFiles)->store($origin, $claim, $this->rendered()));

        $this->assertSame('outside bytes', file_get_contents($this->outside));
        $this->assertSame(['original.pdf'], $this->entries($directory));
    }

    public function test_the_original_is_written_once_sealed_and_leaves_no_temporary_name(): void
    {
        [$origin, $claim, $directory] = $this->claimDirectory();
        $rendered = $this->rendered();
        $files = new ProductionFreeGrantFiles;
        $record = $files->store($origin, $claim, $rendered);

        $this->assertSame(['original.pdf'], $this->entries($directory));
        $stat = lstat($directory.'/original.pdf');
        $this->assertSame(0400, $stat['mode'] & 07777);
        $this->assertSame(1, $stat['nlink']);
        $this->assertSame($rendered->pdfBytes, $files->verify($record));

        $this->refuses(fn () => $files->store($origin, $claim, $this->rendered("\nsecond render")));
        $this->assertSame($rendered->pdfBytes, file_get_contents($directory.'/original.pdf'));
        $this->assertSame(['original.pdf'], $this->entries($directory));
    }

    /** @return array{0:string,1:string,2:string} */
    private function claimDirectory(): array
    {
        $origin = (string) Str::uuid();
        $claim = (string) Str::uuid();
        $directory = $this->privateRoot.'/contracts/production-free-v1/'.$origin.'/'.$claim;
        mkdir($directory, 0700, true);
        foreach ([$this->privateRoot.'/contracts', $this->privateRoot.'/contracts/production-free-v1', dirname($directory), $directory] as $path) {
            chmod($path, 0700);
        }

        return [$origin, $claim, $directory];
    }

    private function rendered(string $extra = ''): RenderedContract
    {
        $bytes = "%PDF-1.7\nSynthetic free256 original ".$extra."\n%%EOF\n";

        return new RenderedContract($bytes, hash('sha256', $bytes), strlen($bytes), 1, hash('sha256', 'text'), hash('sha256', 'profile'));
    }

    /** @return list<string> */
    private function entries(string $directory): array
    {
        return array_values(array_diff(scandir($directory), ['.', '..']));
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Must refuse storage_failed');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('storage_failed', $error->reason);
        }
    }
}
