<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublicCatalog;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Media\BindStemsToRecording;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Models\User;
use App\Support\PublicReadMemo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ExclusiveSelectionFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

/**
 * Staging, 2026-11-11: with 97 published tracks and about 290 offers, `/` ran 3,113 queries (11.8 s) because every eligibility check
 * re-read the same assets, processing runs, licenses and rights once per offer, snapshot entry and page section. A public render now
 * shares those reads within one request, so its query count grows by a small constant per listed track instead.
 */
class PublicCatalogQueryCostTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['cache.default' => 'database']);
        Cache::setDefaultDriver('database');
    }

    /** @return array{actor: User, licenses: array<string, LicenseVersion>} */
    private function store(): array
    {
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $reviewer = LicenseFixtures::admin();
        $licenses = [];
        foreach (['wav' => 'master_wav', 'mp3' => 'download_mp3', 'stems' => 'stems_zip'] as $tier => $role) {
            $licenses[$tier] = LicenseFixtures::published($actor, $reviewer, ['schema_version' => 1, 'features' => ['Synthetic '.$tier], 'required_asset_roles' => [$role]]);
        }

        return compact('actor', 'licenses');
    }

    /** A published track with three offers that share the store's license tiers, one of them a stems ZIP bound to the master. */
    private function track(array $store, int $index): Track
    {
        ['actor' => $actor, 'licenses' => $licenses] = $store;
        $track = Track::create(['title' => 'Synthetic query cost '.$index, 'slug' => 'query-cost-'.$index.'-'.Str::lower(Str::random(8)),
            'artist' => 'Test only', 'bpm' => 90 + $index, 'musical_key' => 'C minor', 'genre' => 'Test']);
        RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'TEST-ONLY-QUERY-COST', 'sample_disclosure' => 'Synthetic source',
            'status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
        $media = MediaFixtures::readyTrackMedia($track, $actor);
        $source = MediaFixtures::source($track, 'stems_zip', StemsFixtures::zip([['name' => 'Parts/Synthetic.wav']]));
        $stems = app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($source, $actor)->id)->outputs()->sole();
        app(BindStemsToRecording::class)->handle($stems, ['master_asset_id' => $media['master_wav']->id, 'preview_asset_id' => $media['preview_tagged']->id,
            'verification_reference' => 'SYNTHETIC-QUERY-COST-STEMS', 'same_recording_confirmed' => true], $actor);
        foreach (['wav' => $media['master_wav'], 'mp3' => $media['download_mp3'], 'stems' => $stems] as $tier => $asset) {
            $offer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id, 'license_version_id' => $licenses[$tier]->id,
                'price_minor' => 2999, 'currency' => 'USD', 'deliverable_asset_ids' => [$asset->id]], $actor);
            app(PublishOffer::class)->handle($offer, $actor);
        }

        return app(PublishTrack::class)->handle($track, $actor);
    }

    /** @return array<string, int> queries per public render */
    private function measure(Track $track, Track $exclusive, int $listed): array
    {
        $counts = [];
        $queries = null;
        DB::listen(function () use (&$queries): void {
            if ($queries !== null) {
                $queries++;
            }
        });
        foreach (['home' => '/', 'catalog' => '/api/catalog', 'track' => '/tracks/'.$track->slug, 'embed' => '/embed/tracks/'.$track->slug,
            'exclusive' => '/tracks/'.$exclusive->slug] as $name => $uri) {
            Cache::flush();
            $queries = 0;
            $response = $this->get($uri)->assertOk();
            $counts[$name] = $queries;
            $queries = null;
            if ($name === 'catalog') {
                $this->assertCount($listed, $response->json('tracks'));
            }
        }

        return $counts;
    }

    public function test_public_render_queries_grow_by_a_small_constant_per_listed_track(): void
    {
        ExclusiveSelectionFixtures::configure();
        $exclusive = ExclusiveSelectionFixtures::active()['track'];
        $store = $this->store();
        $tracks = [];
        for ($i = 0; $i < 3; $i++) {
            $tracks[] = $this->track($store, $i);
        }
        $small = $this->measure($tracks[0], $exclusive, 4);
        for ($i = 3; $i < 8; $i++) {
            $tracks[] = $this->track($store, $i);
        }
        $large = $this->measure($tracks[0], $exclusive, 9);

        // Before PublicReadMemo and ListingPrefetch (main 67b4c020): home 400 -> 940 queries for 4 -> 9 listed tracks, 108 per track.
        foreach (['home', 'catalog', 'track', 'exclusive'] as $page) {
            $this->assertLessThanOrEqual(8 * 5, $large[$page] - $small[$page], $page.' grew by more than 8 queries per listed track: '.json_encode([$small, $large]));
            $this->assertLessThanOrEqual(160, $large[$page], $page.' took '.$large[$page].' queries for 9 listed tracks.');
        }
        // An embed judges one track, whatever the catalog holds.
        $this->assertSame($small['embed'], $large['embed']);
        $this->assertLessThanOrEqual(60, $large['embed']);
    }

    /** Runs $callback and returns how many statements it executed. */
    private function queries(callable $callback): int
    {
        $count = 0;
        $counting = true;
        DB::listen(function () use (&$count, &$counting): void {
            if ($counting) {
                $count++;
            }
        });
        try {
            $callback();
        } finally {
            $counting = false;
        }

        return $count;
    }

    public function test_a_write_inside_a_public_read_scope_is_seen_by_the_next_check(): void
    {
        $store = $this->store();
        $track = $this->track($store, 1);
        $readiness = app(PublicationReadiness::class);

        PublicReadMemo::scope(function () use ($readiness, $track): void {
            $this->assertSame([], $readiness->listingBlockers($track));
            $this->assertSame(0, $this->queries(fn () => $this->assertSame([], $readiness->listingBlockers($track))), 'The second check reads nothing.');
            // A newer, unverified rights declaration: the memoized latest declaration is now stale and must not be used.
            RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'TEST-ONLY-NEWER', 'sample_disclosure' => 'Synthetic source', 'status' => 'pending']);
            $this->assertContains('The latest rights declaration must be verified.', $readiness->listingBlockers($track));
        });

        $track = $this->track($store, 2);
        PublicReadMemo::scope(function () use ($track): void {
            $this->assertCount(1, app(PublicCatalog::class)->selections([$track->id])['tracks']);
            // A raw statement through the query builder empties the memo too.
            DB::table('offers')->where('track_id', $track->id)->update(['is_active' => false]);
            $this->assertSame([], app(PublicCatalog::class)->selections([$track->id])['tracks']);
        });
    }

    public function test_full_checks_and_transactions_never_use_the_memo(): void
    {
        $store = $this->store();
        $track = $this->track($store, 1);
        $readiness = app(PublicationReadiness::class);

        // Outside a scope the full check reads everything every time.
        $first = $this->queries(fn () => $this->assertSame([], $readiness->blockers($track)));
        $this->assertGreaterThan(20, $first);
        $this->assertSame($first, $this->queries(fn () => $this->assertSame([], $readiness->blockers($track))));

        PublicReadMemo::scope(function () use ($readiness, $track, $first): void {
            $this->assertSame([], $readiness->listingBlockers($track));
            $this->assertTrue(PublicReadMemo::active());
            DB::transaction(function () use ($readiness, $track, $first): void {
                $this->assertFalse(PublicReadMemo::active());
                // Inside a transaction (where every locking read lives) the same check reads everything again.
                $this->assertSame($first, $this->queries(fn () => $this->assertSame([], $readiness->blockers($track))));
                $this->assertSame(2, PublicReadMemo::remember('probe', fn () => 2));
                $this->assertSame(3, PublicReadMemo::remember('probe', fn () => 3));
            });
        });
    }

    public function test_the_memo_ends_with_its_scope_and_follows_in_memory_changes(): void
    {
        $this->assertSame(1, PublicReadMemo::scope(fn () => PublicReadMemo::remember('probe', fn () => 1)));
        $this->assertSame(2, PublicReadMemo::scope(fn () => PublicReadMemo::remember('probe', fn () => 2)), 'Nothing survives the scope.');
        $this->assertSame(3, PublicReadMemo::remember('probe', fn () => 3));
        $this->assertSame(4, PublicReadMemo::remember('probe', fn () => 4), 'Outside a scope nothing is kept.');

        $store = $this->store();
        $track = $this->track($store, 1);
        $preview = $track->assets()->where('role', 'preview_tagged')->sole();
        PublicReadMemo::scope(function () use ($preview): void {
            $verified = app(VerifiedMedia::class);
            $this->assertTrue($verified->available($preview));
            $this->assertNotNull($verified->evidence($preview));
            // An unsaved change to the model is judged afresh, never answered with the earlier decision.
            $preview->status = 'superseded';
            $this->assertFalse($verified->available($preview));
            $this->assertNull($verified->evidence($preview));
        });
    }

    public function test_batched_reads_hide_exactly_what_single_reads_hide(): void
    {
        $store = $this->store();
        [$intact, $newerRights, $noOffers] = [$this->track($store, 1), $this->track($store, 2), $this->track($store, 3)];
        // The latest declaration decides, not the first one: a newer unverified declaration hides the track.
        RightsDeclaration::create(['track_id' => $newerRights->id, 'provenance_reference' => 'TEST-ONLY-NEWER', 'sample_disclosure' => 'Synthetic source', 'status' => 'pending']);
        DB::table('offers')->where('track_id', $noOffers->id)->update(['is_active' => false]);

        $this->assertSame([$intact->slug], array_column($this->getJson('/api/catalog')->assertOk()->json('tracks'), 'slug'));
        $this->assertSame([$intact->slug], array_column(app(PublicCatalog::class)->selections([$intact->id, $newerRights->id, $noOffers->id])['tracks'], 'slug'));
        $this->get('/tracks/'.$newerRights->slug)->assertNotFound();
        $this->get('/tracks/'.$noOffers->slug)->assertNotFound();
        $this->get('/tracks/'.$intact->slug)->assertOk();
    }

    public function test_a_batch_read_older_than_a_write_is_not_kept(): void
    {
        PublicReadMemo::scope(function (): void {
            $before = PublicReadMemo::generation();
            PublicReadMemo::prime('probe', 1, $before);
            $this->assertSame(1, PublicReadMemo::remember('probe', fn () => 2));
            DB::table('offers')->where('id', 0)->update(['is_active' => false]);
            PublicReadMemo::prime('probe', 3, $before);
            $this->assertSame(4, PublicReadMemo::remember('probe', fn () => 4), 'The write emptied the memo and the older batch was not kept.');
        });
    }

    public function test_only_plain_reads_and_cache_table_writes_keep_the_memo(): void
    {
        foreach (['select * from "media_assets" where "id" = ?', '  SELECT 1', 'select * from `cache` where `key` in (?)'] as $read) {
            $this->assertFalse(PublicReadMemo::writes($read), $read);
        }
        foreach (['insert into "cache" ("key") values (?)', 'delete from `cache` where `key` = ?', 'update "cache_locks" set "owner" = ?',
            'insert or ignore into "cache" ("key") values (?)'] as $cache) {
            $this->assertFalse(PublicReadMemo::writes($cache), $cache);
        }
        $this->assertFalse(PublicReadMemo::writes('insert into "app_cache" ("key") values (?)', 'app_'));
        foreach ([['insert into "rights_declarations" ("track_id") values (?)', ''], ['update `offers` set `is_active` = ?', ''],
            ['delete from "media_assets" where "id" = ?', ''], ['insert into "cache_shadow" ("key") values (?)', ''],
            ['insert into "cache" ("key") values (?)', 'app_'], ['savepoint trans2', ''], ['PRAGMA foreign_keys = ON', ''],
            ['with x as (select 1) update "offers" set "is_active" = 0', '']] as [$sql, $prefix]) {
            $this->assertTrue(PublicReadMemo::writes($sql, $prefix), $sql);
        }
    }
}
