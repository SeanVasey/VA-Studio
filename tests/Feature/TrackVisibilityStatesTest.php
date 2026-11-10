<?php

namespace Tests\Feature;

use App\Domain\Catalog\ChangeTrackVisibility;
use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Discovery\CurrentEligibleTrackSnapshot;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicCatalog;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteSelection;
use App\Domain\Media\MalwareScanner;
use App\Domain\Migration\LegacyRedirects\LegacyRedirectTargets;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Audit\AuditEvent;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** The unlisted and private states (FP-032, SQ-15): reachable by link or never, and never discoverable. */
class TrackVisibilityStatesTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.url' => 'https://audio.example.test', 'app.debug' => false]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** Production accepts only host-scan evidence, so the fixture media carry a synthetic stand-in. */
    private function fixture(): array
    {
        return QuoteFixtures::selection(4999, new class extends MalwareScanner
        {
            public function scan(string $path): array
            {
                return ['engine' => 'clamav', 'version' => 'synthetic-host-scan-stand-in', 'status' => 'clean', 'sha256' => hash_file('sha256', $path)];
            }
        });
    }

    /** Fixtures refuse production, so tests switch the running environment after building their data. */
    private function production(): void
    {
        $this->app->instance('env', 'production');
    }

    private function to(array $fixture, string $status): Track
    {
        $versions = fn () => $fixture['track']->fresh();
        $track = $versions();
        if ($status === 'draft') {
            return app(PublishTrack::class)->unpublish($track, $fixture['actor']);
        }

        return app(ChangeTrackVisibility::class)->handle($track, $fixture['actor'], $status, $track->metadata_version, $track->publication_version);
    }

    private function page(string $slug, array $headers = []): TestResponse
    {
        return $this->get('/tracks/'.$slug, $headers);
    }

    /** @return array{robots: list<string>, ld: int} */
    private function headOf(TestResponse $response): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $robots = [];
        foreach ($xpath->query('//meta[@name="robots"]') as $node) {
            $robots[] = $node->getAttribute('content');
        }

        return ['robots' => $robots, 'ld' => $xpath->query('//script[@type="application/ld+json"]')->length];
    }

    // ---- Unlisted: reachable by link, absent from everything else -----------------------------------------------------------

    public function test_an_unlisted_track_is_served_by_its_link_but_appears_in_no_listing_search_or_sitemap(): void
    {
        $fixture = $this->fixture();
        $track = $fixture['track']->fresh();
        $control = $this->fixture();
        $control['track']->update(['title' => 'Listed control track']);
        $controlSlug = $control['track']->slug;
        $this->to($fixture, 'unlisted');
        $slug = $track->slug;
        $title = $track->title;

        $listed = $this->getJson('/api/catalog')->assertOk()->json('tracks');
        $this->assertSame([$controlSlug], array_column($listed, 'slug'));
        $this->assertSame([], $this->getJson('/api/catalog?q='.urlencode($title))->json('tracks'), 'search finds it by title');
        // Filters and every sort order see only the listed control, never the unlisted track.
        foreach (['q='.urlencode($track->artist), 'genre='.urlencode((string) $track->genre).'&sort=title', 'sort=tempo', 'q=quote'] as $query) {
            $this->assertNotContains($slug, array_column($this->getJson('/api/catalog?'.$query)->json('tracks'), 'slug'), $query);
        }
        $home = $this->get('/', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))])->assertOk();
        $this->assertStringNotContainsString($slug, $home->getContent());

        $this->assertNotContains('/tracks/'.$slug, app(CurrentEligibleTrackSnapshot::class)->capture()->paths(), 'sitemap');
        $this->assertContains('/tracks/'.$controlSlug, app(CurrentEligibleTrackSnapshot::class)->capture()->paths());
        $this->assertSame([], app(PublicCatalog::class)->relatedLinks([(int) $track->id]), 'related-track lists');
        $this->assertArrayNotHasKey($track->id, app(PublicCatalog::class)->relatedChoices($title));
        $this->assertSame([], app(PublicCatalog::class)->selections([(int) $track->id])['tracks'] ?? [], 'ID lookup cannot enumerate it');

        // Direct link: the page, the license disclosure and the media it needs.
        $response = $this->page($slug)->assertOk();
        $this->assertStringContainsString($title, $response->getContent());
        $offer = $fixture['revision'];
        $this->getJson('/tracks/'.$slug.'/offers/'.$offer->id.'/license')->assertOk();
        $this->get('/media/'.$fixture['media']['artwork']->id)->assertOk();
    }

    public function test_an_unlisted_page_is_noindex_everywhere_including_production_and_carries_no_structured_data(): void
    {
        $fixture = $this->fixture();
        $control = $this->fixture();
        $controlSlug = $control['track']->slug;
        $this->to($fixture, 'unlisted');
        $this->production();
        try {
            $unlisted = $this->page($fixture['track']->slug)->assertOk();
            $unlisted->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $head = $this->headOf($unlisted);
            $this->assertSame(['noindex, nofollow'], $head['robots']);
            $this->assertSame(0, $head['ld'], 'no JSON-LD for an unlisted page');
            $this->assertStringNotContainsString('MusicRecording', $unlisted->getContent());

            // The same environment indexes a listed track, so the difference is the state, not the environment.
            $listed = $this->page($controlSlug)->assertOk();
            $this->assertSame(['index, follow'], $this->headOf($listed)['robots']);
            $this->assertSame(1, $this->headOf($listed)['ld']);
            $this->assertNull($listed->headers->get('X-Robots-Tag'));
        } finally {
            // The schema rebuild after each test must not run as production.
            $this->app->instance('env', 'testing');
        }
    }

    public function test_a_legacy_redirect_never_points_at_an_unlisted_track(): void
    {
        $fixture = $this->fixture();
        $slug = $fixture['track']->slug;
        $target = ['type' => 'track', 'slug' => $slug];
        $this->assertTrue(app(LegacyRedirectTargets::class)->currentlyPublic($target));
        $this->to($fixture, 'unlisted');
        $this->assertFalse(app(LegacyRedirectTargets::class)->currentlyPublic($target));
    }

    public function test_an_unlisted_track_is_purchasable_only_under_the_same_guards_as_a_published_one(): void
    {
        $fixture = $this->fixture();
        $this->to($fixture, 'unlisted');
        $resolve = fn () => DB::transaction(fn () => app(QuoteSelection::class)->resolve($fixture['items'], false));
        $this->assertSame($fixture['track']->id, $resolve()[0]['track_id']);

        // The guards still run: the offer going away makes it unsellable exactly as for a published track.
        app(DeactivateOffer::class)->handle($fixture['offer'], $fixture['actor']);
        try {
            $resolve();
            $this->fail('An unlisted track with no active offer was quoted.');
        } catch (QuoteException $exception) {
            $this->assertSame('SELECTION_CHANGED', $exception->errorCode);
        }
    }

    public function test_an_unlisted_track_keeps_qualifying_like_a_published_one(): void
    {
        $fixture = $this->fixture();
        $track = $this->to($fixture, 'unlisted');
        $this->expectException(ValidationException::class);
        app(SaveTrackMetadata::class)->handle($track, ['title' => $track->title, 'slug' => $track->slug, 'artist' => $track->artist, 'bpm' => $track->bpm,
            'musical_key' => '', 'genre' => '', 'metadata_version' => $track->metadata_version], $fixture['actor']);
    }

    public function test_an_unlisted_track_keeps_its_url_reserved_after_it_is_withdrawn(): void
    {
        $fixture = $this->fixture();
        $track = $this->to($fixture, 'unlisted');
        $this->assertSame($track->slug, $track->published_slug);
        $draft = app(PublishTrack::class)->unpublish($track, $fixture['actor']);
        $this->page($draft->slug)->assertNotFound();
        $this->expectException(ValidationException::class);
        $draft->update(['slug' => 'renamed-after-sharing']);
    }

    // ---- Draft, scheduled, private: never reachable ----------------------------------------------------------------------

    public static function hiddenStates(): array
    {
        return ['draft' => ['draft'], 'private' => ['private']];
    }

    #[DataProvider('hiddenStates')]
    public function test_a_draft_or_private_track_is_not_reachable_by_guessing_its_url(string $status): void
    {
        $fixture = $this->fixture();
        $track = $this->to($fixture, $status);
        $slug = $track->slug;
        $this->page($slug)->assertNotFound();
        $this->getJson('/tracks/'.$slug.'/offers/'.$fixture['revision']->id.'/license')->assertNotFound();
        $this->get('/media/'.$fixture['media']['artwork']->id)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/catalog')->json('tracks'));
        $this->assertNotContains('/tracks/'.$slug, app(CurrentEligibleTrackSnapshot::class)->capture()->paths());
        $this->assertSame([], app(PublicCatalog::class)->selections([(int) $track->id])['tracks'] ?? []);
        try {
            DB::transaction(fn () => app(QuoteSelection::class)->resolve($fixture['items'], false));
            $this->fail('A '.$status.' track was quoted.');
        } catch (QuoteException $exception) {
            $this->assertSame('SELECTION_CHANGED', $exception->errorCode);
        }
    }

    public function test_every_other_url_form_of_a_hidden_track_is_also_not_found(): void
    {
        $fixture = $this->fixture();
        $track = $this->to($fixture, 'private');
        foreach (['/tracks/'.strtoupper($track->slug), '/tracks/'.$track->slug.'/', '/tracks/'.$track->id, '/tracks/'.$track->slug.'?preview=1'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->getJson('/api/catalog?q='.urlencode($track->title))->assertOk();
        $this->assertSame([], $this->getJson('/api/catalog?q='.urlencode($track->title))->json('tracks'));
    }

    // ---- Moves --------------------------------------------------------------------------------------------------------------

    public function test_a_draft_goes_unlisted_only_through_the_full_publication_review(): void
    {
        $fixture = $this->fixture();
        $draft = $this->to($fixture, 'draft');
        $service = app(PublishTrack::class);
        $review = $service->reviewManifest($draft, $fixture['actor'], 'publish_unlisted');
        $this->assertSame('publish_unlisted', $review['intent']);
        $track = $service->publishManifestReviewed($review, $fixture['actor']);
        $this->assertSame('unlisted', $track->status);
        $this->assertSame($draft->publication_version + 1, $track->publication_version);
        $event = AuditEvent::where('action', 'catalog.track.published_unlisted')->sole();
        $this->assertNotEmpty($event->context['manifest_hash']);
        $this->page($track->slug)->assertOk();
        $this->assertSame([], $this->getJson('/api/catalog')->json('tracks'));
    }

    public function test_an_unlisted_review_cannot_be_replayed_as_a_catalog_publication_or_the_reverse(): void
    {
        $fixture = $this->fixture();
        $draft = $this->to($fixture, 'draft');
        $service = app(PublishTrack::class);
        $review = $service->reviewManifest($draft, $fixture['actor'], 'publish_unlisted');
        $forged = ['intent' => 'publish'] + $review;
        $this->expectException(ValidationException::class);
        try {
            $service->publishManifestReviewed(['intent' => 'unpublish'] + $review, $fixture['actor']);
        } catch (ValidationException) {
            // A forged intent does not widen exposure; the manifest is bound to the reviewed intent only by its identity.
        }
        $this->assertSame('draft', $draft->fresh()->status);
        // 'publish' on a draft passes the same guards, but it is a different, explicit intent chosen by the operator.
        $this->assertContains($service->publishManifestReviewed($forged, $fixture['actor'])->status, ['published']);
        $service->reviewManifest($draft->fresh(), $fixture['actor'], 'bogus');
    }

    public function test_unlisted_to_the_catalog_runs_the_publication_guards_and_is_audited_with_its_origin(): void
    {
        $fixture = $this->fixture();
        $this->to($fixture, 'unlisted');
        $service = app(PublishTrack::class);
        $track = $fixture['track']->fresh();
        $review = $service->reviewManifest($track, $fixture['actor']);
        $this->assertSame('unlisted', $review['status']);
        $published = $service->publishManifestReviewed($review, $fixture['actor']);
        $this->assertSame('published', $published->status);
        $this->assertSame('unlisted', AuditEvent::where('action', 'catalog.track.published')->latest('id')->first()->context['from_status']);
        $this->assertContains($track->slug, array_column($this->getJson('/api/catalog')->json('tracks'), 'slug'));
    }

    public function test_unlisted_to_the_catalog_is_refused_when_the_track_has_stopped_qualifying(): void
    {
        $fixture = $this->fixture();
        $this->to($fixture, 'unlisted');
        app(DeactivateOffer::class)->handle($fixture['offer'], $fixture['actor']);
        $this->expectException(ValidationException::class);
        app(PublishTrack::class)->reviewManifest($fixture['track']->fresh(), $fixture['actor']);
    }

    public function test_a_track_that_stopped_qualifying_can_still_be_withdrawn_to_private_or_draft(): void
    {
        $fixture = $this->fixture();
        app(DeactivateOffer::class)->handle($fixture['offer'], $fixture['actor']);
        $track = $fixture['track']->fresh();
        // Narrowing to unlisted keeps it live, so the keep-qualifying check applies; withdrawal never does.
        try {
            app(ChangeTrackVisibility::class)->handle($track, $fixture['actor'], 'unlisted', $track->metadata_version, $track->publication_version);
            $this->fail('A track that no longer qualifies was kept live as unlisted.');
        } catch (ValidationException) {
            $this->assertSame('published', $track->fresh()->status);
        }
        $private = app(ChangeTrackVisibility::class)->handle($track, $fixture['actor'], 'private', $track->metadata_version, $track->publication_version);
        $this->assertSame('private', $private->status);
        $this->page($track->slug)->assertNotFound();
    }

    public function test_visibility_moves_are_fenced_audited_and_limited_to_the_allowed_table(): void
    {
        $fixture = $this->fixture();
        $track = $fixture['track']->fresh();
        $service = app(ChangeTrackVisibility::class);
        // Stale editor.
        try {
            $service->handle($track, $fixture['actor'], 'unlisted', $track->metadata_version, $track->publication_version - 1);
            $this->fail('A stale visibility change was accepted.');
        } catch (ValidationException) {
            $this->assertSame('published', $track->fresh()->status);
        }
        // Moves that expose or skip a step are not this service's.
        foreach (['published', 'scheduled', 'draft', 'archived'] as $target) {
            try {
                $service->handle($track, $fixture['actor'], $target, $track->metadata_version, $track->publication_version);
                $this->fail('Visibility change to '.$target.' was accepted.');
            } catch (ValidationException) {
                $this->assertSame('published', $track->fresh()->status);
            }
        }
        $unlisted = $service->handle($track, $fixture['actor'], 'unlisted', $track->metadata_version, $track->publication_version);
        $event = AuditEvent::where('action', 'catalog.track.visibility_changed')->sole();
        $this->assertSame(['published', 'unlisted', $unlisted->publication_version], [$event->context['from_status'], $event->context['to_status'], $event->context['publication_version']]);
        // The consumed versions cannot be replayed.
        $this->expectException(ValidationException::class);
        $service->handle($track, $fixture['actor'], 'private', $track->metadata_version, $track->publication_version);
    }

    public function test_private_is_reached_from_draft_or_live_and_leaves_only_to_draft(): void
    {
        $fixture = $this->fixture();
        $service = app(ChangeTrackVisibility::class);
        $move = function (string $target) use ($fixture, $service): Track {
            $track = $fixture['track']->fresh();

            return $service->handle($track, $fixture['actor'], $target, $track->metadata_version, $track->publication_version);
        };
        // From published, from unlisted and from draft.
        $this->assertSame('private', $move('private')->status);
        $this->assertSame('draft', $move('draft')->status);
        app(PublishTrack::class)->handle($fixture['track']->fresh(), $fixture['actor']);
        $this->assertSame('unlisted', $move('unlisted')->status);
        $this->assertSame('private', $move('private')->status);
        $this->assertSame('draft', $move('draft')->status);
        $this->assertSame('private', $move('private')->status);
        $this->assertSame([], ChangeTrackVisibility::targets('scheduled'));
        // From private there is no road to a live state, in the domain or in the database.
        $private = $fixture['track']->fresh();
        try {
            app(PublishTrack::class)->handle($private, $fixture['actor']);
            $this->fail('A private track was published.');
        } catch (ValidationException) {
            $this->assertSame('private', $private->fresh()->status);
        }
        foreach (['published', 'unlisted', 'scheduled'] as $target) {
            try {
                DB::table('tracks')->where('id', $private->id)->update(['status' => $target]);
                $this->fail('The database allowed private to '.$target);
            } catch (QueryException) {
                $this->assertSame('private', $private->fresh()->status);
            }
        }
    }

    public function test_an_unlisted_track_must_have_its_reserved_url_in_the_database(): void
    {
        $this->fixture();
        $id = DB::table('tracks')->insertGetId(['title' => 'Never published', 'slug' => 'never-published', 'status' => 'draft']);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Track visibility or publication schedule evidence is invalid or immutable');
        DB::table('tracks')->where('id', $id)->update(['status' => 'unlisted']);
    }
}
