<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishTrack;
use App\Domain\Migration\LegacyRedirects\LegacyRedirectMap;
use App\Domain\Migration\LegacyRedirects\LegacyRedirectMapInvalid;
use App\Domain\Migration\LegacyRedirects\LegacyRedirectMapWarnings;
use App\Domain\Migration\LegacyRedirects\LegacyRedirectResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\LegacyRedirectFixtures as F;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class LegacyRedirectCacheTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.url' => 'https://audio.example.test', 'app.debug' => false, 'cache.default' => 'array']);
        Cache::purge('array');
        Cache::forget(LegacyRedirectMapWarnings::CACHE_KEY);
        $this->directory = sys_get_temp_dir().'/legacy-cache-'.Str::uuid();
        // Keep one resolver for all requests, as in a long-lived application worker.
        $this->app->instance(LegacyRedirectResolver::class, app(LegacyRedirectResolver::class));
    }

    protected function tearDown(): void
    {
        F::remove($this->directory);
        parent::tearDown();
    }

    private function activate(array $entries, array $hosts = []): string
    {
        $files = F::write($this->directory, $entries, $hosts);
        config(['legacy-redirects.enabled' => true, 'legacy-redirects.map_path' => $files['map']]);

        return $files['map'];
    }

    private function valid(): array
    {
        return [F::redirect('URL-001', '/legacy-fixture/cached', ['type' => 'route', 'name' => 'home'])];
    }

    private function invalid(string $path): void
    {
        file_put_contents($path, json_encode(F::map([
            F::redirect('SYNTHETIC_PRIVATE_LABEL', '/legacy-fixture/cached', ['type' => 'route', 'name' => 'home']),
        ]), JSON_THROW_ON_ERROR));
    }

    public function test_unchanged_guarded_source_reuses_one_parsed_map_and_other_sources_evict_it(): void
    {
        $path = $this->activate($this->valid());
        $first = LegacyRedirectMap::fromFile($path);
        $this->assertSame($first, LegacyRedirectMap::fromFile($path));
        $other = $this->directory.'/other.json';
        copy($path, $other);
        $this->assertNotSame($first, LegacyRedirectMap::fromFile($other));
        $again = LegacyRedirectMap::fromFile($path);
        $this->assertNotSame($first, $again, 'Only the most recent configured source is retained.');
        $this->assertSame($first->entries, $again->entries);
    }

    public function test_same_size_and_mtime_changed_bytes_are_reloaded_in_the_same_worker(): void
    {
        $path = $this->activate([F::redirect('URL-001', '/legacy-fixture/cached', ['type' => 'external', 'url' => 'https://cdn.example.test/a'])], ['cdn.example.test']);
        $first = LegacyRedirectMap::fromFile($path);
        $size = filesize($path);
        $mtime = filemtime($path);
        $this->get('/legacy-fixture/cached')->assertStatus(301)->assertHeader('Location', 'https://cdn.example.test/a');
        file_put_contents($path, str_replace('https://cdn.example.test/a', 'https://cdn.example.test/b', file_get_contents($path)));
        touch($path, $mtime);
        clearstatcache(true, $path);
        $this->assertSame($size, filesize($path));
        $this->assertSame($mtime, filemtime($path));
        $this->assertNotSame($first, LegacyRedirectMap::fromFile($path));
        $this->get('/legacy-fixture/cached')->assertStatus(301)->assertHeader('Location', 'https://cdn.example.test/b')
            ->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    public function test_atomic_replacement_with_identical_bytes_is_bound_to_the_new_file_identity(): void
    {
        $path = $this->activate($this->valid());
        $first = LegacyRedirectMap::fromFile($path);
        $replacement = $this->directory.'/replacement.json';
        copy($path, $replacement);
        rename($replacement, $path);
        $next = LegacyRedirectMap::fromFile($path);
        $this->assertNotSame($first, $next);
        $this->assertSame($first->entries, $next->entries);
    }

    public function test_missing_invalid_symlinked_and_oversized_sources_never_fall_back_to_cached_valid_maps(): void
    {
        $path = $this->activate($this->valid());
        $json = file_get_contents($path);
        $first = LegacyRedirectMap::fromFile($path);
        foreach (['missing', 'invalid', 'symlink', 'oversized'] as $kind) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
            if ($kind === 'invalid') {
                file_put_contents($path, '{');
            } elseif ($kind === 'symlink') {
                file_put_contents($this->directory.'/target.json', $json);
                symlink($this->directory.'/target.json', $path);
            } elseif ($kind === 'oversized') {
                file_put_contents($path, str_repeat(' ', LegacyRedirectMap::MAX_BYTES + 1));
            }
            try {
                LegacyRedirectMap::fromFile($path);
                $this->fail($kind.' source reused a cached map.');
            } catch (LegacyRedirectMapInvalid) {
                $this->addToAssertionCount(1);
            }
            if (is_link($path)) {
                unlink($path);
            }
            file_put_contents($path, $json);
            $repaired = LegacyRedirectMap::fromFile($path);
            $this->assertNotSame($first, $repaired);
            $this->assertSame($first->entries, $repaired->entries);
            $first = $repaired;
        }
    }

    public function test_invalid_warning_backoff_is_bounded_private_and_repair_resets_the_episode(): void
    {
        Log::spy();
        $path = $this->activate($this->valid());
        $this->invalid($path);
        for ($i = 0; $i < 5; $i++) {
            $this->get('/legacy-fixture/cached')->assertNotFound()->assertHeaderMissing('Location');
        }
        $this->assertSame(hash('sha256', $path), Cache::get(LegacyRedirectMapWarnings::CACHE_KEY));
        Log::shouldHaveReceived('warning')->once()->with('Legacy redirect map refused; no legacy redirects are served.', ['category' => 'invalid_map']);
        $this->travel(61)->seconds();
        $this->get('/legacy-fixture/cached')->assertNotFound();
        Log::shouldHaveReceived('warning')->twice();
        $this->activate($this->valid());
        $this->get('/legacy-fixture/cached')->assertStatus(301);
        $this->assertNull(Cache::get(LegacyRedirectMapWarnings::CACHE_KEY));
        $this->invalid($path);
        $this->get('/legacy-fixture/cached')->assertNotFound();
        Log::shouldHaveReceived('warning')->times(3)->withArgs(function (string $message, array $context) use ($path): bool {
            $this->assertStringNotContainsString($path, json_encode([$message, $context], JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('SYNTHETIC_PRIVATE_LABEL', json_encode([$message, $context], JSON_THROW_ON_ERROR));

            return $context === ['category' => 'invalid_map'];
        });
    }

    public function test_flag_disable_does_not_read_or_warn_and_reenable_checks_the_changed_source(): void
    {
        Log::spy();
        $path = $this->activate($this->valid());
        $this->get('/legacy-fixture/cached')->assertStatus(301);
        foreach ([false, 'true', 1] as $disabled) {
            config(['legacy-redirects.enabled' => $disabled]);
            $this->invalid($path);
            $this->get('/legacy-fixture/cached')->assertNotFound()->assertHeaderMissing('Location');
        }
        Log::shouldNotHaveReceived('warning');
        config(['legacy-redirects.enabled' => true]);
        $this->get('/legacy-fixture/cached')->assertNotFound()->assertHeaderMissing('Location');
        Log::shouldHaveReceived('warning')->once();
        $this->activate($this->valid());
        $this->get('/legacy-fixture/cached')->assertStatus(301);
    }

    public function test_a_cached_map_still_rechecks_target_publication_and_the_serving_route(): void
    {
        $selection = QuoteFixtures::selection();
        $path = $this->activate([
            F::redirect('URL-001', '/legacy-fixture/cached', ['type' => 'track', 'slug' => $selection['track']->slug]),
            F::redirect('URL-002', '/about', ['type' => 'route', 'name' => 'home']),
        ]);
        $first = LegacyRedirectMap::fromFile($path);
        $this->get('/legacy-fixture/cached')->assertStatus(301);
        $this->get('/about')->assertNotFound()->assertHeaderMissing('Location');
        app(PublishTrack::class)->unpublish($selection['track'], $selection['actor']);
        $this->get('/legacy-fixture/cached')->assertNotFound()->assertHeaderMissing('Location');
        $this->assertSame($first, LegacyRedirectMap::fromFile($path), 'Only map structure was cached, never target eligibility.');
    }

    public function test_unsuccessful_cache_writes_also_use_the_bounded_local_warning_episode(): void
    {
        Log::spy();
        Cache::shouldReceive('add')->andReturn(false);
        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('put')->andReturn(false);
        $warnings = app(LegacyRedirectMapWarnings::class);
        $source = $this->directory.'/missing.json';
        for ($i = 0; $i < 5; $i++) {
            $warnings->invalid($source);
        }
        Log::shouldHaveReceived('warning')->once();
        $this->travel(61)->seconds();
        $warnings->invalid($source);
        Log::shouldHaveReceived('warning')->twice();
        $warnings->repaired($source);
        $warnings->invalid($source);
        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_a_cache_store_failure_only_uses_bounded_local_warning_fallback_and_never_changes_map_decisions(): void
    {
        Log::spy();
        Cache::shouldReceive('add')->andThrow(new RuntimeException('SYNTHETIC_PRIVATE_CACHE_ERROR'));
        Cache::shouldReceive('get')->andThrow(new RuntimeException('SYNTHETIC_PRIVATE_CACHE_ERROR'));
        $warnings = app(LegacyRedirectMapWarnings::class);
        $source = $this->directory.'/missing.json';
        $warnings->invalid($source);
        $warnings->invalid($source);
        Log::shouldHaveReceived('warning')->once()->with('Legacy redirect map refused; no legacy redirects are served.', ['category' => 'invalid_map']);
        $this->travel(61)->seconds();
        $warnings->invalid($source);
        Log::shouldHaveReceived('warning')->twice();
        $path = $this->activate($this->valid());
        $this->get('/legacy-fixture/cached')->assertStatus(301);
        $warnings->repaired($source);
        $warnings->invalid($source);
        Log::shouldHaveReceived('warning')->times(3);
    }
}
