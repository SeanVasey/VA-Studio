<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\MediaProfile;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\StemsArchive;
use App\Domain\Media\VerifiedMedia;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

class StemsArchivePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
    }

    private function queued(): array
    {
        $actor = LicenseFixtures::admin();
        $track = Track::create(['title' => 'Synthetic archive policy', 'slug' => 'archive-policy']);
        $source = MediaFixtures::source($track, 'stems_zip', StemsFixtures::zip([['name' => 'Audio.wav', 'bytes' => MediaFixtures::wav(1.2)]]));
        $run = app(QueueMediaProcessing::class)->handle($source, $actor);

        return compact('actor', 'source', 'run');
    }

    public function test_historical_v1_processing_evidence_remains_available_under_v2_and_changed_config(): void
    {
        ['run' => $run] = $this->queued();
        $run = app(MediaProcessor::class)->handle($run->id);
        $asset = $run->outputs()->sole();
        // Build a separate persisted legacy fixture; never rewrite completed evidence.
        $profile = $run->profile;
        $profile['archive_version'] = 'wav-stems-zip-v1';
        unset($profile['archive_max_duration_seconds']);
        $legacyRun = $run->replicate();
        $legacyRun->forceFill(['status' => 'queued', 'completed_at' => null, 'output_asset_ids' => null, 'profile' => $profile, 'profile_fingerprint' => app(MediaProfile::class)->fingerprint($profile)])->save();
        $legacyAsset = $asset->replicate();
        $metadata = $asset->technical_metadata;
        $metadata['archive_version'] = 'wav-stems-zip-v1';
        $key = app(PrivateMediaFiles::class)->promote(Storage::disk('local')->path($asset->storage_path), (string) Str::uuid(), 'stems.zip');
        $legacyAsset->forceFill(['processing_run_id' => $legacyRun->id, 'technical_metadata' => $metadata, 'storage_path' => $key])->save();
        $legacyRun->update(['status' => 'completed', 'completed_at' => now(), 'output_asset_ids' => [$legacyAsset->id]]);
        $this->assertSame('wav-stems-zip-v2', StemsArchive::VERSION);
        config(['media.max_duration_seconds' => 1, 'media.stems.max_entries' => 1, 'media.profile_version' => 'future-worker']);
        $this->assertTrue(app(VerifiedMedia::class)->available($legacyAsset));
        $this->assertTrue(app(VerifiedMedia::class)->available($asset));
        $this->assertSame('wav-stems-zip-v1', $legacyRun->fresh()->profile['archive_version']);
        $this->assertArrayNotHasKey('archive_max_duration_seconds', $legacyRun->fresh()->profile);
    }

    public function test_duration_ceiling_is_fingerprinted_and_queue_drift_requires_a_new_revision(): void
    {
        config(['media.max_duration_seconds' => 1]);
        ['actor' => $actor, 'source' => $source, 'run' => $run] = $this->queued();
        $this->assertSame(1, $run->profile['archive_max_duration_seconds']);
        config(['media.max_duration_seconds' => 2]);
        try {
            app(MediaProcessor::class)->handle($run->id);
            $this->fail('Queued profile drift was accepted.');
        } catch (MediaFailure $failure) {
            $this->assertSame('profile_changed', $failure->failureCode);
        }
        $next = app(QueueMediaProcessing::class)->handle($source, $actor);
        $this->assertNotSame($run->id, $next->id);
        $this->assertNotSame($run->profile_fingerprint, $next->profile_fingerprint);
        $this->assertSame(2, $next->profile['archive_max_duration_seconds']);
        $completed = app(MediaProcessor::class)->handle($next->id);
        $this->assertTrue(app(VerifiedMedia::class)->available($completed->outputs()->sole()));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, $run->outputs()->count());
    }

    public function test_member_validation_uses_frozen_duration_policy_and_rejects_oversized_audio(): void
    {
        config(['media.max_duration_seconds' => 1]);
        ['actor' => $actor, 'source' => $source, 'run' => $run] = $this->queued();
        try {
            app(MediaProcessor::class)->handle($run->id);
            $this->fail('A member exceeded the frozen duration ceiling.');
        } catch (MediaFailure $failure) {
            $this->assertSame('invalid_audio', $failure->failureCode);
        }
        $this->assertSame([], Storage::disk('local')->allFiles('processing'));
        config(['media.max_duration_seconds' => 2]);
        $next = app(QueueMediaProcessing::class)->handle($source, $actor);
        app()->instance(MalwareScanner::class, new class extends TestOnlyMediaScanner {
            public function scan(string $path): array
            {
                $result = parent::scan($path);
                // Simulate config changing after the worker has accepted its profile.
                config(['media.max_duration_seconds' => 1]);

                return $result;
            }
        });
        $completed = app(MediaProcessor::class)->handle($next->id);
        $this->assertSame(2, $completed->profile['archive_max_duration_seconds']);
        $this->assertTrue(app(VerifiedMedia::class)->available($completed->outputs()->sole()));
    }

    public function test_unknown_mismatched_versions_and_invalid_v2_duration_evidence_are_rejected(): void
    {
        ['run' => $run] = $this->queued();
        $run = app(MediaProcessor::class)->handle($run->id);
        $asset = $run->outputs()->sole();
        $archive = app(StemsArchive::class);
        foreach (['wav-stems-zip-v999', null] as $version) {
            $this->assertFalse($archive->hasEvidence($asset->technical_metadata, $asset->sha256, ['archive_version' => $version] + $run->profile));
        }
        $this->assertFalse($archive->hasEvidence($asset->technical_metadata, $asset->sha256, ['archive_version' => 'wav-stems-zip-v1'] + $run->profile));
        foreach ([0, 1201, '1200', null] as $limit) {
            $this->assertFalse($archive->hasEvidence($asset->technical_metadata, $asset->sha256, ['archive_max_duration_seconds' => $limit] + $run->profile));
        }
        foreach ([0, 1200000001, '1200000', null] as $duration) {
            $metadata = $asset->technical_metadata;
            $metadata['manifest'][0]['audio']['duration_microseconds'] = $duration;
            $metadata['manifest_sha256'] = CanonicalJson::hash($metadata['manifest']);
            $this->assertFalse($archive->hasEvidence($metadata, $asset->sha256, $run->profile));
        }
    }
}
