<?php

namespace Tests\Feature;

use App\Domain\Albums\AlbumReleases;
use App\Domain\Albums\ListeningPlatforms;
use App\Domain\Albums\Models\AlbumRelease;
use App\Domain\Customers\DataRights\RetentionSchedule;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Filament\Resources\AlbumReleaseResource\Pages\ManageAlbumReleases;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\SiteImageFixtures as F;
use Tests\TestCase;

/** BL-67. Every release, track, link and image below is synthetic test data; no real release, artwork or link is used. */
class AlbumReleasesTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        config(['app.url' => 'https://audio.example.test', 'app.debug' => false]);
        $this->actor = LicenseFixtures::admin();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function cover(string $slot = 'release_cover'): SiteImage
    {
        return F::ready($slot, $this->actor);
    }

    /** @return array<string, mixed> */
    private function data(array $over = [], ?SiteImage $cover = null): array
    {
        return $over + [
            'title' => 'Synthetic Release One', 'artist_name' => 'Synthetic Artist', 'slug' => 'synthetic-release-one', 'release_type' => 'album',
            'release_date' => '2024-03-01', 'description' => 'A clearly synthetic description.',
            'artwork_image_id' => $cover?->id, 'artwork_alt' => $cover === null ? null : 'A synthetic blue field with a light block',
            'listening_links' => [['platform' => 'spotify', 'url' => 'https://open.spotify.com/album/SYNTHETIC1']],
            'tracks' => [['title' => 'Synthetic Track A', 'duration_seconds' => 225], ['title' => 'Synthetic Track B', 'duration_seconds' => 3725]],
        ];
    }

    private function draftReady(array $over = []): AlbumRelease
    {
        return app(AlbumReleases::class)->save(null, $this->data($over, $this->cover()), $this->actor);
    }

    /** Another staff member saves a new title, advancing the revision. */
    private function bump(AlbumRelease $release, string $title): void
    {
        $release->refresh();
        app(AlbumReleases::class)->save($release, $this->data(['title' => $title, 'slug' => $release->slug, 'artwork_image_id' => $release->artwork_image_id, 'artwork_alt' => $release->artwork_alt]), $this->actor, $release->revision);
    }

    private function published(array $over = [], ?SiteImage $cover = null): AlbumRelease
    {
        $service = app(AlbumReleases::class);
        $release = $service->save(null, $this->data($over, $cover ?? $this->cover()), $this->actor);

        return $service->publish($release, $release->revision, $this->actor);
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

    public function test_the_flag_defaults_off_and_every_public_surface_is_absent_until_it_is_on(): void
    {
        $this->assertFalse(config('albums.enabled'));
        $release = $this->published();
        $this->assertTrue($release->is_published);
        $this->get('/albums')->assertNotFound();
        $this->get('/albums/synthetic-release-one')->assertNotFound();
        $this->get('/', $this->inertia())->assertOk();
        $nav = array_column($this->get('/', $this->inertia())->json('props.siteContent.navigation'), 'href');
        $this->assertNotContains('/albums', $nav);
        $variant = $release->artwork->variants->firstWhere('format', 'jpeg');
        $this->get('/site-images/'.$variant->sha256.'.jpg')->assertNotFound();
        $this->production();
        $this->get('/site-pages-sitemap.xml')->assertOk()->assertDontSee('/albums', false);
        // Only the exact boolean true enables it.
        config(['albums.enabled' => 'true']);
        $this->get('/albums')->assertNotFound();
        config(['albums.enabled' => 1]);
        $this->get('/albums/synthetic-release-one')->assertNotFound();
    }

    public function test_index_lists_published_releases_newest_first_and_hides_drafts_and_unpublished(): void
    {
        $cover = $this->cover();
        $service = app(AlbumReleases::class);
        $this->published(['slug' => 'older', 'title' => 'Older', 'release_date' => '2020-01-01'], $cover);
        $this->published(['slug' => 'newest', 'title' => 'Newest', 'release_date' => '2025-06-30', 'release_type' => 'ep'], $cover);
        $this->published(['slug' => 'middle-b', 'title' => 'Middle B', 'release_date' => '2022-05-05', 'release_type' => 'single'], $cover);
        $this->published(['slug' => 'middle-a', 'title' => 'Middle A', 'release_date' => '2022-05-05'], $cover);
        $service->save(null, $this->data(['slug' => 'draft-only', 'title' => 'Draft only']), $this->actor);
        $gone = $this->published(['slug' => 'gone', 'title' => 'Gone'], $cover);
        $service->unpublish($gone, $gone->revision, $this->actor);
        config(['albums.enabled' => true]);

        $props = $this->get('/albums', $this->inertia())->assertOk()->json('props');
        $this->assertSame(['newest', 'middle-a', 'middle-b', 'older'], array_column($props['albums'], 'slug'));
        $this->assertSame(['EP', 'Album', 'Single', 'Album'], array_column($props['albums'], 'typeLabel'));
        $this->assertSame('A synthetic blue field with a light block', $props['albums'][0]['cover']['alt']);
        $this->assertContains('/albums', array_column($props['siteContent']['navigation'], 'href'));
        $this->get('/albums/draft-only')->assertNotFound();
        $this->get('/albums/gone')->assertNotFound();
        $this->get('/albums/Newest')->assertNotFound();
        $this->get('/albums')->assertOk()->assertSee('data-page', false);
    }

    public function test_detail_shows_the_ordered_tracklist_links_and_no_purchase_path(): void
    {
        $this->published();
        config(['albums.enabled' => true]);
        $album = $this->get('/albums/synthetic-release-one', $this->inertia())->assertOk()->json('props.album');
        $this->assertSame(['Synthetic Track A', 'Synthetic Track B'], array_column($album['tracks'], 'title'));
        $this->assertSame(['3:45', '1:02:05'], array_column($album['tracks'], 'duration'));
        $this->assertSame([['platform' => 'spotify', 'label' => 'Spotify', 'url' => 'https://open.spotify.com/album/SYNTHETIC1']], $album['links']);
        $this->assertSame('2024-03-01', $album['releaseDate']);
        $this->assertSame('A synthetic blue field with a light block', $album['cover']['alt']);
        foreach (['price', 'offer', 'cart', 'checkout', 'license'] as $word) {
            $this->assertStringNotContainsString($word, strtolower(json_encode($album)), $word);
        }
        $metadata = $this->get('/albums/synthetic-release-one', $this->inertia())->json('props.metadata');
        $this->assertSame('https://audio.example.test/albums/synthetic-release-one', $metadata['canonicalUrl']);
        $this->assertSame('noindex, nofollow', $metadata['robots']);
        $this->assertSame('music.album', $metadata['type']);
    }

    public function test_publication_requires_cover_art_with_alt_text_a_track_and_a_valid_link(): void
    {
        $service = app(AlbumReleases::class);
        $draft = $service->save(null, $this->data(['listening_links' => [], 'tracks' => []]), $this->actor);
        $errors = $this->messages(fn () => $service->publish($draft, $draft->revision, $this->actor));
        $this->assertEqualsCanonicalizing(['artwork_image_id', 'artwork_alt', 'tracks', 'listening_links'], array_keys($errors));
        $this->assertFalse($draft->refresh()->is_published);

        $cover = $this->cover();
        $draft = $service->save($draft, $this->data(['listening_links' => [], 'tracks' => []], $cover) + [], $this->actor);
        $this->assertSame(['tracks', 'listening_links'], array_keys($this->messages(fn () => $service->publish($draft, $draft->revision, $this->actor))));

        $draft = $service->save($draft, $this->data([], $cover), $this->actor);
        $draft = $service->save($draft, $this->data(['artwork_alt' => null], $cover), $this->actor);
        $this->assertSame(['artwork_alt'], array_keys($this->messages(fn () => $service->publish($draft, $draft->revision, $this->actor))));
        $draft = $service->save($draft, $this->data(['artwork_alt' => '   '], $cover), $this->actor);
        $this->assertNull($draft->artwork_alt);

        $draft = $service->save($draft, $this->data([], $cover), $this->actor);
        $published = $service->publish($draft, $draft->revision, $this->actor);
        $this->assertTrue($published->is_published);
        $this->assertSame($cover->manifest_sha256, $published->artwork_manifest_sha256);
        $this->assertNotNull($published->published_at);
        $this->assertSame($this->actor->id, $published->published_by);
    }

    public function test_cover_art_must_be_a_ready_release_cover_image(): void
    {
        $service = app(AlbumReleases::class);
        $studio = $this->cover('studio');
        $this->assertSame(['artwork_image_id'], array_keys($this->messages(fn () => $service->save(null, $this->data([], $studio), $this->actor))));
        $this->assertSame(['artwork_image_id'], array_keys($this->messages(fn () => $service->save(null, $this->data(['artwork_image_id' => 999999, 'artwork_alt' => 'x']), $this->actor))));
        $waiting = F::quarantined('release_cover', F::jpeg(1200, 1200), 1200, 1200, $this->actor);
        $this->assertSame(['artwork_image_id'], array_keys($this->messages(fn () => $service->save(null, $this->data([], $waiting), $this->actor))));
        $this->assertSame(0, AlbumRelease::count());
        // A square 1200 px image is accepted by the slot; a wide one is not.
        $this->assertSame('release_cover', $this->cover()->slot);
    }

    /** @return array<string, array{mixed, mixed, string}> */
    public static function badLinks(): array
    {
        return [
            'plain http' => ['spotify', 'http://open.spotify.com/album/x', 'Links must start with https://.'],
            'javascript scheme' => ['spotify', 'javascript:alert(1)', 'Links must start with https://.'],
            'protocol relative' => ['spotify', '//open.spotify.com/album/x', 'Links must start with https://.'],
            'host not on the platform' => ['spotify', 'https://music.apple.com/album/x', 'This link is not on Spotify. Use the address of the release on that platform.'],
            'unknown host' => ['spotify', 'https://evil.example/album/x', 'This link is not on Spotify. Use the address of the release on that platform.'],
            'lookalike suffix' => ['spotify', 'https://open.spotify.com.evil.example/x', 'This link is not on Spotify. Use the address of the release on that platform.'],
            'lookalike prefix' => ['spotify', 'https://evilopen.spotify.com/x', 'This link is not on Spotify. Use the address of the release on that platform.'],
            'credentials in the address' => ['spotify', 'https://open.spotify.com@evil.example/x', 'Links cannot carry a login, a port or a #fragment.'],
            'userinfo with the right host' => ['spotify', 'https://user:pw@open.spotify.com/x', 'Links cannot carry a login, a port or a #fragment.'],
            'port' => ['spotify', 'https://open.spotify.com:8443/x', 'Links cannot carry a login, a port or a #fragment.'],
            'fragment' => ['spotify', 'https://open.spotify.com/x#frag', 'Links cannot carry a login, a port or a #fragment.'],
            'bandcamp lookalike' => ['bandcamp', 'https://evilbandcamp.com/album/x', 'This link is not on Bandcamp. Use the address of the release on that platform.'],
            'bandcamp nested subdomain' => ['bandcamp', 'https://a.b.bandcamp.com/album/x', 'This link is not on Bandcamp. Use the address of the release on that platform.'],
            'space' => ['spotify', 'https://open.spotify.com/a b', 'Enter the link as plain text of up to 500 characters, with no spaces.'],
            'quote' => ['spotify', 'https://open.spotify.com/a"b', 'Enter the link as plain text of up to 500 characters, with no spaces.'],
            'angle bracket' => ['spotify', 'https://open.spotify.com/<x>', 'Enter the link as plain text of up to 500 characters, with no spaces.'],
            'too long' => ['spotify', 'https://open.spotify.com/'.str_repeat('a', 500), 'Enter the link as plain text of up to 500 characters, with no spaces.'],
            'empty' => ['spotify', '', 'Enter the link as plain text of up to 500 characters, with no spaces.'],
            'array url' => ['spotify', ['https://open.spotify.com/x'], 'Enter the link as plain text of up to 500 characters, with no spaces.'],
            'unlisted platform' => ['facebook', 'https://www.facebook.com/x', 'Choose a listening platform from the list.'],
        ];
    }

    #[DataProvider('badLinks')]
    public function test_listening_links_must_be_https_and_on_the_chosen_allow_listed_platform(string $platform, mixed $url, string $message): void
    {
        $this->assertSame($message, ListeningPlatforms::problem($platform, $url));
        $errors = $this->messages(fn () => app(AlbumReleases::class)->save(null, $this->data(['listening_links' => [['platform' => $platform, 'url' => $url]]]), $this->actor));
        $this->assertSame(['listening_links' => [$message]], $errors);
        $this->assertSame(0, AlbumRelease::count());
    }

    public function test_good_links_for_every_platform_are_accepted_and_each_platform_is_used_once(): void
    {
        $good = [
            ['spotify', 'https://open.spotify.com/album/A'], ['apple_music', 'https://music.apple.com/us/album/a/1'],
            ['youtube_music', 'https://music.youtube.com/playlist?list=A'], ['youtube', 'https://www.youtube.com/watch?v=A'],
            ['bandcamp', 'https://synthetic-artist.bandcamp.com/album/a'], ['soundcloud', 'https://soundcloud.com/a/sets/b'],
            ['tidal', 'https://tidal.com/album/1'], ['amazon_music', 'https://music.amazon.com/albums/A'], ['deezer', 'https://www.deezer.com/album/1'],
        ];
        foreach ($good as [$platform, $url]) {
            $this->assertNull(ListeningPlatforms::problem($platform, $url), $platform);
        }
        $this->assertNull(ListeningPlatforms::problem('youtube', 'https://youtu.be/A'));
        $this->assertNull(ListeningPlatforms::problem('tidal', 'https://listen.tidal.com/album/1'));
        $links = array_map(fn (array $pair): array => ['platform' => $pair[0], 'url' => $pair[1]], $good);
        $release = app(AlbumReleases::class)->save(null, $this->data(['listening_links' => $links]), $this->actor);
        $this->assertCount(9, $release->listening_links);
        $twice = [$links[0], ['platform' => 'spotify', 'url' => 'https://open.spotify.com/album/B']];
        $this->assertSame(['listening_links' => ['Use each platform once.']], $this->messages(fn () => app(AlbumReleases::class)->save($release, $this->data(['listening_links' => $twice]), $this->actor)));
    }

    public function test_a_published_release_cannot_be_edited_into_an_unpublishable_state(): void
    {
        $service = app(AlbumReleases::class);
        $release = $this->published();
        $before = $release->revision;
        $errors = $this->messages(fn () => $service->save($release, $this->data(['tracks' => [], 'listening_links' => []], $release->artwork), $this->actor));
        $this->assertSame(['tracks', 'listening_links'], array_keys($errors));
        $this->assertSame($before, $release->refresh()->revision);
        $this->assertCount(2, $release->tracks);
        // A valid edit goes live in place and is audited.
        $edited = $service->save($release, $this->data(['title' => 'Synthetic Release One (edited)'], $release->artwork), $this->actor, $before);
        $this->assertSame('Synthetic Release One (edited)', $edited->title);
        $this->assertSame($before + 1, $edited->revision);
        $this->assertTrue($edited->is_published);
    }

    public function test_stale_edits_and_duplicate_slugs_are_refused(): void
    {
        $service = app(AlbumReleases::class);
        $release = $service->save(null, $this->data(), $this->actor);
        $service->save($release, $this->data(['title' => 'Second']), $this->actor, $release->revision);
        $errors = $this->messages(fn () => $service->save($release, $this->data(['title' => 'Stale']), $this->actor, $release->revision));
        $this->assertSame(['title'], array_keys($errors));
        $this->assertSame('Second', $release->refresh()->title);
        $this->assertSame(['slug' => ['Another release already uses this web address.']], $this->messages(fn () => $service->save(null, $this->data(), $this->actor)));
        foreach (['Bad Slug', 'bad_slug', '-x', 'x-', 'x--y', str_repeat('a', 81), ''] as $slug) {
            $this->assertSame(['slug'], array_keys($this->messages(fn () => $service->save(null, $this->data(['slug' => $slug]), $this->actor))), $slug);
        }
        $this->assertSame(['title'], array_keys($this->messages(fn () => $service->publish($release, 99, $this->actor))));
    }

    public function test_publish_and_unpublish_are_audited_and_tracks_keep_their_order(): void
    {
        $service = app(AlbumReleases::class);
        $release = $service->save(null, $this->data([], $this->cover()), $this->actor);
        $release = $service->publish($release, $release->revision, $this->actor);
        $release = $service->unpublish($release, $release->revision, $this->actor);
        $this->assertFalse($release->is_published);
        $this->assertSame(['albums.release.created', 'albums.release.published', 'albums.release.unpublished'],
            AuditEvent::query()->where('subject_type', AlbumRelease::class)->orderBy('id')->pluck('action')->all());
        $this->assertSame([$this->actor->id], AuditEvent::query()->where('subject_type', AlbumRelease::class)->pluck('actor_id')->unique()->all());
        $reordered = $service->save($release, $this->data(['tracks' => [['title' => 'B first', 'duration_seconds' => 10], ['title' => 'A second', 'duration_seconds' => 20]]], $release->artwork), $this->actor);
        $this->assertSame(['B first', 'A second'], $reordered->tracks->pluck('title')->all());
        $this->assertSame([1, 2], $reordered->tracks->pluck('position')->all());
    }

    public function test_input_is_plain_text_with_bounded_lengths(): void
    {
        $service = app(AlbumReleases::class);
        $cases = [
            ['title', '<b>x</b>'], ['title', "two\nlines"], ['title', str_repeat('a', 161)], ['artist_name', ''], ['release_type', 'boxset'],
            ['release_date', '2024-02-30'], ['release_date', '1900-01-01'], ['description', '<script>'], ['description', str_repeat('a', 2001)],
            ['tracks', [['title' => 'x', 'duration_seconds' => 0]]], ['tracks', [['title' => 'x', 'duration_seconds' => 21601]]],
            ['tracks', [['title' => '', 'duration_seconds' => 10]]], ['tracks', [['title' => 'x', 'duration_seconds' => '10']]],
        ];
        foreach ($cases as [$field, $value]) {
            $this->assertSame([$field], array_keys($this->messages(fn () => $service->save(null, $this->data([$field => $value]), $this->actor))), $field.json_encode($value));
        }
        $this->assertSame(0, AlbumRelease::count());
        $this->expectException(ValidationException::class);
        $service->save(null, ['title' => 'x'], $this->actor);
    }

    public function test_only_enrolled_staff_with_catalog_authority_can_write(): void
    {
        $service = app(AlbumReleases::class);
        $release = $service->save(null, $this->data(), $this->actor);
        $customer = User::factory()->create();
        foreach ([fn () => $service->save(null, $this->data(['slug' => 'denied']), $customer), fn () => $service->publish($release, $release->revision, $customer),
            fn () => $service->unpublish($release, $release->revision, $customer)] as $operation) {
            try {
                $operation();
                $this->fail('A non-staff user changed a release.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->actingAs($customer);
        $this->get('/admin/album-releases')->assertForbidden();
        $this->assertSame(1, AlbumRelease::count());

        $this->actingAs($this->actor);
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            try {
                $service->save(null, $this->data(['slug' => 'no-mfa']), $this->actor);
                $this->fail('Staff without enrolled MFA wrote a release.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('Admin multi-factor authentication is required.', $exception->getMessage());
            }
            Livewire::test(ManageAlbumReleases::class)->assertForbidden();
            $this->assertSame(1, AlbumRelease::count());
            $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            Livewire::test(ManageAlbumReleases::class)->assertOk();
            $this->assertSame('mfa-ok', $service->save(null, $this->data(['slug' => 'mfa-ok']), $this->actor)->slug);
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_the_filament_editor_creates_edits_publishes_and_unpublishes_through_the_service(): void
    {
        $this->actingAs($this->actor);
        $cover = $this->cover();
        $form = ['title' => 'Synthetic Editor Release', 'artist_name' => 'Synthetic Artist', 'slug' => 'synthetic-editor-release', 'release_type' => 'single',
            'release_date' => '2024-07-04', 'description' => null, 'artwork_image_id' => $cover->id, 'artwork_alt' => 'Synthetic cover',
            'listening_links' => [['platform' => 'bandcamp', 'url' => 'https://synthetic.bandcamp.com/track/a']],
            'tracks' => [['title' => 'One', 'duration' => '3:05'], ['title' => 'Two', 'duration' => '1:00:00']]];
        Livewire::test(ManageAlbumReleases::class)->callAction('create', data: $form)->assertHasNoActionErrors();
        $release = AlbumRelease::sole();
        $this->assertSame([185, 3600], $release->tracks->pluck('duration_seconds')->all());
        $this->assertFalse($release->is_published);

        Livewire::test(ManageAlbumReleases::class)->callAction('create', data: ['slug' => 'Bad Slug'] + $form)->assertHasActionErrors(['slug']);
        Livewire::test(ManageAlbumReleases::class)->callAction('create', data: ['slug' => 'second', 'tracks' => [['title' => 'One', 'duration' => 'abc']]] + $form)->assertHasActionErrors(['tracks']);
        Livewire::test(ManageAlbumReleases::class)->callAction('create', data: ['slug' => 'third', 'listening_links' => [['platform' => 'spotify', 'url' => 'http://open.spotify.com/x']]] + $form)->assertHasActionErrors(['listening_links']);
        $this->assertSame(1, AlbumRelease::count());

        Livewire::test(ManageAlbumReleases::class)->callTableAction('edit', $release, data: ['title' => 'Synthetic Editor Release v2'] + $form)->assertHasNoTableActionErrors();
        $this->assertSame('Synthetic Editor Release v2', $release->refresh()->title);
        Livewire::test(ManageAlbumReleases::class)->callTableAction('publish', $release)->assertNotified('Release published');
        $this->assertTrue($release->refresh()->is_published);
        Livewire::test(ManageAlbumReleases::class)->assertTableActionHidden('publish', $release)->assertTableActionVisible('unpublish', $release);
        Livewire::test(ManageAlbumReleases::class)->callTableAction('unpublish', $release)->assertNotified('Release unpublished');
        $this->assertFalse($release->refresh()->is_published);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'like', 'albums.%')->whereNull('actor_id')->count());
    }

    public function test_the_filament_editor_refuses_a_save_or_confirmation_made_against_a_revision_changed_since_it_was_opened(): void
    {
        $this->actingAs($this->actor);
        $release = $this->draftReady();
        $form = fn (AlbumRelease $r): array => ['title' => $r->title, 'artist_name' => $r->artist_name, 'slug' => $r->slug, 'release_type' => $r->release_type->value,
            'release_date' => $r->release_date->format('Y-m-d'), 'description' => null, 'artwork_image_id' => $r->artwork_image_id, 'artwork_alt' => $r->artwork_alt,
            'listening_links' => array_values($r->listening_links ?? []),
            'tracks' => $r->tracks->map(fn ($t): array => ['title' => $t->title, 'duration' => intdiv($t->duration_seconds, 60).':'.str_pad((string) ($t->duration_seconds % 60), 2, '0', STR_PAD_LEFT)])->all()];

        // Edit: A opens the dialog, B saves, A submits what it saw.
        $a = Livewire::test(ManageAlbumReleases::class)->mountTableAction('edit', $release);
        $this->bump($release, 'Synthetic title by B');
        $a->setTableActionData(['title' => 'Synthetic title by A'] + $form($release->refresh()))->callMountedTableAction()->assertHasTableActionErrors();
        $this->assertSame('Synthetic title by B', $release->refresh()->title);

        // Publish: A opens the confirmation (ready), B edits the title, A confirms.
        $a = Livewire::test(ManageAlbumReleases::class)->mountTableAction('publish', $release);
        $this->bump($release, 'Synthetic title unreviewed');
        $a->callMountedTableAction();
        $this->assertFalse($release->refresh()->is_published);
        $this->assertSame('Synthetic title unreviewed', $release->title);

        // The same confirmation against the current revision succeeds, and unpublish is guarded the same way.
        Livewire::test(ManageAlbumReleases::class)->callTableAction('publish', $release)->assertNotified('Release published');
        $a = Livewire::test(ManageAlbumReleases::class)->mountTableAction('unpublish', $release);
        app(AlbumReleases::class)->unpublish($release->refresh(), $release->revision, $this->actor);
        app(AlbumReleases::class)->publish($release->refresh(), $release->revision, $this->actor);
        $a->callMountedTableAction();
        $this->assertTrue($release->refresh()->is_published);
    }

    public function test_cover_art_is_served_only_for_a_published_release_with_the_page_on_and_the_reviewed_bytes(): void
    {
        $release = $this->published();
        $variant = $release->artwork->variants->firstWhere('format', 'jpeg');
        $webp = $release->artwork->variants->firstWhere('format', 'webp');
        $this->get('/site-images/'.$variant->sha256.'.jpg')->assertNotFound();
        config(['albums.enabled' => true]);
        $this->get('/site-images/'.$variant->sha256.'.jpg')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/site-images/'.$webp->sha256.'.webp')->assertOk()->assertHeader('Content-Type', 'image/webp');
        // A cover that is not attached to a published release stays private, even though it is a ready release_cover image.
        $unused = $this->cover();
        $unusedVariant = $unused->variants->firstWhere('format', 'jpeg');
        if ($unusedVariant->sha256 !== $variant->sha256) {
            $this->get('/site-images/'.$unusedVariant->sha256.'.jpg')->assertNotFound();
        }
        app(AlbumReleases::class)->unpublish($release, $release->revision, $this->actor);
        $this->get('/site-images/'.$variant->sha256.'.jpg')->assertNotFound();
    }

    public function test_structured_data_is_a_music_album_only_in_production_and_only_with_the_page_on(): void
    {
        $this->published();
        config(['albums.enabled' => true]);
        $this->assertNull($this->get('/albums/synthetic-release-one', $this->inertia())->json('props.metadata.structuredData'));
        $this->production();
        $html = $this->get('/albums/synthetic-release-one')->assertOk();
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $nodes = (new DOMXPath($dom))->query('//script[@type="application/ld+json"]');
        $this->assertCount(1, $nodes);
        $raw = $nodes->item(0)->textContent;
        $this->assertSame($raw, $this->get('/albums/synthetic-release-one', $this->inertia())->json('props.metadata.structuredData'));
        $this->assertMatchesRegularExpression('/\A[\x20-\x7E]+\z/', $raw);
        $graph = json_decode($raw, true, 16, JSON_THROW_ON_ERROR)['@graph'];
        $album = $graph[0];
        $this->assertSame('MusicAlbum', $album['@type']);
        $this->assertSame('https://audio.example.test/albums/synthetic-release-one', $album['url']);
        $this->assertSame('https://schema.org/AlbumRelease', $album['albumReleaseType']);
        $this->assertSame('2024-03-01', $album['datePublished']);
        $this->assertSame(['@type' => 'MusicGroup', 'name' => 'Synthetic Artist'], $album['byArtist']);
        $this->assertSame(2, $album['numTracks']);
        $this->assertSame([['MusicRecording', 1, 'Synthetic Track A', 'PT3M45S'], ['MusicRecording', 2, 'Synthetic Track B', 'PT1H2M5S']],
            array_map(fn (array $track): array => [$track['@type'], $track['position'], $track['name'], $track['duration']], $album['track']));
        $this->assertSame(['https://open.spotify.com/album/SYNTHETIC1'], $album['sameAs']);
        $this->assertStringStartsWith('https://audio.example.test/site-images/', $album['image']);
        $this->assertArrayNotHasKey('offers', $album);
        $this->assertSame('BreadcrumbList', $graph[1]['@type']);
        $this->assertSame(['VASEY.AUDIO', 'Albums', 'Synthetic Release One'], array_column($graph[1]['itemListElement'], 'name'));
        $this->assertStringNotContainsString('VASEY/AI', $raw);
        // The index carries no structured data rather than an invented type.
        $this->assertNull($this->get('/albums', $this->inertia())->json('props.metadata.structuredData'));
        config(['albums.enabled' => false]);
        $this->get('/albums/synthetic-release-one')->assertNotFound();
    }

    public function test_structured_data_escapes_markup_characters_in_staff_text(): void
    {
        $this->published(['title' => 'Tom & Jerry', 'artist_name' => "Synthetic 'Artist' & Co", 'description' => 'Fish "n" chips & more']);
        config(['albums.enabled' => true]);
        $this->production();
        $raw = $this->get('/albums/synthetic-release-one', $this->inertia())->json('props.metadata.structuredData');
        $this->assertDoesNotMatchRegularExpression('/[<>&]/', $raw);
        $this->assertSame('Tom & Jerry', json_decode($raw, true)['@graph'][0]['name']);
    }

    public function test_sitemap_lists_published_releases_only_when_the_page_is_on(): void
    {
        $this->published(['slug' => 'older', 'release_date' => '2020-01-01']);
        $draftService = app(AlbumReleases::class);
        $draftService->save(null, $this->data(['slug' => 'a-draft']), $this->actor);
        $this->published(['slug' => 'newer', 'release_date' => '2025-01-01']);
        $this->production();
        $this->get('/site-pages-sitemap.xml')->assertOk()->assertDontSee('albums', false);
        config(['albums.enabled' => true]);
        $xml = simplexml_load_string($this->get('/site-pages-sitemap.xml')->assertOk()->getContent());
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urls = array_map(fn ($loc) => (string) $loc, $xml->xpath('/s:urlset/s:url/s:loc'));
        $this->assertSame(['https://audio.example.test/', 'https://audio.example.test/albums', 'https://audio.example.test/albums/newer', 'https://audio.example.test/albums/older'], $urls);
    }

    public function test_an_empty_catalog_shows_an_honest_empty_state_with_the_page_on(): void
    {
        config(['albums.enabled' => true]);
        $this->assertSame([], $this->get('/albums', $this->inertia())->assertOk()->json('props.albums'));
        $this->production();
        $this->get('/site-pages-sitemap.xml')->assertOk()->assertDontSee('/albums', false);
    }

    public function test_retention_and_previews_stay_separate(): void
    {
        config(['albums.enabled' => true]);
        $content = SiteContentSchema::defaults();
        $this->assertContains('/albums', array_column(app(EditorialContent::class)->chrome($content)['navigation'], 'href'));
        $this->assertNotContains('/albums', array_column(app(EditorialContent::class)->chrome($content, families: false)['navigation'], 'href'));
        $this->assertArrayHasKey('album_releases', RetentionSchedule::NOT_CUSTOMER_DATA);
        $this->assertArrayHasKey('album_release_tracks', RetentionSchedule::NOT_CUSTOMER_DATA);
    }
}
