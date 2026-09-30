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
use App\Domain\SiteBuilder\SiteContentUnavailable;
use App\Filament\Resources\SiteReleaseResource\Pages\ListSiteReleases;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Cache\ArrayStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Retry-After', '60')
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')->assertHeader('X-Robots-Tag', 'noindex');
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
            && $context === ['reason' => 'release', 'revision' => 1, 'release_id' => $corrupt]);
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

    public function test_a_failing_cache_does_not_silence_the_outage_report(): void
    {
        $actor = LicenseFixtures::admin();
        $corrupt = $this->corruptActiveRelease($actor);
        // The default store fails on add; rate limiting keeps its own working store.
        Cache::extend('failing-add', fn () => Cache::repository(new class extends ArrayStore
        {
            public function add($key, $value, $seconds)
            {
                throw new RuntimeException('Synthetic cache failure');
            }
        }));
        config(['cache.stores.failing-add' => ['driver' => 'failing-add'], 'cache.default' => 'failing-add', 'cache.limiter' => 'array']);
        Log::spy();

        $this->assertUnavailable($this->get('/'));
        $this->assertUnavailable($this->get('/about'));
        // Without the cache there is no once-a-minute limit, but each failure is still reported and still fails closed.
        Log::shouldHaveReceived('critical')->twice()->withArgs(fn (string $message, array $context) => $message === 'Published site content is unavailable.'
            && $context === ['reason' => 'release', 'revision' => 1, 'release_id' => $corrupt]);
    }

    public function test_new_content_draft_reports_unavailable_published_content_instead_of_opening(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $this->corruptActiveRelease($actor);

        $page = Livewire::test(ListSiteReleases::class)->mountAction('createDraft');
        $notification = collect(session('filament.claimed_notifications', session('filament.notifications', [])))
            ->firstWhere('title', 'The published site content is unavailable');
        $this->assertNotNull($notification);
        // A damaged release is recoverable in the panel, so staff are not sent to a needless backup restore.
        $this->assertStringContainsString('Publish or restore an intact release', (string) $notification['body']);
        $this->assertStringNotContainsString('backup', (string) $notification['body']);
        $page->assertNotified('The published site content is unavailable')->assertSet('mountedActions', []);
        $this->assertDatabaseCount('site_releases', 1);
    }

    public static function firstPublicationMoments(): array
    {
        return [
            'after the history read' => ['/from [`"]site_publication_revisions[`"]/', true],
            'after the pointer read' => ['/from [`"]site_publications[`"] where/', false],
        ];
    }

    #[DataProvider('firstPublicationMoments')]
    public function test_a_first_publication_committing_during_a_public_read_never_reports_an_outage(string $pattern, bool $showsPublication): void
    {
        $actor = LicenseFixtures::admin();
        $content = SiteContentSchema::defaults();
        $content['hero']['title'] = 'SYNTHETIC FIRST';
        $release = app(SiteContent::class)->create($content, 'Synthetic first publication', $actor);
        Log::spy();
        $done = false;
        DB::listen(function (QueryExecuted $query) use (&$done, $pattern, $release, $actor): void {
            if (! $done && preg_match($pattern, $query->sql) === 1 && ! str_contains($query->sql, 'for update')) {
                $done = true;
                app(SiteContent::class)->publish($release->id, 0, $actor);
            }
        });

        $response = $this->get('/')->assertOk();
        $this->assertTrue($done);
        // The site as it was before the publication or after it, never an outage.
        $this->assertSame($showsPublication, str_contains((string) $response->getContent(), 'SYNTHETIC FIRST'));
        Log::shouldNotHaveReceived('critical');
        $this->assertSame(1, SitePublication::findOrFail(1)->revision);
    }

    public function test_an_unavailable_exception_without_a_named_reason_never_claims_staff_can_recover(): void
    {
        $this->assertFalse(SiteContentUnavailable::withMessages(['publication' => 'Synthetic'])->recoverableByStaff());
        $this->assertTrue(SiteContentUnavailable::because(SiteContentUnavailable::RELEASE, ['publication' => 'Synthetic'])->recoverableByStaff());
        foreach ([SiteContentUnavailable::PUBLICATION, SiteContentUnavailable::MISSING] as $reason) {
            $this->assertFalse(SiteContentUnavailable::because($reason, ['publication' => 'Synthetic'])->recoverableByStaff());
        }
    }
}
