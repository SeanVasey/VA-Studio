<?php

namespace Tests\Feature;

use App\Application\Media\MediaIntegrity;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\VerifyOfferFiles;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteSelection;
use App\Domain\Media\BindStemsToRecording;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\VerifiedMedia;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

/**
 * Staging, 2026-11-11: every public render re-hashed every pinned deliverable (masters, delivery MP3s, stems ZIPs) of every candidate
 * track. Public listing now checks private deliverables from stat identity and recorded mismatches only; anything that sells, publishes
 * or hands over bytes still hashes them in full.
 */
class PublicCatalogIntegrityCostTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    /** @var object{calls: list<array{id: int, role: string}>} */
    private object $integrity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        // Each test owns the mismatch store's directory, so no record outlives it or leaks into another test.
        $mismatches = sys_get_temp_dir().'/media-integrity-'.Str::uuid();
        config(['cache.stores.'.MediaIntegrity::MISMATCH_STORE.'.path' => $mismatches, 'cache.stores.'.MediaIntegrity::MISMATCH_STORE.'.lock_path' => $mismatches]);
        $this->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($mismatches));
    }

    /** Counts every full integrity check (the only caller of hash_file for catalog media) while delegating to the real service. */
    private function countHashing(): void
    {
        $this->integrity = new class
        {
            public array $calls = [];

            public function matches(MediaAsset $asset, string $path): bool
            {
                $this->calls[] = ['id' => $asset->id, 'role' => $asset->role];

                return (new MediaIntegrity)->matches($asset, $path);
            }

            public function plausible(MediaAsset $asset, string $path): bool
            {
                return (new MediaIntegrity)->plausible($asset, $path);
            }
        };
        $this->app->instance(MediaIntegrity::class, $this->integrity);
    }

    private function privateHashes(): array
    {
        return array_values(array_filter($this->integrity->calls, fn (array $call): bool => ! in_array($call['role'], ['artwork', ...MediaAsset::PREVIEW_ROLES], true)));
    }

    /** A published track whose only offer delivers a stems ZIP bound to the track's master, so the stems recording binding is exercised too. */
    private function stemsTrack(): array
    {
        $fixture = QuoteFixtures::selection();
        ['actor' => $actor, 'track' => $track] = $fixture;
        $track = app(PublishTrack::class)->unpublish($track, $actor);
        $source = MediaFixtures::source($track, 'stems_zip', StemsFixtures::zip([['name' => 'Parts/Synthetic.wav']]));
        $run = app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($source, $actor)->id);
        $stems = $run->outputs()->sole();
        app(BindStemsToRecording::class)->handle($stems, ['master_asset_id' => $fixture['media']['master_wav']->id,
            'preview_asset_id' => $fixture['media']['preview_tagged']->id,
            'verification_reference' => 'SYNTHETIC-LISTING-STEMS', 'same_recording_confirmed' => true], $actor);
        $license = LicenseFixtures::published($actor, terms: ['schema_version' => 1, 'features' => ['Synthetic stems'], 'required_asset_roles' => ['stems_zip']]);
        app(SaveOfferDraft::class)->handle($fixture['offer'], ['license_version_id' => $license->id, 'deliverable_asset_ids' => [$stems->id]], $actor);
        app(PublishOffer::class)->handle($fixture['offer'], $actor);
        $fixture['track'] = app(PublishTrack::class)->handle($track, $actor);
        $fixture['media']['stems_zip'] = $stems;

        return $fixture;
    }

    /** Same size, different bytes, original modification time: only a full hash can tell. */
    private function tamper(MediaAsset $asset): void
    {
        $path = Storage::disk('local')->path($asset->storage_path);
        $bytes = file_get_contents($path);
        $mtime = filemtime($path);
        $mode = fileperms($path) & 0777;
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        chmod($path, 0600);
        try {
            $this->assertSame(strlen($bytes), file_put_contents($path, $bytes));
            $this->assertTrue(touch($path, $mtime));
        } finally {
            chmod($path, $mode);
        }
        clearstatcache(true, $path);
    }

    private function catalogSlugs(): array
    {
        return array_column($this->getJson('/api/catalog')->assertOk()->json('tracks'), 'slug');
    }

    public function test_public_listing_track_pages_and_public_media_never_hash_private_deliverables(): void
    {
        $tracks = [QuoteFixtures::selection(), QuoteFixtures::selection(), $this->stemsTrack()];
        Cache::flush();
        $this->countHashing();

        $this->get('/')->assertOk();
        $this->assertEqualsCanonicalizing(array_map(fn (array $f): string => $f['track']->slug, $tracks), $this->catalogSlugs());
        foreach ($tracks as $fixture) {
            $this->get('/tracks/'.$fixture['track']->slug)->assertOk();
            $this->get('/embed/tracks/'.$fixture['track']->slug)->assertOk();
            $this->get(route('media.public', $fixture['media']['artwork']->id, false))->assertOk();
            $this->get(route('media.public', $fixture['media']['preview_tagged']->id, false))->assertOk();
        }
        $this->postJson('/catalog/selections', ['trackIds' => array_map(fn (array $f): int => $f['track']->id, $tracks)])->assertOk()->assertJsonCount(3, 'tracks');

        $this->assertNotSame([], $this->integrity->calls, 'Served public files are still hashed in full.');
        $this->assertSame([], $this->privateHashes(), 'A public render hashed a private deliverable.');

        // The full readiness check, used by checkout, publication and the scheduler, still hashes every pinned deliverable.
        $this->assertSame([], app(PublicationReadiness::class)->blockers($tracks[2]['track']->fresh()));
        $hashed = array_column($this->privateHashes(), 'role');
        $this->assertContains('stems_zip', $hashed);
        $this->assertContains('master_wav', $hashed);
    }

    public function test_a_same_size_tamper_is_refused_by_checkout_activation_and_serving_and_then_hidden_from_listing(): void
    {
        // As on staging and production: the default store is the database, so it shares the checkout transaction below.
        config(['cache.default' => 'database']);
        Cache::setDefaultDriver('database');
        $fixture = QuoteFixtures::selection();
        $master = $fixture['media']['master_wav'];
        $this->tamper($master);
        Cache::flush();
        Cache::store(MediaIntegrity::MISMATCH_STORE)->flush();
        $this->countHashing();

        // Unproven but stat-identical: listing shows it, without reading it. Nothing below sells or hands it over.
        $this->assertSame([$fixture['track']->slug], $this->catalogSlugs());
        $this->assertSame([], $this->privateHashes());

        // Checkout resolves the selection inside a transaction, which its refusal rolls back. The mismatch the full hash found must
        // survive that rollback: the listing hides the track at once, before anything else hashes the file.
        try {
            DB::transaction(fn () => app(QuoteSelection::class)->resolve($fixture['items'], false));
            $this->fail('Checkout accepted a tampered deliverable.');
        } catch (QuoteException $error) {
            $this->assertSame('SELECTION_CHANGED', $error->errorCode);
        }
        $this->assertSame(0, DB::transactionLevel());
        $hashed = count($this->privateHashes());
        $this->assertGreaterThan(0, $hashed, 'Checkout hashes the deliverable in full.');
        $this->assertSame([], $this->catalogSlugs());
        $this->get('/tracks/'.$fixture['track']->slug)->assertNotFound();
        $this->assertCount($hashed, $this->privateHashes(), 'The listing hid the track without hashing it again.');

        // Every other path that sells or hands over the bytes still refuses it.
        try {
            DB::transaction(fn () => app(QuoteSelection::class)->resolve($fixture['items'], true));
            $this->fail('Checkout with fresh digests accepted a tampered deliverable.');
        } catch (QuoteException $error) {
            $this->assertSame('SELECTION_CHANGED', $error->errorCode);
        }
        try {
            app(VerifyOfferFiles::class)->revision($fixture['revision']);
            $this->fail('Activation accepted a tampered deliverable.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('offer', $error->errors());
        }
        $this->assertNull(app(VerifiedMedia::class)->path($master), 'Bytes of a tampered file must never be handed over.');
        $this->assertNotEmpty(app(PublicationReadiness::class)->blockers($fixture['track']->fresh()));
    }

    public function test_publication_still_refuses_a_same_size_tamper(): void
    {
        $fixture = QuoteFixtures::selection();
        $track = app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        $this->tamper($fixture['media']['master_wav']);
        Cache::flush();
        try {
            app(PublishTrack::class)->handle($track, $fixture['actor']);
            $this->fail('Publication accepted a tampered deliverable.');
        } catch (ValidationException) {
            $this->assertSame('draft', $track->fresh()->status);
        }
    }

    public function test_listing_hides_a_missing_or_resized_deliverable_without_hashing(): void
    {
        $missing = QuoteFixtures::selection();
        $resized = QuoteFixtures::selection();
        $intact = QuoteFixtures::selection();
        Cache::flush();
        $this->countHashing();
        $this->assertCount(3, $this->catalogSlugs());

        Storage::disk('local')->delete($missing['media']['master_wav']->storage_path);
        $path = Storage::disk('local')->path($resized['media']['master_wav']->storage_path);
        chmod($path, 0600);
        file_put_contents($path, 'x', FILE_APPEND);

        $this->assertSame([$intact['track']->slug], $this->catalogSlugs());
        $this->get('/tracks/'.$missing['track']->slug)->assertNotFound();
        $this->get('/tracks/'.$resized['track']->slug)->assertNotFound();
        $this->get(route('media.public', $resized['media']['artwork']->id, false))->assertNotFound();
        $this->assertSame([], $this->privateHashes());
    }
}
