<?php
namespace Tests\Feature;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Support\CanonicalJson;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

final class SitemapIndependentCanaryTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'app.url' => 'https://canonical.example.test:8443/']);
        $this->app->instance('env', 'production');
    }
    private function release(string $slug): array
    {
        $actor = LicenseFixtures::admin();
        $content = SiteEditorialFixtures::content('PRIVATE TEXT '.$slug);
        $content['blog']['entries'] = [['slug' => $slug] + $content['blog']['entries'][0]];
        return [app(SiteContent::class)->create($content, 'PRIVATE LABEL '.$slug, $actor), $actor];
    }
    public function test_forwarded_host_identity_headers_and_cookies_cannot_change_public_projection(): void
    {
        [$release, $actor] = $this->release('public-live');
        app(SiteContent::class)->publish($release->id, 0, $actor);
        [$draft] = $this->release('private-draft');
        $response = $this->withHeaders(['Host'=>'attacker.example','X-Forwarded-Host'=>'forwarded.example','X-Forwarded-Proto'=>'http','Authorization'=>'Bearer private-token','X-Inertia'=>'true'])->withCookie('laravel_session','private-customer-session')->get('/site-pages-sitemap.xml')->assertOk();
        $response->assertSee('<loc>https://canonical.example.test:8443/blog/public-live</loc>',false);
        foreach (['attacker','forwarded','private-token','private-customer-session','private-draft','PRIVATE TEXT','PRIVATE LABEL',$draft->content_hash,$release->content_hash,(string)$actor->email] as $private) { $response->assertDontSee($private,false); }
        $this->assertSame([], $response->headers->getCookies());
        $response->assertHeaderMissing('X-Inertia');
        $route = app('router')->getRoutes()->getByName('discovery.site-pages');
        $this->assertNotContains('web', app('router')->gatherRouteMiddleware($route));
    }
    public function test_pointer_movement_keeps_one_coherent_release_and_next_request_observes_replacement(): void
    {
        [$old, $actor] = $this->release('old-public');
        app(SiteContent::class)->publish($old->id, 0, $actor);
        [$next] = $this->release('new-public');
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed, $next, $actor): void {
            if ($armed && str_starts_with($query->sql, 'select * from "site_publications"')) {
                $armed = false;
                app(SiteContent::class)->publish($next->id, 1, $actor);
            }
        });
        $this->get('/site-pages-sitemap.xml')->assertOk()->assertSee('/blog/old-public',false)->assertDontSee('/blog/new-public',false);
        $this->assertFalse($armed, 'Actual publication pointer query interception must execute.');
        $this->get('/site-pages-sitemap.xml')->assertOk()->assertSee('/blog/new-public',false)->assertDontSee('/blog/old-public',false);
    }
    public function test_nonproduction_published_release_stays_empty_and_cookie_free(): void
    {
        [$release, $actor] = $this->release('production-only');
        app(SiteContent::class)->publish($release->id, 0, $actor);
        $this->app->instance('env', 'staging');
        $response = $this->get('/site-pages-sitemap.xml')->assertOk()->assertDontSee('<loc>',false)->assertHeader('X-Robots-Tag','noindex, nofollow');
        $this->assertSame([], $response->headers->getCookies());
    }
    public function test_nonempty_get_body_is_rejected_without_echoing_private_payload(): void
    {
        $this->call('GET','/site-pages-sitemap.xml',[],[],[],['CONTENT_TYPE'=>'application/json'],'{"customer":"private-marker"}')->assertNotFound()->assertDontSee('private-marker',false);
    }
    public function test_corrupt_retained_release_fails_closed_without_private_content_or_fallback_urls(): void
    {
        $actor = LicenseFixtures::admin();
        $content = SiteContentSchema::defaults();
        $hash = CanonicalJson::hash($content);
        $content['hero']['title'] = 'PRIVATE CORRUPT RESTORE';
        $id = DB::table('site_releases')->insertGetId(['label'=>'PRIVATE RETAINED LABEL','schema_version'=>1,'content'=>json_encode($content),'content_hash'=>$hash,'canonicalization_version'=>CanonicalJson::VERSION,'created_by'=>$actor->id,'created_at'=>now()]);
        DB::table('site_publication_revisions')->insert(['revision'=>1,'release_id'=>$id,'previous_release_id'=>null,'operation'=>'publish','content_hash'=>$hash,'actor_id'=>$actor->id,'created_at'=>now()]);
        DB::table('site_publications')->where('id',1)->update(['revision'=>1,'active_release_id'=>$id,'updated_at'=>now()]);
        $response = $this->get('/site-pages-sitemap.xml')->assertStatus(503)->assertHeader('Cache-Control','no-store, private');
        foreach (['PRIVATE CORRUPT RESTORE','PRIVATE RETAINED LABEL',$hash,'<loc>'] as $private) { $response->assertDontSee($private,false); }
        $this->assertSame([], $response->headers->getCookies());
    }
}
