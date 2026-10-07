<?php
namespace Tests\Feature;
use App\Domain\SiteBuilder\PublicPagesSitemap;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class DiscoveryIndexIndependentCanaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug'=>false,'app.url'=>'https://canonical.example.test:8443/']);
        $this->app->instance('env','production');
    }
    public function test_discovery_index_and_robots_read_no_catalog_customer_or_publication_data(): void
    {
        DB::listen(fn () => throw new RuntimeException('Any SQL is forbidden in this discovery index canary.'));
        foreach (['/sitemap.xml','/robots.txt'] as $path) {
            $response = $this->withHeaders(['Host'=>'attacker.example','X-Forwarded-Host'=>'private-host.example','Authorization'=>'Bearer PRIVATE_CUSTOMER','X-Inertia'=>'true'])->withCookie('customer_session','PRIVATE_COOKIE')->get($path)->assertOk();
            $response->assertSee('https://canonical.example.test:8443/',false);
            foreach (['attacker','private-host','PRIVATE_CUSTOMER','PRIVATE_COOKIE'] as $private) { $response->assertDontSee($private,false); }
            $response->assertHeaderMissing('X-Inertia');
            $this->assertSame([], $response->headers->getCookies());
        }
        foreach (['discovery.index','discovery.robots'] as $name) {
            $route=app('router')->getRoutes()->getByName($name);
            foreach (app('router')->gatherRouteMiddleware($route) as $middleware) {
                $this->assertStringNotContainsString('Session', $middleware);
                $this->assertStringNotContainsString('HandleInertia', $middleware);
                $this->assertStringNotContainsString('Authenticate', $middleware);
            }
        }
    }
    public function test_canonical_origin_cannot_inject_xml_or_robots_directives(): void
    {
        foreach (["https://safe.example.test\r\nSitemap: https://private.example.test",'https://safe.example.test%0aDisallow:','https://safe.example.test/<loc>private</loc>','https://safe.example.test?private=SECRET','https://safe.example.test/#private',null,42,['private'=>'marker']] as $origin) {
            config(['app.url'=>$origin]);
            foreach (['/sitemap.xml','/robots.txt'] as $path) { $this->get($path)->assertStatus(503)->assertDontSee('private.example.test',false)->assertDontSee('SECRET',false); }
        }
    }
    public function test_extracted_origin_matches_the_prior_public_page_url_contract(): void
    {
        foreach (['https://canonical.example.test','https://canonical.example.test/','http://canonical.example.test:8080/'] as $origin) {
            config(['app.url'=>$origin]);
            $this->assertSame(rtrim($origin,'/'), app(PublicPagesSitemap::class)->canonicalOrigin());
            $this->get('/sitemap.xml')->assertOk()->assertSee('<loc>'.rtrim($origin,'/').'/site-pages-sitemap.xml</loc>',false);
            $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: '.rtrim($origin,'/').'/sitemap.xml',false);
        }
    }
}
