<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function fakePrivateMediaStorage(): void
    {
        // Each test owns its storage, including across separate PHPUnit processes.
        // Storage::fake() ignores a custom root; a unique disk name supplies it.
        $name = 'local-'.Str::uuid();
        $disk = Storage::fake($name, config('filesystems.disks.local'));
        Storage::set('local', $disk);
        $root = $disk->path('');
        $this->beforeApplicationDestroyed(function () use ($root) {
            (new Filesystem)->deleteDirectory($root);
        });
    }
}
