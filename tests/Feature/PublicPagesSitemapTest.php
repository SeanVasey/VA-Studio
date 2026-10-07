<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\SiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

class PublicPagesSitemapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'app.url' => 'https://audio.example.test']);
        $this->app->instance('env', 'production');
    }

    public function test_only_current_verified_public_paths_are_discovered_without_private_fields_or_session(): void
    {
        $actor = LicenseFixtures::admin();
        $service = app(SiteContent::class);
        $active = $service->create(SiteEditorialFixtures::content('ACTIVE BODY'), 'PRIVATE LABEL', $actor);
        $service->publish($active->id, 0, $actor);
        $draft = $service->create(SiteEditorialFixtures::content('PRIVATE DRAFT'), 'DRAFT LABEL', $actor);
        $response = $this->withHeaders(['Host' => 'attacker.example'])->get('/site-pages-sitemap.xml')->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([], $response->headers->getCookies());
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urls = array_map(fn ($loc) => (string) $loc, $xml->xpath('/s:urlset/s:url/s:loc'));
        $this->assertSame(array_map(fn ($p) => 'https://audio.example.test'.$p, ['/', '/about', '/contact', '/blog', '/blog/first-note', '/blog/second-note', '/videos', '/videos/first-film', '/videos/second-film']), $urls);
        foreach (['attacker', 'PRIVATE', 'ACTIVE BODY', 'email', 'media/', 'customer', $draft->content_hash, $active->content_hash] as $private) {
            $response->assertDontSee($private, false);
        }
        $this->assertSame(9, count($xml->xpath('/s:urlset/s:url/*')));
    }

    public function test_unpublished_editorial_is_absent_and_preview_queries_are_rejected(): void
    {
        app(SiteContent::class)->create(SiteEditorialFixtures::content(), 'UNPUBLISHED', LicenseFixtures::admin());
        $this->get('/site-pages-sitemap.xml')->assertOk()->assertSee('<loc>https://audio.example.test/</loc>', false)->assertDontSee('/blog', false);
        foreach (['release=1', 'preview=1', 'page=1', 'anything='] as $query) {
            $this->get('/site-pages-sitemap.xml?'.$query)->assertNotFound();
        }
    }

    public function test_full_verified_schema_bound_has_sixty_five_urls_and_replacement_removes_old_entries(): void
    {
        $actor = LicenseFixtures::admin();
        $service = app(SiteContent::class);
        $content = SiteEditorialFixtures::content();
        foreach (['blog', 'videos'] as $section) {
            $template = $content[$section]['entries'][0];
            $content[$section]['entries'] = [];
            foreach (range(1, 30) as $index) {
                $content[$section]['entries'][] = ['slug' => 'entry-'.$index] + $template;
            }
        }
        $release = $service->create($content, 'BOUND', $actor);
        $service->publish($release->id, 0, $actor);
        $xml = simplexml_load_string($this->get('/site-pages-sitemap.xml')->assertOk()->getContent());
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $this->assertCount(65, $xml->xpath('/s:urlset/s:url/s:loc'));
        $replacement = $service->create(SiteEditorialFixtures::legacy(), 'REPLACE', $actor);
        $service->publish($replacement->id, 1, $actor);
        $this->get('/site-pages-sitemap.xml')->assertOk()->assertDontSee('entry-', false)->assertDontSee('/blog', false);
    }

    #[DataProvider('requestBodies')]
    public function test_nonempty_get_and_head_bodies_are_rejected_even_with_false_zero_content_length(string $method, string $body): void
    {
        $this->call($method, '/site-pages-sitemap.xml', [], [], [], ['CONTENT_LENGTH' => '0'], $body)->assertNotFound();
    }

    public static function requestBodies(): array
    {
        return [['GET', 'x'], ['GET', str_repeat('x', 65536)], ['HEAD', 'x'], ['HEAD', str_repeat('x', 65536)]];
    }

    public function test_empty_get_and_head_bodies_are_allowed(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $this->call($method, '/site-pages-sitemap.xml', [], [], [], ['CONTENT_LENGTH' => '0'], '')->assertOk();
        }
    }

    public function test_nonproduction_environment_exposes_no_indexable_urls(): void
    {
        $this->app->instance('env', 'testing');
        $this->get('/site-pages-sitemap.xml')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDontSee('<loc>', false);
    }

    #[DataProvider('invalidOrigins')]
    public function test_invalid_canonical_configuration_fails_closed(string $origin): void
    {
        config(['app.url' => $origin]);
        $this->get('/site-pages-sitemap.xml')->assertStatus(503);
    }

    public static function invalidOrigins(): array
    {
        return array_map(fn ($origin) => [$origin], ['', 'ftp://audio.example.test', '//audio.example.test', 'https://user:password@audio.example.test', 'https://audio.example.test?draft=1', 'https://audio.example.test#private', 'https://audio.example.test/private', 'https://audio.example.test/../', "https://audio.example.test\n"]);
    }
}
