<?php

namespace Tests\Feature;

use App\Domain\Catalog\ChangeTrackVisibility;
use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Collections\CollectionsPolicy;
use App\Domain\Collections\Models\TrackCollection;
use App\Domain\Collections\TrackCollections;
use App\Domain\Customers\DataRights\RetentionSchedule;
use App\Domain\Media\MalwareScanner;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Filament\Resources\TrackCollectionResource\Pages\ManageTrackCollections;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** BL-54 (FP-006). Every collection, track and title below is synthetic test data. */
class TrackCollectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    /** @var array<int, Offer> offer of each fixture track, by track id */
    private array $offers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.url' => 'https://audio.example.test', 'app.debug' => false]);
        $this->actor = LicenseFixtures::admin();
        $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** A track published through the real services, with media, rights, a published license and an active offer. */
    private function publishedTrack(string $title): Track
    {
        $scanner = new class extends MalwareScanner
        {
            public function scan(string $path): array
            {
                return ['engine' => 'clamav', 'version' => 'synthetic-host-scan-stand-in', 'status' => 'clean', 'sha256' => hash_file('sha256', $path)];
            }
        };
        $fixture = QuoteFixtures::selection(4999, $scanner);
        $this->actor = $fixture['actor'];
        $fixture['track']->forceFill(['title' => $title])->save();
        $this->offers[$fixture['track']->id] = $fixture['offer'];

        return $fixture['track']->refresh();
    }

    private function draftTrack(string $title): Track
    {
        return Track::create(['title' => $title, 'slug' => 'synthetic-draft-'.md5($title), 'artist' => 'Test only', 'bpm' => 90, 'musical_key' => 'C minor', 'genre' => 'Test']);
    }

    /** @param  list<Track>  $tracks */
    private function collection(array $tracks, array $over = [], bool $publish = true): TrackCollection
    {
        $service = app(TrackCollections::class);
        $collection = $service->save(null, $over + [
            'title' => 'Synthetic Collection', 'slug' => 'synthetic-collection', 'description' => 'A clearly synthetic description.',
            'track_ids' => array_map(fn (Track $track): int => $track->id, $tracks),
        ], $this->actor);

        return $publish ? $service->publish($collection, $collection->revision, $this->actor) : $collection;
    }

    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }

    private function production(): void
    {
        $this->app->instance('env', 'production');
    }

    private function messages(callable $operation): array
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
        $this->fail('The operation was accepted.');
    }

    /** Every array key anywhere in the payload, lower-cased. */
    private function keys(mixed $value): array
    {
        $keys = [];
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $keys[] = strtolower((string) $key);
                array_push($keys, ...$this->keys($child));
            }
        }

        return $keys;
    }

    public function test_the_flag_defaults_off_and_every_public_surface_is_absent_until_it_is_on(): void
    {
        $this->assertFalse(config('collections.enabled'));
        $track = $this->publishedTrack('Synthetic One');
        $this->collection([$track]);
        $this->get('/collections')->assertNotFound();
        $this->get('/collections/synthetic-collection')->assertNotFound();
        $this->assertNotContains('/collections', array_column(app(EditorialContent::class)->chrome(SiteContentSchema::defaults())['navigation'], 'href'));
        $this->production();
        $this->get('/site-pages-sitemap.xml')->assertOk()->assertDontSee('/collections', false);
        // Only the exact boolean true enables it.
        foreach (['true', 1, '1'] as $value) {
            config(['collections.enabled' => $value]);
            $this->assertFalse(app(CollectionsPolicy::class)->enabled());
            $this->get('/collections')->assertNotFound();
        }
    }

    public function test_a_published_collection_lists_only_published_tracks_in_staff_order_each_linking_to_its_own_page(): void
    {
        $a = $this->publishedTrack('Synthetic Alpha');
        $b = $this->publishedTrack('Synthetic Bravo');
        $draft = $this->draftTrack('Synthetic Draft');
        $this->collection([$b, $draft, $a]);
        config(['collections.enabled' => true]);

        $detail = $this->get('/collections/synthetic-collection', $this->inertia())->assertOk()->json('props.collection');
        $this->assertSame(['Synthetic Bravo', 'Synthetic Alpha'], array_column($detail['tracks'], 'title'));
        $this->assertSame(['/tracks/'.$b->slug, '/tracks/'.$a->slug], array_column($detail['tracks'], 'path'));
        $this->assertSame(2, $detail['trackCount']);
        $this->assertSame('/collections/synthetic-collection', $detail['path']);
        $this->get('/tracks/'.$a->slug)->assertOk();

        $index = $this->get('/collections', $this->inertia())->assertOk()->json('props.collections');
        $this->assertSame([['slug' => 'synthetic-collection', 'path' => '/collections/synthetic-collection', 'title' => 'Synthetic Collection', 'trackCount' => 2,
            'preview' => ['Synthetic Bravo', 'Synthetic Alpha']]], $index);
        $this->assertContains('/collections', array_column($this->get('/', $this->inertia())->json('props.siteContent.navigation'), 'href'));
    }

    public function test_a_track_that_stops_being_published_disappears_from_the_collection_without_changing_it(): void
    {
        $a = $this->publishedTrack('Synthetic Alpha');
        $b = $this->publishedTrack('Synthetic Bravo');
        $collection = $this->collection([$a, $b]);
        config(['collections.enabled' => true]);
        $this->assertCount(2, $this->get('/collections/synthetic-collection', $this->inertia())->json('props.collection.tracks'));

        app(PublishTrack::class)->unpublish($b->refresh(), $this->actor);
        $page = $this->get('/collections/synthetic-collection', $this->inertia())->assertOk();
        $this->assertSame(['Synthetic Alpha'], array_column($page->json('props.collection.tracks'), 'title'));
        $this->assertStringNotContainsString('Synthetic Bravo', $page->getContent());
        $this->assertSame(1, $page->json('props.collection.trackCount'));
        // The staff list still holds the reference, so republishing the track brings it back in place.
        $this->assertSame([$a->id, $b->id], $collection->items()->pluck('track_id')->map(fn ($id) => (int) $id)->all());

        app(PublishTrack::class)->unpublish($a->refresh(), $this->actor);
        $this->get('/collections/synthetic-collection')->assertNotFound();
        $this->assertSame([], $this->get('/collections', $this->inertia())->assertOk()->json('props.collections'));
    }

    public function test_unlisted_and_unsellable_tracks_are_hidden_too(): void
    {
        $listed = $this->publishedTrack('Synthetic Listed');
        $unlisted = $this->publishedTrack('Synthetic Unlisted');
        $noOffer = $this->publishedTrack('Synthetic No Offer');
        $this->collection([$listed, $unlisted, $noOffer]);
        config(['collections.enabled' => true]);
        $unlisted = $unlisted->refresh();
        app(ChangeTrackVisibility::class)->handle($unlisted, $this->actor, 'unlisted', $unlisted->metadata_version, $unlisted->publication_version);
        app(DeactivateOffer::class)->handle($this->offers[$noOffer->id], $this->actor);

        // The unlisted track still has its own page by link, but a grouping page never advertises it.
        $this->get('/tracks/'.$unlisted->slug)->assertOk();
        $detail = $this->get('/collections/synthetic-collection', $this->inertia())->assertOk()->json('props.collection');
        $this->assertSame(['Synthetic Listed'], array_column($detail['tracks'], 'title'));
        $this->assertStringNotContainsString('Synthetic Unlisted', $this->get('/collections', $this->inertia())->getContent());
        $this->assertStringNotContainsString('Synthetic No Offer', $this->get('/collections', $this->inertia())->getContent());
    }

    public function test_unpublished_unknown_and_empty_collections_are_404_and_absent_from_the_index(): void
    {
        $track = $this->publishedTrack('Synthetic One');
        $draft = $this->collection([$track], ['slug' => 'still-a-draft'], publish: false);
        $this->collection([$this->draftTrack('Only a draft track')], ['slug' => 'no-listed-tracks']);
        $withdrawn = $this->collection([$track], ['slug' => 'withdrawn']);
        app(TrackCollections::class)->unpublish($withdrawn, $withdrawn->revision, $this->actor);
        $this->collection([$track], ['slug' => 'visible', 'title' => 'Visible']);
        config(['collections.enabled' => true]);

        foreach (['still-a-draft', 'no-listed-tracks', 'withdrawn', 'nonexistent'] as $slug) {
            $this->get('/collections/'.$slug)->assertNotFound();
        }
        $this->assertFalse($draft->is_published);
        $this->assertSame(['visible'], array_column($this->get('/collections', $this->inertia())->json('props.collections'), 'slug'));
    }

    public function test_the_public_payload_and_markup_carry_no_price_offer_or_bundle(): void
    {
        $a = $this->publishedTrack('Synthetic Alpha');
        $b = $this->publishedTrack('Synthetic Bravo');
        $this->collection([$a, $b]);
        config(['collections.enabled' => true]);
        foreach (['/collections', '/collections/synthetic-collection'] as $path) {
            $response = $this->get($path, $this->inertia())->assertOk();
            foreach ($this->keys($response->json('props.collection') ?? $response->json('props.collections')) as $key) {
                $this->assertDoesNotMatchRegularExpression('/price|offer|currency|bundle|cart|license|discount|amount|total|checkout/', $key, $path);
            }
            // The numbers the tracks are sold at never reach the page.
            $this->assertStringNotContainsString('4999', $response->getContent(), $path);
            $this->assertStringNotContainsString('49.99', $response->getContent(), $path);
        }
        // The track's own price stays on the track page.
        $this->assertStringContainsString('4999', $this->get('/tracks/'.$a->slug, $this->inertia())->getContent());
    }

    public function test_sitemap_lists_visible_collections_only_in_production_and_only_while_the_pages_are_on(): void
    {
        $track = $this->publishedTrack('Synthetic One');
        $this->collection([$track], ['slug' => 'visible', 'title' => 'Visible']);
        $this->collection([$track], ['slug' => 'hidden-draft', 'title' => 'Hidden'], publish: false);
        $this->collection([$this->draftTrack('Only a draft track')], ['slug' => 'no-listed-tracks']);
        $this->production();
        $this->get('/site-pages-sitemap.xml')->assertOk()->assertDontSee('collections', false);
        config(['collections.enabled' => true]);
        $xml = simplexml_load_string($this->get('/site-pages-sitemap.xml')->assertOk()->getContent());
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urls = array_map(fn ($loc) => (string) $loc, $xml->xpath('/s:urlset/s:url/s:loc'));
        $this->assertSame(['https://audio.example.test/', 'https://audio.example.test/collections', 'https://audio.example.test/collections/visible'], $urls);
    }

    public function test_metadata_is_pinned_to_the_site_url_and_noindex_outside_production(): void
    {
        $this->collection([$this->publishedTrack('Synthetic One')]);
        config(['collections.enabled' => true]);
        $metadata = $this->get('/collections/synthetic-collection', $this->inertia())->json('props.metadata');
        $this->assertSame('https://audio.example.test/collections/synthetic-collection', $metadata['canonicalUrl']);
        $this->assertSame('noindex, nofollow', $metadata['robots']);
        $this->assertSame('https://audio.example.test/collections', $this->get('/collections', $this->inertia())->json('props.metadata.canonicalUrl'));
    }

    public function test_the_writer_enforces_its_field_rules_and_records_one_audit_event_per_command(): void
    {
        $track = $this->draftTrack('Synthetic One');
        $service = app(TrackCollections::class);
        $base = ['title' => 'Synthetic', 'slug' => 'synthetic', 'description' => null, 'track_ids' => [$track->id]];
        foreach ([['slug', ['slug' => 'Bad Slug']], ['slug', ['slug' => 'a--b']], ['title', ['title' => '<b>x</b>']], ['title', ['title' => '']],
            ['description', ['description' => '<script>']], ['track_ids', ['track_ids' => [$track->id, $track->id]]], ['track_ids', ['track_ids' => [999999]]],
            ['track_ids', ['track_ids' => ['x']]], ['track_ids', ['track_ids' => range(1, TrackCollections::MAX_TRACKS + 1)]]] as [$field, $change]) {
            $this->assertArrayHasKey($field, $this->messages(fn () => $service->save(null, $change + $base, $this->actor)), json_encode($change));
        }
        $this->assertArrayHasKey('title', $this->messages(fn () => $service->save(null, ['title' => 'x'], $this->actor)));
        $this->assertSame(['Use each track once.'], $this->messages(fn () => $service->save(null, ['track_ids' => [$track->id, $track->id]] + $base, $this->actor))['track_ids']);
        $this->assertSame(0, TrackCollection::count());

        $collection = $service->save(null, $base, $this->actor);
        $this->assertArrayHasKey('slug', $this->messages(fn () => $service->save(null, ['title' => 'Other'] + $base, $this->actor)));
        // A save that changes nothing is not a new revision or an audit event.
        $this->assertSame($collection->revision, $service->save($collection, $base, $this->actor, $collection->revision)->revision);
        $this->assertArrayHasKey('title', $this->messages(fn () => $service->save($collection, ['title' => 'New'] + $base, $this->actor, $collection->revision + 5)));

        $second = $this->draftTrack('Synthetic Two');
        $reordered = $service->save($collection, ['track_ids' => [$second->id, $track->id]] + $base, $this->actor, $collection->revision);
        $this->assertSame([$second->id, $track->id], $reordered->items()->pluck('track_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([1, 2], $reordered->items()->pluck('position')->all());
        $this->assertSame(['collections.collection.created', 'collections.collection.updated'], DB::table('audit_events')->where('action', 'like', 'collections.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_publication_needs_a_track_and_a_current_revision_and_a_published_collection_cannot_be_emptied(): void
    {
        $service = app(TrackCollections::class);
        $empty = $service->save(null, ['title' => 'Empty', 'slug' => 'empty', 'description' => null, 'track_ids' => []], $this->actor);
        $this->assertArrayHasKey('track_ids', $service->blockers($empty));
        $this->assertArrayHasKey('track_ids', $this->messages(fn () => $service->publish($empty, $empty->revision, $this->actor)));
        $this->assertFalse($empty->refresh()->is_published);

        $track = $this->draftTrack('Synthetic One');
        $ready = $service->save($empty, ['title' => 'Empty', 'slug' => 'empty', 'description' => null, 'track_ids' => [$track->id]], $this->actor, $empty->revision);
        $this->assertArrayHasKey('title', $this->messages(fn () => $service->publish($ready, $ready->revision - 1, $this->actor)));
        $published = $service->publish($ready, $ready->revision, $this->actor);
        $this->assertTrue($published->is_published);
        $this->assertArrayHasKey('is_published', $this->messages(fn () => $service->publish($published, $published->revision, $this->actor)));
        $this->assertArrayHasKey('track_ids', $this->messages(fn () => $service->save($published, ['title' => 'Empty', 'slug' => 'empty', 'description' => null, 'track_ids' => []], $this->actor, $published->revision)));
        $this->assertSame(1, $published->refresh()->items()->count());
        $unpublished = $service->unpublish($published, $published->revision, $this->actor);
        $this->assertFalse($unpublished->is_published);
        $this->assertArrayHasKey('is_published', $this->messages(fn () => $service->unpublish($unpublished, $unpublished->revision, $this->actor)));
        $this->assertSame(['collections.collection.created', 'collections.collection.updated', 'collections.collection.published', 'collections.collection.unpublished'],
            DB::table('audit_events')->where('action', 'like', 'collections.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_only_current_mfa_enrolled_catalog_staff_can_write(): void
    {
        $service = app(TrackCollections::class);
        $data = ['title' => 'Synthetic', 'slug' => 'synthetic', 'description' => null, 'track_ids' => []];
        // With MFA required (staging and production), an admin without an enrolled secret passes the gate but is refused
        // before any write, and the enrolled actor is admitted.
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            try {
                $service->save(null, $data, LicenseFixtures::admin());
                $this->fail('An admin without MFA wrote a collection.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('Admin multi-factor authentication is required.', $exception->getMessage());
            }
            $this->assertSame(0, TrackCollection::query()->count());
            $this->assertSame('synthetic', $service->save(null, $data, $this->actor)->slug);
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
        $this->expectException(AuthorizationException::class);
        $service->save(null, $data, User::factory()->create());
    }

    public function test_the_filament_editor_keeps_staff_order_and_publishes_through_the_service(): void
    {
        $this->actingAs($this->actor);
        $a = $this->draftTrack('Synthetic Alpha');
        $b = $this->draftTrack('Synthetic Bravo');
        $form = ['title' => 'Synthetic Editor Collection', 'slug' => 'synthetic-editor', 'description' => '', 'tracks' => [['track_id' => $b->id], ['track_id' => $a->id]]];
        Livewire::test(ManageTrackCollections::class)->callAction('create', data: $form)->assertHasNoActionErrors();
        $collection = TrackCollection::sole();
        $this->assertSame([$b->id, $a->id], $collection->items()->pluck('track_id')->map(fn ($id) => (int) $id)->all());
        Livewire::test(ManageTrackCollections::class)->callAction('create', data: ['slug' => 'Bad Slug'] + $form)->assertHasActionErrors(['slug']);
        Livewire::test(ManageTrackCollections::class)->callTableAction('publish', $collection)->assertNotified('Collection published');
        $this->assertTrue($collection->refresh()->is_published);
        Livewire::test(ManageTrackCollections::class)->callTableAction('unpublish', $collection)->assertNotified('Collection unpublished');
        $this->assertFalse($collection->refresh()->is_published);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'like', 'collections.%')->whereNull('actor_id')->count());
    }

    public function test_the_tables_are_classified_and_the_chrome_item_is_not_part_of_a_saved_release(): void
    {
        $this->assertArrayHasKey('track_collections', RetentionSchedule::NOT_CUSTOMER_DATA);
        $this->assertArrayHasKey('track_collection_items', RetentionSchedule::NOT_CUSTOMER_DATA);
        config(['collections.enabled' => true]);
        $content = SiteContentSchema::defaults();
        $this->assertContains('/collections', array_column(app(EditorialContent::class)->chrome($content)['navigation'], 'href'));
        $this->assertNotContains('/collections', array_column(app(EditorialContent::class)->chrome($content, families: false)['navigation'], 'href'));
    }
}
