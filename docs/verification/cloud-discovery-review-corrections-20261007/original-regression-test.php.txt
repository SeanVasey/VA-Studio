<?php

namespace App\Console\Commands;

use Tests\Feature\DiscoverySitemapReviewRegressionTest;

/** Observe the actual exclusive create before the command's following chmod. */
function fopen(string $filename, string $mode): mixed
{
    $file = \fopen($filename, $mode);
    if (DiscoverySitemapReviewRegressionTest::$observeCreate && is_resource($file)) {
        DiscoverySitemapReviewRegressionTest::$modeAtCreate = fstat($file)['mode'] & 0777;
    }

    return $file;
}

namespace Tests\Feature;

use App\Domain\Catalog\DiscoverySitemap\SitemapStore;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class DiscoverySitemapReviewRegressionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static bool $observeCreate = false;

    public static ?int $modeAtCreate = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32)), 'app.url' => 'https://synthetic.example', 'discovery-sitemap.enabled' => true]);
        $this->travelTo(now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        $this->app?->instance('env', 'testing');
        parent::tearDown();
    }

    public function test_no_current_track_generation_preserves_editorial_sitemap_entry(): void
    {
        $this->app->instance('env', 'production');
        $this->get('/sitemap.xml')->assertOk()->assertSee('https://synthetic.example/site-pages-sitemap.xml', false)->assertDontSee('track-sitemaps', false);
    }

    public function test_expired_authentic_track_generation_preserves_editorial_sitemap_entry(): void
    {
        $this->publish();
        $this->travel(3600)->seconds();
        $this->get('/sitemap.xml')->assertOk()->assertSee('https://synthetic.example/site-pages-sitemap.xml', false)->assertDontSee('track-sitemaps', false);
    }

    public function test_expiry_cannot_hide_changed_configuration(): void
    {
        $this->publish();
        $this->travel(3600)->seconds();
        config(['app.url' => 'https://changed.synthetic.example']);
        $this->get('/sitemap.xml')->assertStatus(503)->assertDontSee('site-pages-sitemap', false)->assertDontSee('track-sitemaps', false);
    }

    public function test_capability_file_is_private_at_exclusive_create_and_caller_umask_is_restored(): void
    {
        $path = sys_get_temp_dir().'/synthetic-discovery-permission-'.bin2hex(random_bytes(10));
        $original = umask(0022);
        self::$observeCreate = true;
        self::$modeAtCreate = null;
        try {
            $this->assertSame(0, Artisan::call('discovery-sitemap:build', ['--new-request' => $path]));
            $this->assertSame(0600, self::$modeAtCreate, 'Observe the actual create before any subsequent chmod.');
            $this->assertSame(0022, umask());
            $request = file_get_contents($path);
            $this->assertNotSame('', $request);
            $this->assertStringNotContainsString($request, Artisan::output());
        } finally {
            self::$observeCreate = false;
            umask($original);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function publish(): void
    {
        $this->app->instance('env', 'production');
        $store = app(SitemapStore::class);
        $request = $store->newRequest();
        $store->start($request);
        $store->step($request, 1);
        $store->publish($request, 0);
    }
}
