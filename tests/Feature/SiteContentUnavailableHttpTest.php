<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Filament\Resources\SiteReleaseResource\Pages\ListSiteReleases;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class SiteContentUnavailableHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.debug' => false, 'app.url' => 'https://audio.example.test']);
    }

    /** Point the site at a retained release whose stored copy no longer matches its hash, as a bad restore would. */
    private function corruptActiveRelease(User $actor): int
    {
        $bad = SiteContentSchema::defaults();
        $bad['hero']['title'] = 'CORRUPTED';
        $hash = CanonicalJson::hash(SiteContentSchema::defaults());
        $id = DB::table('site_releases')->insertGetId([
            'label' => 'Synthetic corrupt restoration', 'schema_version' => 1, 'content' => json_encode($bad),
            'content_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION,
            'created_by' => $actor->id, 'created_at' => now(),
        ]);
        DB::table('site_publication_revisions')->insert(['revision' => 1, 'release_id' => $id, 'previous_release_id' => null,
            'operation' => 'publish', 'content_hash' => $hash, 'actor_id' => $actor->id, 'created_at' => now()]);
        DB::table('site_publications')->where('id', 1)->update(['revision' => 1, 'active_release_id' => $id, 'updated_at' => now()]);

        return $id;
    }

    private function publishedTrack(User $operator): Track
    {
        MediaFixtures::configure();
        $track = Track::create(['title' => 'Synthetic unavailable fixture', 'slug' => 'synthetic-unavailable', 'artist' => 'Test', 'bpm' => 90,
            'musical_key' => 'C minor', 'genre' => 'Test', 'duration_seconds' => 120, 'waveform' => [0.2, 0.5, 0.8]]);
        RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'TEST-ONLY', 'sample_disclosure' => 'Synthetic test only',
            'status' => 'verified', 'verified_by' => $operator->id, 'verified_at' => now()]);
        $master = MediaFixtures::readyTrackMedia($track, $operator)['master_wav'];
        $license = LicenseFixtures::published($operator, LicenseFixtures::admin());
        $offer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id, 'license_version_id' => $license->id,
            'price_minor' => 4999, 'currency' => 'USD', 'deliverable_asset_ids' => [$master->id]], $operator);
        app(PublishOffer::class)->handle($offer, $operator);
        app(PublishTrack::class)->handle($track, $operator);

        return $track;
    }

    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        ];
    }

    private function assertUnavailable(TestResponse $response): void
    {
        $response->assertStatus(503)->assertHeaderMissing('Location')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Retry-After', '60');
        foreach (['integrity', 'CORRUPTED', 'publication', 'Synthetic corrupt restoration'] as $private) {
            $this->assertStringNotContainsString($private, (string) $response->getContent());
        }
    }

    public function test_public_pages_fail_closed_with_a_generic_503_instead_of_redirecting(): void
    {
        $operator = LicenseFixtures::admin();
        $track = $this->publishedTrack($operator);
        $this->get('/tracks/'.$track->slug)->assertOk();
        $corrupt = $this->corruptActiveRelease($operator);
        Log::spy();

        foreach (['/', '/?q=Synthetic', '/tracks/'.$track->slug, '/about', '/contact', '/blog', '/videos'] as $path) {
            $this->assertUnavailable($this->get($path));
            // A referring page or an Inertia visit must not turn the failure into a redirect either.
            $this->assertUnavailable($this->get($path, ['Referer' => 'https://elsewhere.example/page']));
            $this->assertUnavailable($this->get($path, $this->inertiaHeaders()));
        }
        // Every request fails closed; the operator log records the outage once a minute rather than once a request.
        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context) => $message === 'Published site content is unavailable.'
            && $context === ['reason' => 'integrity', 'revision' => 1, 'release_id' => $corrupt]);
    }

    public function test_staff_can_recover_by_publishing_an_intact_release(): void
    {
        $actor = LicenseFixtures::admin();
        $this->corruptActiveRelease($actor);
        $this->assertUnavailable($this->get('/'));
        $content = SiteContentSchema::defaults();
        $content['hero']['title'] = 'SYNTHETIC RECOVERY';
        $release = app(SiteContent::class)->create($content, 'Synthetic recovery', $actor);
        app(SiteContent::class)->publish($release->id, 1, $actor);

        $this->get('/')->assertOk()->assertSee('SYNTHETIC RECOVERY');
        $this->assertSame($release->id, SitePublication::findOrFail(1)->active_release_id);
    }

    public function test_new_content_draft_reports_unavailable_published_content_instead_of_opening(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $this->corruptActiveRelease($actor);

        Livewire::test(ListSiteReleases::class)->mountAction('createDraft')
            ->assertNotified('The published site content failed its integrity check')
            ->assertSet('mountedActions', []);
        $this->assertDatabaseCount('site_releases', 1);
    }
}
