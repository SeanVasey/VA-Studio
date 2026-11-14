<?php

namespace Tests\Feature;

use App\Domain\Migration\LegacyRedirects\LegacyRedirectResolver;
use App\Domain\SoundKits\Products\SoundKitStorefrontPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * URL-005: the legacy /sound-kits path falls back to the catalog while the sound-kit storefront is off, behind the legacy
 * redirect layer's own literal switch (Sean's decision, 2026-11-14). A real route wins over a map redirect, so the fallback
 * lives in the route's controller, as /services's does (ServiceLegacyFallbackTest).
 */
class SoundKitLegacyFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://audio.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => self::HOST, 'app.debug' => false]);
    }

    public function test_the_shipped_defaults_keep_the_plain_404(): void
    {
        $this->assertFalse(config('legacy-redirects.enabled'));
        $this->assertFalse(app(SoundKitStorefrontPolicy::class)->enabled());
        $this->get(self::HOST.'/sound-kits')->assertNotFound()->assertHeaderMissing('Location');
        $this->assertNull(app(LegacyRedirectResolver::class)->catalogFallback());
    }

    public function test_storefront_off_and_redirects_on_answers_one_hop_301_to_the_canonical_catalog(): void
    {
        config(['legacy-redirects.enabled' => true]);

        $this->get(self::HOST.'/sound-kits')->assertStatus(301)->assertHeader('Location', self::HOST.'/')
            ->assertHeader('Cache-Control', 'max-age=3600, private');
        // The query string is dropped and the request Host never reaches the Location.
        $this->get('https://attacker.example.test/sound-kits?utm_source=x&next=https://evil.example.test')->assertStatus(301)
            ->assertHeader('Location', self::HOST.'/');
        $this->call('HEAD', self::HOST.'/sound-kits')->assertStatus(301)->assertHeader('Location', self::HOST.'/');
    }

    public function test_only_the_legacy_index_path_falls_back(): void
    {
        config(['legacy-redirects.enabled' => true]);
        foreach (['/sound-kits/synthetic-kit', '/sound-kits/unknown'] as $path) {
            $this->get(self::HOST.$path)->assertNotFound()->assertHeaderMissing('Location');
        }
        $this->post(self::HOST.'/sound-kits')->assertStatus(405);
    }

    public function test_storefront_on_serves_the_page_even_with_the_redirect_layer_on(): void
    {
        config(['legacy-redirects.enabled' => true, 'sound-kits.storefront_enabled' => true]);
        $this->get(self::HOST.'/sound-kits')->assertOk()->assertHeaderMissing('Location');
        config(['legacy-redirects.enabled' => false]);
        $this->get(self::HOST.'/sound-kits')->assertOk();
    }

    public function test_only_the_literal_true_switch_enables_the_fallback(): void
    {
        foreach (['true', 1, '1', 'yes'] as $value) {
            config(['legacy-redirects.enabled' => $value]);
            $this->get(self::HOST.'/sound-kits')->assertNotFound();
        }
    }
}
