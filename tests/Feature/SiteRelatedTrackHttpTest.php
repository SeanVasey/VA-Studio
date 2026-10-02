<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\SiteContent;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\SiteRelatedTrackFixtures as F;
use Tests\TestCase;

class SiteRelatedTrackHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        config(['app.debug' => false]);
    }

    private function headers(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }

    public function test_only_selected_detail_receives_ordered_current_summaries_without_catalog_payloads(): void
    {
        $first = QuoteFixtures::selection();
        $second = QuoteFixtures::selection();
        $first['track']->update(['title' => 'Current first track']);
        $second['track']->update(['title' => 'Current second track']);
        $site = app(SiteContent::class);
        $release = $site->create(F::content([$second['track']->id, $first['track']->id], [$first['track']->id]), 'Private editorial label', $first['actor']);
        $site->publish($release->id, 0, $first['actor']);
        $audits = AuditEvent::count();
        $expected = array_map(fn (array $fixture): array => ['title' => $fixture['track']->title, 'artist' => $fixture['track']->artist, 'href' => '/tracks/'.$fixture['track']->slug], [$second, $first]);
        $response = $this->get('/blog/first-note', $this->headers())->assertOk()->assertJsonPath('props.editorial.relatedTracks', $expected);
        foreach (['related_track_ids', 'previewUrl', 'artworkUrl', 'offers', 'priceMinor', 'licenseVersionId', 'TEST-ONLY-QUOTE-RIGHTS', $release->content_hash, $release->label] as $private) {
            $response->assertDontSee($private, false);
        }
        foreach (['/blog', '/videos', '/blog/second-note', '/about', '/contact'] as $path) {
            $other = $this->get($path, $this->headers())->assertOk();
            $this->assertEmpty($other->json('props.editorial.relatedTracks'));
            $other->assertDontSee('Current first track', false)->assertDontSee('Current second track', false);
        }
        $this->get('/videos/first-film', $this->headers())->assertOk()->assertJsonPath('props.editorial.relatedTracks', [$expected[1]]);
        $this->assertSame($audits, AuditEvent::count());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_private_preview_omits_destinations_server_side_and_rechecks_staff_without_mutation(): void
    {
        $fixture = QuoteFixtures::selection();
        $site = app(SiteContent::class);
        $release = $site->create(F::content([$fixture['track']->id]), 'Private related draft', $fixture['actor']);
        $url = '/admin/site-releases/'.$release->id.'/preview/blog/first-note';
        $audits = AuditEvent::count();
        $response = $this->actingAs($fixture['actor'])->get($url, $this->headers())->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertJsonPath('props.sitePreview', true)
            ->assertJsonPath('props.commerceEnabled', false)->assertJsonPath('props.editorial.relatedTracks', [
                ['title' => $fixture['track']->title, 'artist' => $fixture['track']->artist],
            ])->assertDontSee('/tracks/'.$fixture['track']->slug, false);
        $this->assertArrayNotHasKey('href', $response->json('props.editorial.relatedTracks.0'));
        $this->assertSame($audits, AuditEvent::count());
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        User::whereKey($fixture['actor']->id)->update(['is_admin' => false]);
        $this->get($url, $this->headers())->assertForbidden()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee($fixture['track']->title, false);
    }

    public function test_fresh_requests_omit_withdrawn_tracks_but_keep_pinned_text_order_and_reserved_urls(): void
    {
        $first = QuoteFixtures::selection();
        $second = QuoteFixtures::selection();
        $site = app(SiteContent::class);
        $content = F::content([$second['track']->id, $first['track']->id]);
        $release = $site->create($content, 'Withdrawal safe', $first['actor']);
        $site->publish($release->id, 0, $first['actor']);
        $track = $first['track'];
        $metadata = $track->only(['title', 'slug', 'artist', 'bpm', 'musical_key', 'genre', 'mood', 'tags', 'description', 'metadata_version']);
        $metadata['title'] = 'Updated current title';
        app(SaveTrackMetadata::class)->handle($track, $metadata, $first['actor']);
        $this->get('/blog/first-note', $this->headers())->assertOk()->assertJsonPath('props.editorial.relatedTracks.1.title', 'Updated current title')
            ->assertJsonPath('props.editorial.relatedTracks.1.href', '/tracks/'.$track->published_slug);
        app(PublishTrack::class)->unpublish($second['track'], $second['actor']);
        $this->get('/blog/first-note', $this->headers())->assertOk()->assertJsonCount(1, 'props.editorial.relatedTracks')->assertJsonPath('props.editorial.relatedTracks.0.title', 'Updated current title');
        app(PublishTrack::class)->unpublish($track->fresh(), $first['actor']);
        $this->get('/blog/first-note', $this->headers())->assertOk()->assertJsonPath('props.editorial.relatedTracks', []);
        $this->assertSame($content, $release->fresh()->content);
        $copy = $site->create($site->preview($release->id, $first['actor']), 'Retained withdrawn copy', $first['actor']);
        $site->publish($copy->id, 1, $first['actor']);
        $site->rollback($release->id, 2, $first['actor']);
        $this->get('/blog/first-note', $this->headers())->assertOk()->assertJsonPath('props.editorial.title', $content['blog']['entries'][0]['title'])->assertJsonPath('props.editorial.relatedTracks', []);
    }

    public function test_private_v4_preview_obeys_required_mfa_and_keeps_the_redirect_private(): void
    {
        $actor = LicenseFixtures::admin();
        $release = app(SiteContent::class)->create(F::content(), 'MFA private v4', $actor);
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        Route::get('/admin/synthetic-related-mfa', fn () => 'Synthetic setup')->name('filament.admin.auth.multi-factor-authentication.set-up-required');
        Route::getRoutes()->refreshNameLookups();
        $url = '/admin/site-releases/'.$release->id.'/preview/blog/first-note';
        Route::getRoutes()->match(Request::create($url))->middleware(Dashboard::getRouteMiddleware($panel));
        $this->actingAs(LicenseFixtures::admin())->get($url)->assertRedirect('/admin/synthetic-related-mfa')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertDontSee('SYNTHETIC RELATED', false);
    }
}
