<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Livewire::test() leaves static auto-injection state set after it renders a component
        // (SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest). That state outlives the
        // application, so a later test in the same process would get Livewire's <style>/<script>
        // injected into every full-HTML response, such as the script-free public embed.
        Livewire::flushState();
    }

    protected function fakePrivateMediaStorage(): void
    {
        // Each test owns its storage, including across separate PHPUnit processes.
        // Storage::fake() ignores a custom root; a unique disk name supplies it.
        $name = 'local-'.Str::uuid();
        $disk = Storage::fake($name, config('filesystems.disks.local'));
        Storage::set('local', $disk);
        $root = $disk->path('');
        // Keep read-only local adapters on the same already-created isolated root without resolving Storage.
        config(['filesystems.disks.local.root' => rtrim($root, '/')]);
        $this->beforeApplicationDestroyed(function () use ($root) {
            (new Filesystem)->deleteDirectory($root);
        });
    }
}
