<?php

namespace Tests\Feature;

use App\Domain\Media\MediaFailure;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\ScanEngines;
use Tests\TestCase;

class PrivateMediaRevisionRootsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    private function processingOutput(PrivateMediaFiles $files): string
    {
        $path = $files->workspace().'/output.bin';
        file_put_contents($path, str_repeat('synthetic revision bytes ', 4));

        return $path;
    }

    public function test_promotion_writes_read_only_copies_only_under_the_fixed_revision_roots(): void
    {
        $files = app(PrivateMediaFiles::class);
        $track = $files->promote($this->processingOutput($files), '0b8f7e9e-5c47-4c61-9f63-2d4d7f6f8a10', 'artwork.png');
        $site = $files->promoteUnder('site-images/revisions', $this->processingOutput($files), '5d0e4a3a-8f4c-4c2e-b61e-0a0f5a8d9c21', '1200.webp');

        $this->assertSame('media/revisions/0b8f7e9e-5c47-4c61-9f63-2d4d7f6f8a10/artwork.png', $track);
        $this->assertSame('site-images/revisions/5d0e4a3a-8f4c-4c2e-b61e-0a0f5a8d9c21/1200.webp', $site);
        foreach ([$track, $site] as $relative) {
            $this->assertSame('0400', substr(sprintf('%o', fileperms($files->resolve($relative))), -4));
        }
        // An existing revision is never replaced.
        $this->expectException(MediaFailure::class);
        $files->promoteUnder('site-images/revisions', $this->processingOutput($files), '5d0e4a3a-8f4c-4c2e-b61e-0a0f5a8d9c21', '1200.webp');
    }

    public function test_promotion_rejects_other_roots_and_unsafe_names(): void
    {
        $files = app(PrivateMediaFiles::class);
        foreach ([
            ['quarantine', 'abc', 'file.png'],
            ['site-images/quarantine', 'abc', 'file.png'],
            ['site-images/revisions', '../escape', 'file.png'],
            ['site-images/revisions', 'a/b', 'file.png'],
            ['site-images/revisions', 'abc', '../file.png'],
            ['site-images/revisions', 'abc', '.hidden.png'],
            ['site-images/revisions', 'abc', 'no-extension'],
        ] as [$root, $directory, $name]) {
            try {
                $files->promoteUnder($root, $this->processingOutput($files), $directory, $name);
                $this->fail("Promotion accepted {$root}/{$directory}/{$name}.");
            } catch (MediaFailure $failure) {
                $this->assertSame('unsafe_path', $failure->failureCode);
            }
        }
    }

    public function test_only_clamav_or_the_testing_engine_counts_as_scan_evidence(): void
    {
        $this->assertTrue(ScanEngines::accepted('clamav'));
        $this->assertTrue(ScanEngines::accepted('test-only'));
        $this->assertTrue(ScanEngines::accepted('test-only', historical: true));
        foreach ([null, '', 'ClamAV', 'other', 1] as $engine) {
            $this->assertFalse(ScanEngines::accepted($engine));
        }
        $this->app['env'] = 'production';
        $this->assertFalse(ScanEngines::accepted('test-only'));
        $this->assertTrue(ScanEngines::accepted('test-only', historical: true));
        $this->assertTrue(ScanEngines::accepted('clamav'));
    }
}
