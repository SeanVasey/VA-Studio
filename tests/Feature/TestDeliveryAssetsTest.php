<?php

namespace Tests\Feature;

use App\Application\Media\MediaIntegrity;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Delivery\DeliveryAssets;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\MediaEvidenceValues;
use App\Domain\Media\BindStemsToRecording;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\RecordingAssociation;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\RecordingFixtures;
use Tests\TestCase;

class TestDeliveryAssetsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp(); $this->fakePrivateMediaStorage();
        chmod(Storage::disk('local')->path(''), 0700);
    }

    private function original(OfferRevision $revision): array
    {
        return ['lines' => [['offer_revision_id' => $revision->id, 'selection' => ['offer_snapshot' => $revision->snapshot]]]];
    }

    private function failure(string $reason, callable $operation): void
    {
        try { $operation(); $this->fail('Changed asset evidence was accepted.'); }
        catch (DeliveryException $error) { $this->assertSame($reason, $error->reason); }
    }

    public function test_db_only_capture_is_deterministic_and_deduplicates_exact_historical_assets(): void
    {
        $fixture = QuoteFixtures::selection(); $original = $this->original($fixture['revision']);
        $assets = app(DeliveryAssets::class); $evidence = $assets->inspect($original);
        $this->assertCount(1, $evidence);
        $this->assertSame(MediaEvidenceValues::reference($fixture['media']['master_wav']->technical_metadata), $evidence[0]['provenance']['asset']['technical_metadata']);
        $this->assertArrayNotHasKey('value', $evidence[0]['provenance']['asset']['technical_metadata']);
        $this->assertIsString(CanonicalJson::encode($evidence));
        $this->assertSame($fixture['media']['master_wav']->id, $evidence[0]['id']);
        $this->assertSame(CanonicalJson::hash($original['lines'][0]['selection']['offer_snapshot']['assets'][0]), $evidence[0]['descriptor_hash']);
        $assets->verify($evidence);
        $original['lines'][] = $original['lines'][0];
        $this->assertSame($evidence, $assets->inspect($original));
        $this->app->instance(PrivateMediaFiles::class, new class extends PrivateMediaFiles {
            public int $calls = 0;
            public function resolve(string $relative): string { $this->calls++; throw new \LogicException('Physical I/O is forbidden during capture.'); }
        });
        $before = json_encode($evidence, JSON_THROW_ON_ERROR);
        $captured = DB::transaction(fn () => $assets->inspect($original));
        $this->assertSame($before, json_encode($captured, JSON_THROW_ON_ERROR));
        $this->assertSame(0, app(PrivateMediaFiles::class)->calls);
        $this->assertNull(app(VerifiedMedia::class)->path($fixture['media']['master_wav']));
        $this->assertSame(1, app(PrivateMediaFiles::class)->calls);
    }

    public function test_warm_integrity_success_cannot_hide_same_size_purchased_asset_corruption(): void
    {
        $fixture = QuoteFixtures::selection(); $asset = $fixture['media']['master_wav'];
        $evidence = app(DeliveryAssets::class)->inspect($this->original($fixture['revision']));
        $path = Storage::disk('local')->path($asset->storage_path); $original = file_get_contents($path);
        $this->assertTrue(app(MediaIntegrity::class)->matches($asset, $path));
        // Force the pre-existing bounded-cache success to model corruption inside that same window.
        Cache::partialMock()->shouldReceive('get')->withArgs(fn (string $key) => str_starts_with($key, 'media-integrity:'))->andReturn(true);
        chmod($path, 0600); file_put_contents($path, str_repeat('x', strlen($original))); chmod($path, 0400);
        $this->assertTrue(app(MediaIntegrity::class)->matches($asset, $path));
        $this->assertSame($evidence, app(DeliveryAssets::class)->inspect($this->original($fixture['revision'])));
        $this->failure('asset_unavailable', fn () => app(DeliveryAssets::class)->verify($evidence));
        chmod($path, 0600); file_put_contents($path, $original); chmod($path, 0400);
        app(DeliveryAssets::class)->verify($evidence);
        $this->assertSame($original, file_get_contents($path));
    }

    public function test_frozen_revision_is_not_replaced_by_a_later_processed_source_or_current_catalog_changes(): void
    {
        $fixture = QuoteFixtures::selection(); $original = $this->original($fixture['revision']);
        $evidence = app(DeliveryAssets::class)->inspect($original);
        $fixture['track']->update(['title' => 'New catalog metadata', 'status' => 'draft']);
        $newSource = MediaFixtures::source($fixture['track'], bytes: MediaFixtures::wav(1.2, 990));
        $newRun = app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($newSource, $fixture['actor'])->id);
        $replacement = $newRun->outputs()->where('role', 'master_wav')->sole();
        $this->assertSame($evidence, app(DeliveryAssets::class)->inspect($original));
        app(DeliveryAssets::class)->verify($evidence);
        $changed = $original; $changed['lines'][0]['selection']['offer_snapshot']['assets'][0]['id'] = $replacement->id;
        $this->failure('changed', fn () => app(DeliveryAssets::class)->inspect($changed));
        $other = QuoteFixtures::selection(); $changed = $original;
        $changed['lines'][0]['selection']['offer_snapshot']['assets'] = $other['revision']->snapshot['assets'];
        $this->failure('changed', fn () => app(DeliveryAssets::class)->inspect($changed));
        $changed = $original; $changed['lines'][0]['offer_revision_id'] = $other['revision']->id;
        $this->failure('changed', fn () => app(DeliveryAssets::class)->inspect($changed));
    }

    public function test_provenance_validation_keeps_original_checks_and_test_scan_boundary_without_file_reads(): void
    {
        $fixture = QuoteFixtures::selection(); $asset = $fixture['media']['master_wav'];
        $verified = app(VerifiedMedia::class); $evidence = $verified->evidence($asset);
        $this->assertNotNull($evidence); $this->assertCount(3, $evidence['outputs']);
        $this->assertNotNull($verified->path($asset));
        foreach (['track_id' => $asset->track_id + 1000, 'parent_asset_id' => $asset->id,
            'processing_run_id' => $fixture['media']['artwork']->processing_run_id, 'sha256' => str_repeat('0', 64),
            'status' => 'quarantined', 'disk' => 'public', 'storage_path' => '../master.wav'] as $key => $value) {
            $changed = clone $asset; $changed->setAttribute($key, $value);
            $this->assertNull($verified->evidence($changed), $key);
        }
        unlink(Storage::disk('local')->path($asset->storage_path));
        $this->assertSame($evidence, $verified->evidence($asset));
        $this->assertNull($verified->path($asset));
        $environment = $this->app['env']; $this->app['env'] = 'local';
        try {
            $this->assertNull($verified->evidence($asset));
            $this->assertSame($evidence, $verified->evidence($asset, historical: true));
            $captured = app(DeliveryAssets::class)->inspect($this->original($fixture['revision']));
            $this->assertSame('test-only', $captured[0]['scan_scope']);
            $this->app->instance(DeliveryAssetFiles::class, new class extends DeliveryAssetFiles {
                public int $calls = 0;
                public function verify(array $entry, ?int $deadline = null): void { $this->calls++; throw new \LogicException('Synthetic scans cannot reach storage outside testing.'); }
            });
            $this->failure('asset_unavailable', fn () => app(DeliveryAssets::class)->verify($captured));
            $this->assertSame(0, app(DeliveryAssetFiles::class)->calls);
        }
        finally { $this->app['env'] = $environment; }
    }

    public function test_stems_capture_validates_historical_binding_without_inspecting_current_master_or_preview_files(): void
    {
        $fixture = RecordingFixtures::draft(); ['actor' => $actor, 'track' => $track, 'stems' => $stems] = $fixture;
        $binding = app(BindStemsToRecording::class)->handle($stems, $fixture['data'], $actor);
        RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'SYNTHETIC RIGHTS', 'sample_disclosure' => 'Synthetic source', 'status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
        $license = LicenseFixtures::published($actor, terms: ['schema_version' => 1, 'features' => ['SYNTHETIC STEMS ONLY'], 'required_asset_roles' => ['stems_zip']]);
        $offer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id, 'license_version_id' => $license->id,
            'price_minor' => 4999, 'currency' => 'USD', 'deliverable_asset_ids' => [$stems->id]], $actor);
        $revision = app(PublishOffer::class)->handle($offer, $actor); $original = $this->original($revision);
        $evidence = app(DeliveryAssets::class)->inspect($original);
        $this->assertSame($binding->evidence_hash, $evidence[0]['descriptor']['recording_binding']['evidence_hash']);
        $this->assertSame($binding->evidence, $evidence[0]['recording']['evidence']);
        app(DeliveryAssets::class)->verify($evidence);
        $this->app->instance(PrivateMediaFiles::class, new class extends PrivateMediaFiles {
            public int $calls = 0;
            public function resolve(string $relative): string { $this->calls++; throw new \LogicException('No storage access in captured provenance.'); }
        });
        $this->assertSame($evidence, DB::transaction(fn () => app(DeliveryAssets::class)->inspect($original)));
        $this->assertSame($binding->id, app(RecordingAssociation::class)->retained($stems)?->id);
        $this->assertSame(0, app(PrivateMediaFiles::class)->calls);
        $this->assertNull(app(RecordingAssociation::class)->verified($stems));
        $environment = $this->app['env']; $this->app['env'] = 'local';
        try {
            $this->assertSame($evidence, app(DeliveryAssets::class)->inspect($original));
            $this->assertNull(app(VerifiedMedia::class)->evidence($stems));
        } finally { $this->app['env'] = $environment; }
    }
}
