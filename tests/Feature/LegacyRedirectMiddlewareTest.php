<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\SiteBuilder\SiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\LegacyRedirectFixtures as F;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

class LegacyRedirectMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.url' => 'https://audio.example.test', 'app.debug' => false]);
        $this->directory = sys_get_temp_dir().'/legacy-redirect-http-'.Str::uuid();
    }

    protected function tearDown(): void
    {
        F::remove($this->directory);
        parent::tearDown();
    }

    private function activate(array $entries, array $hosts = [], bool $enabled = true): void
    {
        $files = F::write($this->directory, $entries, $hosts);
        config(['legacy-redirects.enabled' => $enabled, 'legacy-redirects.map_path' => $files['map']]);
    }

    public function test_the_shipped_flag_is_the_literal_false_and_an_off_flag_serves_no_redirect(): void
    {
        $this->assertFalse(config('legacy-redirects.enabled'));
        $this->assertStringContainsString("'enabled' => false,", file_get_contents(config_path('legacy-redirects.php')));
        $this->activate([F::redirect('URL-001', '/legacy-fixture/catalog', ['type' => 'route', 'name' => 'home']), F::gone('URL-002', '/legacy-fixture/retired')], [], false);
        $this->get('/legacy-fixture/catalog')->assertNotFound()->assertHeaderMissing('Location');
        $this->get('/legacy-fixture/retired')->assertNotFound();
        // Only the literal boolean true activates: truthy strings and integers do not.
        foreach (['true', 1, '1', 'yes'] as $value) {
            config(['legacy-redirects.enabled' => $value]);
            $this->get('/legacy-fixture/catalog')->assertNotFound()->assertHeaderMissing('Location');
        }
    }

    public function test_an_unmatched_exact_source_answers_one_hop_301_to_the_canonical_origin_dropping_the_query(): void
    {
        $this->activate([F::redirect('URL-001', '/legacy-fixture/catalog', ['type' => 'route', 'name' => 'home'])]);
        $response = $this->get('/legacy-fixture/catalog?utm_source=old&cursor=abc')->assertStatus(301)
            ->assertHeader('Location', 'https://audio.example.test/')->assertHeader('Cache-Control', 'max-age=3600, public');
        $this->assertSame([], $response->headers->getCookies());
        $this->call('HEAD', '/legacy-fixture/catalog')->assertStatus(301)->assertHeader('Location', 'https://audio.example.test/');
        // A trailing slash is the same path; case and longer paths are not.
        $this->get('/legacy-fixture/catalog/')->assertStatus(301);
        $this->get('/Legacy-Fixture/Catalog')->assertNotFound();
        $this->get('/legacy-fixture/catalog/extra')->assertNotFound();
        $this->get('/legacy-fixture')->assertNotFound();
        // Request Host never supplies the redirect origin.
        $this->get('https://attacker.example/legacy-fixture/catalog')->assertStatus(301)->assertHeader('Location', 'https://audio.example.test/');
        // Other methods are never redirected.
        $this->post('/legacy-fixture/catalog')->assertNotFound();
        $this->call('PUT', '/legacy-fixture/catalog')->assertNotFound();
    }

    public function test_retired_content_answers_410_and_unresolved_or_retained_rows_change_nothing(): void
    {
        $this->activate([
            F::gone('URL-001', '/legacy-fixture/retired'),
            F::unresolved('URL-002', '/legacy-fixture/unknown'),
            F::retained('URL-003', '/'),
        ]);
        $this->get('/legacy-fixture/retired?x=1')->assertStatus(410)->assertHeaderMissing('Location');
        $this->get('/legacy-fixture/unknown')->assertNotFound()->assertHeaderMissing('Location');
        $this->get('/')->assertOk();
    }

    public function test_a_path_any_real_route_handles_is_never_redirected_even_when_that_route_answers_404(): void
    {
        // Validation refuses these entries; the request path re-checks anyway.
        $this->activate([
            F::redirect('URL-001', '/about', ['type' => 'route', 'name' => 'home']),
            F::gone('URL-002', '/tracks/missing-track'),
            F::redirect('URL-003', '/checkout', ['type' => 'route', 'name' => 'home']),
        ]);
        // The default release has no about page: the editorial route itself answers 404.
        $this->get('/about')->assertNotFound()->assertHeaderMissing('Location');
        $this->get('/tracks/missing-track')->assertNotFound();
        // POST-only route: a GET is a 405 from routing, not a redirect.
        $this->get('/checkout')->assertStatus(405)->assertHeaderMissing('Location');
    }

    public function test_content_targets_redirect_only_while_public_and_never_reveal_drafts_or_withdrawn_tracks(): void
    {
        $draft = Track::create(['title' => 'Private draft name', 'artist' => 'Private artist', 'slug' => 'private-draft-target']);
        $selection = QuoteFixtures::selection();
        $slug = $selection['track']->slug;
        $this->activate([
            F::redirect('URL-001', '/legacy-fixture/draft', ['type' => 'track', 'slug' => $draft->slug]),
            F::redirect('URL-002', '/legacy-fixture/published', ['type' => 'track', 'slug' => $slug]),
            F::redirect('URL-003', '/legacy-fixture/missing', ['type' => 'track', 'slug' => 'never-existed']),
            F::redirect('URL-004', '/legacy-fixture/blog-entry', ['type' => 'editorial', 'section' => 'blog', 'slug' => 'first-note']),
        ]);
        $this->get('/legacy-fixture/draft')->assertNotFound()->assertHeaderMissing('Location')->assertDontSee($draft->slug)->assertDontSee($draft->title);
        $this->get('/legacy-fixture/missing')->assertNotFound()->assertHeaderMissing('Location');
        $this->get('/legacy-fixture/published')->assertStatus(301)->assertHeader('Location', 'https://audio.example.test/tracks/'.$slug);
        app(PublishTrack::class)->unpublish($selection['track'], $selection['actor']);
        $this->get('/legacy-fixture/published')->assertNotFound()->assertHeaderMissing('Location');

        // An editorial entry counts only once a release that contains it is published; a draft release is not enough.
        $this->get('/legacy-fixture/blog-entry')->assertNotFound()->assertHeaderMissing('Location');
        $actor = LicenseFixtures::admin();
        $service = app(SiteContent::class);
        $release = $service->create(SiteEditorialFixtures::content(), 'DRAFT', $actor);
        $this->get('/legacy-fixture/blog-entry')->assertNotFound()->assertHeaderMissing('Location');
        $service->publish($release->id, 0, $actor);
        $this->get('/legacy-fixture/blog-entry')->assertStatus(301)->assertHeader('Location', 'https://audio.example.test/blog/first-note');
    }

    public function test_external_targets_redirect_only_to_allowlisted_hosts_and_chains_never_act(): void
    {
        $this->activate([
            F::redirect('URL-001', '/legacy-fixture/kit', ['type' => 'external', 'url' => 'https://cdn.example.test/kit']),
            F::redirect('URL-002', '/legacy-fixture/phish', ['type' => 'external', 'url' => 'https://attacker.example/phish']),
            F::redirect('URL-003', '/legacy-fixture/first', ['type' => 'route', 'name' => 'home']),
        ], ['cdn.example.test']);
        $this->get('/legacy-fixture/kit')->assertStatus(301)->assertHeader('Location', 'https://cdn.example.test/kit');
        $this->get('/legacy-fixture/phish')->assertNotFound()->assertHeaderMissing('Location');

        $this->activate([
            F::redirect('URL-001', '/legacy-fixture/a', ['type' => 'track', 'slug' => 'chained']),
            F::gone('URL-002', '/tracks/chained'),
        ]);
        $this->get('/legacy-fixture/a')->assertNotFound()->assertHeaderMissing('Location');
    }

    public function test_an_invalid_or_missing_map_fails_closed_with_a_warning_and_no_redirect(): void
    {
        Log::spy();
        $this->activate([F::redirect('URL-001', '/legacy-fixture/catalog', ['type' => 'route', 'name' => 'home'])]);
        file_put_contents(config('legacy-redirects.map_path'), '{"schema":');
        $this->get('/legacy-fixture/catalog')->assertNotFound()->assertHeaderMissing('Location');
        config(['legacy-redirects.map_path' => $this->directory.'/absent.json']);
        $this->get('/legacy-fixture/catalog')->assertNotFound()->assertHeaderMissing('Location');
        Log::shouldHaveReceived('warning')->twice()->withArgs(fn (string $message) => str_contains($message, 'Legacy redirect map refused'));
    }
}
