<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

class SiteEditorialHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.debug' => false, 'app.url' => 'https://audio.example.test']);
    }

    private function headers(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }

    private function draft(string $marker, ?User $actor = null): SiteRelease
    {
        return app(SiteContent::class)->create(SiteEditorialFixtures::content($marker), $marker.' PRIVATE LABEL', $actor ?? LicenseFixtures::admin());
    }

    private function preview(SiteRelease $release, string $path = ''): string
    {
        return '/admin/site-releases/'.$release->id.'/preview'.$path;
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $vary = strtolower(implode(', ', $response->headers->all('Vary')));
        $this->assertStringContainsString('cookie', $vary);
        $this->assertStringContainsString('x-inertia', $vary);
    }

    private function paths(): array
    {
        return ['/about', '/contact', '/blog', '/blog/first-note', '/videos', '/videos/first-film', '/videos/second-film'];
    }

    public function test_editorial_pages_are_absent_until_their_release_is_active_and_public_query_parameters_cannot_select_drafts(): void
    {
        $draft = $this->draft('UNPUBLISHED EDITORIAL');
        foreach ($this->paths() as $path) {
            foreach (['', '?release='.$draft->id.'&preview='.$draft->id.'&site_release_id='.$draft->id] as $query) {
                $this->get($path.$query)->assertNotFound()->assertDontSee('UNPUBLISHED EDITORIAL', false);
                $this->get($path.$query, $this->headers())->assertNotFound()->assertDontSee('UNPUBLISHED EDITORIAL', false);
            }
        }
        foreach (['/', '/?release='.$draft->id, '/api/catalog'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('UNPUBLISHED EDITORIAL', false)
                ->assertDontSee($draft->content_hash, false)->assertDontSee($draft->label, false);
        }
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertDatabaseCount('site_publication_revisions', 0);
    }

    public function test_private_previews_render_exact_selected_editorial_content_with_safe_provider_links_and_no_mutation(): void
    {
        $actor = LicenseFixtures::admin();
        $active = $this->draft('ACTIVE EDITORIAL', $actor);
        app(SiteContent::class)->publish($active->id, 0, $actor);
        $draft = $this->draft('PRIVATE EDITORIAL', $actor);
        $audits = AuditEvent::count();
        $this->actingAs($actor);
        foreach ($this->paths() as $path) {
            $response = $this->get($this->preview($draft, $path), $this->headers())->assertOk();
            $this->assertPrivate($response);
            $response->assertJsonPath('props.sitePreview', true)
                ->assertJsonPath('props.sitePreviewBase', $this->preview($draft))
                ->assertJsonPath('props.editorial.path', $path)
                ->assertJsonPath('props.commerceEnabled', false)
                ->assertJsonPath('props.testOrderPreparationEnabled', false)
                ->assertJsonPath('props.testCheckoutEnabled', false)
                ->assertJsonPath('props.metadata.canonicalUrl', 'https://audio.example.test'.$path)
                ->assertJsonPath('props.metadata.robots', 'noindex, nofollow')
                ->assertDontSee($draft->content_hash, false)->assertDontSee($draft->label, false)
                ->assertDontSee('ACTIVE EDITORIAL', false);
            $this->assertStringContainsString('PRIVATE EDITORIAL', $response->json('props.editorial.title'));
            $this->assertArrayNotHasKey('blog', $response->json('props.siteContent'));
            $this->assertArrayNotHasKey('videos', $response->json('props.siteContent'));
        }
        $contact = $this->get($this->preview($draft, '/contact'), $this->headers())->assertOk();
        $contact->assertJsonPath('props.editorial.email', 'editorial+synthetic@example.test')
            ->assertJsonPath('props.editorial.contactHref', 'mailto:editorial%2Bsynthetic%40example.test');
        $this->get($this->preview($draft, '/videos/first-film'), $this->headers())->assertOk()
            ->assertJsonPath('props.editorial.video', ['provider' => 'youtube', 'videoId' => 'AbCdEfGhI_1', 'watchUrl' => 'https://www.youtube.com/watch?v=AbCdEfGhI_1']);
        $this->get($this->preview($draft, '/videos/second-film'), $this->headers())->assertOk()
            ->assertJsonPath('props.editorial.video', ['provider' => 'vimeo', 'videoId' => '123456789', 'watchUrl' => 'https://vimeo.com/123456789']);
        $this->assertSame(1, SitePublication::findOrFail(1)->revision);
        $this->assertDatabaseCount('site_publication_revisions', 2);
        $this->assertSame($audits, AuditEvent::count());
    }

    public function test_public_routes_project_only_the_selected_page_and_server_owned_social_identity(): void
    {
        $actor = LicenseFixtures::admin();
        $release = $this->draft('PUBLIC EDITORIAL', $actor);
        $private = $this->draft('DO NOT DISCLOSE', $actor);
        app(SiteContent::class)->publish($release->id, 0, $actor);
        foreach ($this->paths() as $path) {
            $response = $this->get($path.'?release='.$private->id.'&utm_source=synthetic', $this->headers())->assertOk();
            $response->assertJsonPath('props.sitePreview', false)
                ->assertJsonPath('props.sitePreviewBase', null)
                ->assertJsonPath('props.editorial.path', $path)
                ->assertJsonPath('props.commerceEnabled', false)
                ->assertJsonPath('props.testOrderPreparationEnabled', false)
                ->assertJsonPath('props.testCheckoutEnabled', false)
                ->assertJsonPath('props.metadata.canonicalUrl', 'https://audio.example.test'.$path)
                ->assertJsonPath('props.metadata.imageUrl', 'https://audio.example.test/images/storefront-hero.jpg')
                ->assertDontSee('DO NOT DISCLOSE', false)
                ->assertDontSee($release->content_hash, false)->assertDontSee($release->label, false);
            $this->assertSame($response->json('props.editorial.description'), $response->json('props.metadata.description'));
            $this->assertSame($response->json('props.editorial.title').' — VASEY.AUDIO', $response->json('props.metadata.title'));
            foreach (['about', 'contact', 'blog', 'videos'] as $section) {
                $this->assertArrayNotHasKey($section, $response->json('props.siteContent'));
            }
            $html = $this->get($path)->assertOk();
            $html->assertSee('rel="canonical" href="https://audio.example.test'.$path.'"', false);
        }
        $post = $this->get('/blog/first-note', $this->headers())->assertOk();
        $post->assertJsonPath('props.editorial.kind', 'entry')
            ->assertJsonPath('props.editorial.paragraphs', ['PUBLIC EDITORIAL FIRST BODY'])
            ->assertDontSee('PUBLIC EDITORIAL SECOND BODY', false)
            ->assertDontSee('PUBLIC EDITORIAL CONTACT BODY', false)
            ->assertDontSee('editorial+synthetic@example.test', false);
        $this->get('/blog', $this->headers())->assertOk()->assertJsonPath('props.editorial.kind', 'collection')
            ->assertJsonCount(2, 'props.editorial.entries')->assertDontSee('PUBLIC EDITORIAL FIRST BODY', false);
        $this->get('/', $this->headers())->assertOk()->assertJsonPath('props.siteContent.hero.title', 'PUBLIC EDITORIAL HOME')
            ->assertDontSee('PUBLIC EDITORIAL FIRST BODY', false)->assertDontSee('PUBLIC EDITORIAL CONTACT BODY', false);
        $this->get('/api/catalog')->assertOk()->assertDontSee('PUBLIC EDITORIAL', false);
        $spoofed = $this->withServerVariables(['HTTP_HOST' => 'untrusted.example.test'])->get('/about?canonical=https://untrusted.example.test', $this->headers())->assertOk();
        $spoofed->assertJsonPath('props.metadata.canonicalUrl', 'https://audio.example.test/about');
    }

    public function test_publication_replacement_and_cross_version_rollback_control_all_routes_without_historical_slug_fallback(): void
    {
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $legacy = $site->create(SiteEditorialFixtures::legacy(), 'Retained legacy release', $actor);
        $first = $this->draft('FIRST EDITORIAL', $actor);
        $content = SiteEditorialFixtures::content('SECOND EDITORIAL');
        $content['contact'] = null;
        $content['navigation'] = array_values(array_filter($content['navigation'], fn (array $link): bool => $link['href'] !== '/contact'));
        $content['blog']['entries'][0]['slug'] = 'replacement-note';
        $content['videos']['entries'][0]['slug'] = 'replacement-film';
        $second = $site->create($content, 'Replacement release', $actor);
        $site->publish($legacy->id, 0, $actor);
        $site->publish($first->id, 1, $actor);
        foreach ($this->paths() as $path) { $this->get($path, $this->headers())->assertOk()->assertSee('FIRST EDITORIAL', false); }
        $site->publish($second->id, 2, $actor);
        foreach (['/contact', '/blog/first-note', '/videos/first-film'] as $path) {
            $this->get($path.'?release='.$first->id, $this->headers())->assertNotFound()->assertDontSee('FIRST EDITORIAL', false);
        }
        $this->get('/blog/replacement-note', $this->headers())->assertOk()->assertSee('SECOND EDITORIAL FIRST BODY', false);
        $this->get('/videos/replacement-film', $this->headers())->assertOk();
        $site->rollback($first->id, 3, $actor);
        foreach ($this->paths() as $path) { $this->get($path, $this->headers())->assertOk()->assertSee('FIRST EDITORIAL', false)->assertDontSee('SECOND EDITORIAL', false); }
        $this->get('/blog/replacement-note')->assertNotFound();
        $site->rollback($legacy->id, 4, $actor);
        foreach ($this->paths() as $path) { $this->get($path, $this->headers())->assertNotFound()->assertDontSee('FIRST EDITORIAL', false); }
        $this->assertSame(SiteEditorialFixtures::legacy(), $site->current());
        $this->assertSame(5, SitePublication::findOrFail(1)->revision);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_every_preview_denial_and_missing_target_retains_private_headers_without_content_leaks(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = $this->draft('PRIVATE DENIAL', $actor);
        $url = $this->preview($draft, '/blog/first-note');
        $guest = $this->get($url)->assertRedirect('/admin/login')->assertDontSee('PRIVATE DENIAL', false);
        $this->assertPrivate($guest);
        $customer = $this->actingAs(User::factory()->create())->get($url)->assertForbidden()->assertDontSee('PRIVATE DENIAL', false);
        $this->assertPrivate($customer);
        $this->actingAs($actor);
        foreach ([$this->preview($draft, '/blog/missing-post'), '/admin/site-releases/999999/preview/about', $this->preview($draft, '/unknown')] as $missing) {
            $response = $this->get($missing)->assertNotFound()->assertDontSee('PRIVATE DENIAL', false);
            $this->assertPrivate($response);
        }
        $legacy = app(SiteContent::class)->create(SiteEditorialFixtures::legacy(), 'Legacy private target', $actor);
        $this->assertPrivate($this->get($this->preview($legacy, '/about'))->assertNotFound());
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
    }

    public static function withdrawnAuthority(): array
    {
        return ['role' => ['is_admin', false], 'verified email' => ['email_verified_at', null]];
    }

    #[DataProvider('withdrawnAuthority')]
    public function test_nested_preview_rechecks_persisted_authority_for_stale_authenticated_staff(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $draft = $this->draft('WITHDRAWN EDITORIAL', $actor);
        $this->actingAs($actor);
        User::whereKey($actor->id)->update([$field => $value]);
        $this->assertTrue($actor->is_admin);
        $response = $this->get($this->preview($draft, '/videos/first-film'))->assertForbidden()->assertDontSee('WITHDRAWN EDITORIAL', false);
        $this->assertPrivate($response);
        $this->assertDatabaseCount('site_publication_revisions', 0);
    }

    public function test_nested_preview_obeys_mfa_enrollment_and_keeps_redirect_private(): void
    {
        $draft = $this->draft('MFA EDITORIAL');
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        Route::get('/admin/synthetic-editorial-mfa', fn () => 'Synthetic setup destination')->name('filament.admin.auth.multi-factor-authentication.set-up-required');
        Route::getRoutes()->refreshNameLookups();
        $url = $this->preview($draft, '/contact');
        Route::getRoutes()->match(Request::create($url))->middleware(Dashboard::getRouteMiddleware($panel));
        $response = $this->actingAs(LicenseFixtures::admin())->get($url)->assertRedirect('/admin/synthetic-editorial-mfa')->assertDontSee('MFA EDITORIAL', false);
        $this->assertPrivate($response);
    }

    public function test_nested_preview_throttle_keeps_generic_private_response_and_retry_header(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = $this->draft('THROTTLED PRIVATE', $actor);
        $this->actingAs($actor);
        $url = $this->preview($draft, '/about');
        for ($request = 0; $request < 60; $request++) {
            $this->get($url, $this->headers())->assertOk();
        }
        $response = $this->get($url, $this->headers())->assertStatus(429)->assertHeader('Retry-After')
            ->assertDontSee('THROTTLED PRIVATE', false);
        $this->assertPrivate($response);
        $this->assertDatabaseCount('site_publication_revisions', 0);
    }

    public function test_preview_database_failure_is_generic_private_service_unavailable_in_debug_mode(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = $this->draft('DATABASE PRIVATE', $actor);
        $this->actingAs($actor);
        config(['app.debug' => true]);
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_contains($query, 'site_releases')) {
                throw new \RuntimeException('SYNTHETIC SENSITIVE DATABASE DETAIL');
            }
        });
        $response = $this->get($this->preview($draft, '/about'))->assertStatus(503)
            ->assertDontSee('DATABASE PRIVATE', false)->assertDontSee('SYNTHETIC SENSITIVE DATABASE DETAIL', false)
            ->assertDontSee(base_path(), false);
        $this->assertPrivate($response);
    }

    public function test_corrupt_preview_is_private_and_generic_even_when_debug_is_enabled(): void
    {
        $actor = LicenseFixtures::admin();
        $content = SiteEditorialFixtures::content('CORRUPT SECRET EDITORIAL');
        $id = DB::table('site_releases')->insertGetId([
            'label' => 'CORRUPT PRIVATE LABEL', 'schema_version' => 2, 'content' => json_encode($content, JSON_THROW_ON_ERROR),
            'content_hash' => str_repeat('f', 64), 'canonicalization_version' => CanonicalJson::VERSION,
            'created_by' => $actor->id, 'created_at' => now(),
        ]);
        config(['app.debug' => true]);
        $response = $this->actingAs($actor)->get('/admin/site-releases/'.$id.'/preview/blog/first-note');
        $this->assertContains($response->status(), [422, 503]);
        $this->assertPrivate($response);
        $response->assertDontSee('CORRUPT SECRET EDITORIAL', false)->assertDontSee('CORRUPT PRIVATE LABEL', false)
            ->assertDontSee(base_path(), false)->assertDontSee('SiteContent.php', false)->assertDontSee('stack', false);
        $this->assertDatabaseCount('site_publication_revisions', 0);
    }
}
