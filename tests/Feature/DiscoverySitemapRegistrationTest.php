<?php

namespace Tests\Feature;

use App\Domain\Catalog\DiscoverySitemap\SitemapStore;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

final class DiscoverySitemapRegistrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32)), 'app.url' => 'https://synthetic.example', 'discovery-sitemap.enabled' => false]);
    }

    protected function tearDown(): void
    {
        $this->app?->instance('env', 'testing');
        parent::tearDown();
    }

    private function publish(): string
    {
        $this->app->instance('env', 'production');
        config(['discovery-sitemap.enabled' => true]);
        $store = app(SitemapStore::class);
        $request = $store->newRequest();
        $id = $store->start($request)['generation'];
        $this->assertSame('complete', $store->step($request, 1)['state']);
        $this->assertSame($id, $store->publish($request, 0));

        return $id;
    }

    public function test_default_off_keeps_existing_site_index_and_public_routes_have_no_session_stack(): void
    {
        $this->app->instance('env', 'production');
        $this->get('/sitemap.xml')->assertOk()->assertSee('https://synthetic.example/site-pages-sitemap.xml', false)->assertDontSee('track-sitemaps', false);
        $this->get('/track-sitemaps/'.str_repeat('a', 32).'/1.xml')->assertNotFound();
        $route = Route::getRoutes()->getByName('discovery.tracks');
        $this->assertSame(['throttle:128,1,public-discovery-tracks'], $route->gatherMiddleware());
        $this->assertFalse(in_array('web', $route->gatherMiddleware(), true));
    }

    public function test_registered_complete_generation_is_canonical_and_respects_production_evidence_and_epoch(): void
    {
        $fixture = QuoteFixtures::selection();
        $id = $this->publish();
        $headers = ['Host' => 'attacker.example', 'X-Forwarded-Host' => 'forwarded.example', 'Cookie' => 'private=PRIVATE-SENTINEL'];
        $index = $this->withHeaders($headers)->get('/sitemap.xml')->assertOk();
        $this->assertSame(129, substr_count($index->getContent(), '<sitemap>'));
        $child = $this->get('/track-sitemaps/'.$id.'/1.xml')->assertOk();
        // Synthetic scanner evidence remains inadmissible in production. Never advertise fixture tracks.
        $child->assertDontSee('<url>', false);
        foreach ([$index, $child] as $response) {
            $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Robots-Tag', 'noindex, follow');
            $this->assertSame([], $response->headers->getCookies());
            foreach (['attacker', 'forwarded', 'PRIVATE-SENTINEL', 'storage', 'window', 'producer', 'epoch', 'expires'] as $private) {
                $response->assertDontSee($private, false);
            }
        }
        Storage::disk('local')->delete($fixture['media']['preview_tagged']->storage_path);
        $this->get('/track-sitemaps/'.$id.'/1.xml')->assertOk()->assertDontSee('<url>', false);
        $this->get('/track-sitemaps/'.$id.'/128.xml')->assertOk()->assertDontSee('<url>', false);
        $this->call('GET', '/track-sitemaps/'.$id.'/1.xml', [], [], [], ['CONTENT_LENGTH' => '0'], ' ')->assertNotFound();
        $this->get('/track-sitemaps/'.$id.'/01.xml')->assertNotFound();
        $this->get('/track-sitemaps/'.$id.'/1.xml?private=sentinel')->assertNotFound()->assertDontSee('sentinel', false);
        $fixture['track']->update(['status' => 'draft']);
        $this->get('/track-sitemaps/'.$id.'/1.xml')->assertStatus(503)->assertDontSee('<url>', false);
        $this->get('/sitemap.xml')->assertStatus(503)->assertDontSee('<sitemap>', false);
    }

    public function test_complete_advertised_sitemap_family_fits_an_independent_bounded_track_budget(): void
    {
        $this->app->instance('env', 'production');
        for ($request = 0; $request < 58; $request++) {
            $this->get('/robots.txt')->assertOk();
        }
        $id = $this->publish();
        $index = $this->get('/sitemap.xml')->assertOk();
        $this->assertSame(129, substr_count($index->getContent(), '<sitemap>'));
        $this->get('/site-pages-sitemap.xml')->assertOk();
        for ($slot = 1; $slot <= 128; $slot++) {
            $this->get('/track-sitemaps/'.$id.'/'.$slot.'.xml')->assertOk();
        }
        // The independent track family remains rate limited after its complete crawl.
        $this->get('/track-sitemaps/'.$id.'/1.xml')->assertStatus(429);
        $this->get('/robots.txt')->assertStatus(429);
    }

    #[DataProvider('publicPaths')]
    public function test_response_creation_cannot_resolve_callbacks_after_terminal_source_proof(string $path): void
    {
        $id = $this->publish();
        $callbacks = 0;
        $this->app->forgetInstance(ResponseFactory::class);
        $this->app->resolving(ResponseFactory::class, function () use (&$callbacks): void {
            $callbacks++;
            config(['discovery-sitemap.enabled' => false]);
        });
        $response = $this->get(str_replace('{generation}', $id, $path));
        file_put_contents('/tmp/va-discovery-response-factory-'.($path === '/sitemap.xml' ? 'index' : 'child').'.json', json_encode(['callbacks' => $callbacks, 'enabled' => config('discovery-sitemap.enabled'), 'status' => $response->getStatusCode(), 'xml' => $response->getContent()], JSON_PRETTY_PRINT)."\n");
        $this->assertSame(0, $callbacks, 'No response-factory callback after terminal source proof.');
        $this->assertTrue(config('discovery-sitemap.enabled'));
        $response->assertOk();
    }

    public static function publicPaths(): array
    {
        return [['/sitemap.xml'], ['/track-sitemaps/{generation}/1.xml']];
    }
}
