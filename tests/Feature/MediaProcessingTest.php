<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\BoundedMediaProcess;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\MediaProfile;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\VerifiedMedia;
use App\Jobs\ProcessMedia;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Support\MediaFixtures;
use Tests\Support\ShortReadMediaStream;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

class MediaProcessingTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Track $track;

    private TestOnlyMediaScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->scanner = MediaFixtures::configure();
        $this->actor = User::factory()->create();
        $this->actor->forceFill(['is_admin' => true])->save();
        $this->track = Track::create(['title' => 'Synthetic fixture', 'slug' => 'synthetic-fixture']);
    }

    private function process(?MediaAsset $source = null): MediaProcessingRun
    {
        $run = app(QueueMediaProcessing::class)->handle($source ?? MediaFixtures::source($this->track), $this->actor);

        return app(MediaProcessor::class)->handle($run->id);
    }

    private function assertFailure(MediaAsset $source, string $code): MediaProcessingRun
    {
        $run = app(QueueMediaProcessing::class)->handle($source, $this->actor);
        try {
            app(MediaProcessor::class)->handle($run->id);
            $this->fail('Invalid media was promoted.');
        } catch (MediaFailure $failure) {
            $this->assertSame($code, $failure->failureCode);
        }
        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame($code, $run->failure_code);
        $this->assertSame(0, $run->outputs()->count());
        $this->assertSame('quarantined', $source->fresh()->status);
        $this->assertSame([], glob(Storage::disk('local')->path('processing').'/*'));

        return $run;
    }

    public function test_real_wav_produces_private_immutable_master_tagged_preview_delivery_and_waveform(): void
    {
        $source = MediaFixtures::source($this->track, bytes: MediaFixtures::wav(3.2));
        $run = $this->process($source);
        $this->assertSame('completed', $run->status);
        $outputs = $run->outputs()->get()->keyBy('role');
        $this->assertCount(3, $outputs);
        $this->assertSame($source->sha256, $outputs['master_wav']->sha256);
        foreach ($outputs as $asset) {
            $this->assertSame($source->id, $asset->parent_asset_id);
            $this->assertSame('local', $asset->disk);
            $this->assertStringStartsWith('media/revisions/', $asset->storage_path);
            $this->assertTrue(app(VerifiedMedia::class)->available($asset));
            $this->assertSame($asset->sha256, hash_file('sha256', Storage::disk('local')->path($asset->storage_path)));
            $this->assertFalse(file_exists(public_path($asset->storage_path)));
        }
        $preview = $outputs['preview_tagged'];
        $delivery = $outputs['download_mp3'];
        $this->assertNotSame($preview->sha256, $delivery->sha256);
        $this->assertEqualsWithDelta(3.2, $preview->technical_metadata['duration_seconds'], 0.15);
        $this->assertCount(200, $preview->technical_metadata['waveform']);
        $this->assertGreaterThan(0.05, max($preview->technical_metadata['waveform']));
        $this->assertGreaterThan(0.02, max($preview->technical_metadata['waveform']) - min($preview->technical_metadata['waveform']));
        $this->assertSame($preview->technical_metadata['waveform'], $this->track->fresh()->waveform);
        // Decode both actual MP3s and measure the synthetic tag's 1800 Hz tone.
        $tagged = $this->decode($preview);
        $untagged = $this->decode($delivery);
        $firstTag = $this->toneEnergy($tagged, 0.06, 1800);
        $repeatTag = $this->toneEnergy($tagged, 1.06, 1800);
        $betweenTags = $this->toneEnergy($tagged, 0.5, 1800);
        $this->assertGreaterThan(0.04, $firstTag);
        $this->assertGreaterThan($firstTag * 0.6, $repeatTag);
        $this->assertLessThan($firstTag * 0.1, $betweenTags);
        $this->assertLessThan($firstTag * 0.1, $this->toneEnergy($untagged, 0.06, 1800));
        $this->assertSame([], glob(Storage::disk('local')->path('processing').'/*'));
    }

    public function test_real_png_is_sanitized_into_verified_raster_artwork(): void
    {
        $run = $this->process(MediaFixtures::source($this->track, 'artwork'));
        $artwork = $run->outputs()->sole();
        $this->assertSame('image/png', $artwork->mime_type);
        $this->assertSame(['width' => 1, 'height' => 1, 'sanitized' => true], $artwork->technical_metadata);
        $this->assertTrue(app(VerifiedMedia::class)->available($artwork));
    }

    public function test_retry_and_duplicate_delivery_produce_one_logical_output_set(): void
    {
        $source = MediaFixtures::source($this->track);
        $this->scanner->reject = true;
        $failed = $this->assertFailure($source, 'scan_not_clean');
        $this->scanner->reject = false;
        $retry = app(QueueMediaProcessing::class)->handle($source, $this->actor);
        $this->assertSame($failed->id, $retry->id);
        $completed = app(MediaProcessor::class)->handle($retry->id);
        $ids = $completed->output_asset_ids;
        $again = app(MediaProcessor::class)->handle($retry->id);
        $queuedAgain = app(QueueMediaProcessing::class)->handle($source, $this->actor);
        $this->assertSame($ids, $again->output_asset_ids);
        $this->assertSame($completed->id, $queuedAgain->id);
        $this->assertSame(2, $again->attempts);
        $this->assertDatabaseCount('media_processing_runs', 1);
        $this->assertSame(3, MediaAsset::where('status', 'ready')->count());
        Queue::assertPushed(ProcessMedia::class, 2);
    }

    public function test_new_profile_creates_new_revisions_without_changing_old_outputs(): void
    {
        $source = MediaFixtures::source($this->track);
        $first = $this->process($source);
        $original = $first->outputs()->get()->pluck('sha256', 'storage_path')->all();
        config(['media.profile_version' => 'wav-preview-v2']);
        $second = $this->process($source);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(6, MediaAsset::where('status', 'ready')->count());
        foreach ($original as $path => $hash) {
            $this->assertSame($hash, hash_file('sha256', Storage::disk('local')->path($path)));
        }
    }

    public function test_corrupt_and_wrong_mime_audio_never_promote(): void
    {
        $this->assertFailure(MediaFixtures::source($this->track, bytes: str_repeat('not a wav', 10)), 'invalid_wav');
        $source = MediaFixtures::source($this->track);
        $source->update(['mime_type' => 'audio/mpeg']);
        $this->assertFailure($source, 'source_changed');
        $truncated = substr(MediaFixtures::wav(), 0, -16);
        $this->assertFailure(MediaFixtures::source($this->track, bytes: $truncated), 'invalid_wav');
    }

    public function test_oversized_audio_and_unsupported_artwork_stay_quarantined(): void
    {
        $source = MediaFixtures::source($this->track);
        $run = app(QueueMediaProcessing::class)->handle($source, $this->actor);
        config(['media.max_source_bytes' => 100]);
        try {
            app(MediaProcessor::class)->handle($run->id);
            $this->fail('Oversized source was processed.');
        } catch (MediaFailure $failure) {
            $this->assertSame('invalid_size', $failure->failureCode);
        }
        $this->assertSame(0, $run->outputs()->count());
        $this->assertFailure(MediaFixtures::source($this->track, 'artwork', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'unsupported_artwork');
    }

    public function test_missing_tag_wrong_hash_and_invalid_tag_content_fail_closed(): void
    {
        config(['media.tag_path' => null, 'media.tag_sha256' => null]);
        $this->assertFailure(MediaFixtures::source($this->track), 'tag_not_configured');
        MediaFixtures::configure();
        config(['media.tag_sha256' => str_repeat('a', 64)]);
        $this->assertFailure(MediaFixtures::source($this->track), 'tag_hash_mismatch');
        $invalid = 'this is not an audio tag';
        Storage::disk('local')->put('approved-tags/bad.wav', $invalid);
        config(['media.tag_path' => 'approved-tags/bad.wav', 'media.tag_sha256' => hash('sha256', $invalid)]);
        $this->assertFailure(MediaFixtures::source($this->track), 'invalid_wav');
    }

    public function test_runtime_scanner_is_required_and_test_evidence_is_rejected_outside_testing(): void
    {
        app()->instance(MalwareScanner::class, new MalwareScanner);
        config(['media.clamscan' => '/not-installed/clamscan']);
        $this->assertFailure(MediaFixtures::source($this->track), 'scanner_unavailable');
        app()->instance(MalwareScanner::class, new class extends MalwareScanner
        {
            public function scan(string $path): array
            {
                return ['engine' => 'test-only', 'status' => 'clean', 'sha256' => hash_file('sha256', $path)];
            }
        });
        app()->instance('env', 'production');
        try {
            $this->assertFailure(MediaFixtures::source($this->track), 'scan_not_clean');
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_unsafe_paths_symlinks_and_replaced_source_cannot_be_queued(): void
    {
        foreach (['../escape.wav', 'quarantine/../../escape.wav', 'https://example.com/source.wav'] as $path) {
            $source = MediaFixtures::source($this->track);
            $source->update(['storage_path' => $path]);
            try {
                app(QueueMediaProcessing::class)->handle($source, $this->actor);
                $this->fail('Unsafe source was queued.');
            } catch (ValidationException) {
            }
        }
        $source = MediaFixtures::source($this->track);
        $real = Storage::disk('local')->path($source->storage_path);
        unlink($real);
        symlink(Storage::disk('local')->path('approved-tags/test-only.wav'), $real);
        try {
            app(QueueMediaProcessing::class)->handle($source, $this->actor);
            $this->fail('Symbolic link was queued.');
        } catch (ValidationException) {
        }
        unlink($real);
        file_put_contents($real, MediaFixtures::wav(2));
        $this->expectException(ValidationException::class);
        app(QueueMediaProcessing::class)->handle($source, $this->actor);
    }

    public function test_stems_and_arbitrary_uploaded_previews_are_unsupported(): void
    {
        foreach (['stems_zip', 'preview_tagged', 'download_mp3'] as $role) {
            $source = MediaFixtures::source($this->track, $role);
            try {
                app(QueueMediaProcessing::class)->handle($source, $this->actor);
                $this->fail('Unsupported role was queued.');
            } catch (ValidationException) {
            }
            $this->assertSame('quarantined', $source->fresh()->status);
        }
        $this->assertDatabaseCount('media_processing_runs', 0);
    }

    public function test_non_admin_cannot_queue_processing(): void
    {
        $this->expectException(AuthorizationException::class);
        app(QueueMediaProcessing::class)->handle(MediaFixtures::source($this->track), User::factory()->create());
    }

    public function test_live_track_replacement_requires_unpublishing_before_queue_and_completion(): void
    {
        $source = MediaFixtures::source($this->track);
        DB::table('tracks')->where('id', $this->track->id)->update(['status' => 'published']);
        try {
            app(QueueMediaProcessing::class)->handle($source, $this->actor);
            $this->fail('Published replacement was queued.');
        } catch (ValidationException) {
        }
        $this->track->update(['status' => 'draft']);
        $run = app(QueueMediaProcessing::class)->handle($source, $this->actor);
        DB::table('tracks')->where('id', $this->track->id)->update(['status' => 'published']);
        try {
            app(MediaProcessor::class)->handle($run->id);
            $this->fail('Published replacement was promoted.');
        } catch (MediaFailure $failure) {
            $this->assertSame('track_published', $failure->failureCode);
        }
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, MediaAsset::where('status', 'ready')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('media/revisions'));
    }

    public function test_completed_processing_and_ready_assets_resist_bulk_mutation(): void
    {
        $run = $this->process();
        foreach ([['media_assets', $run->output_asset_ids[0]], ['media_processing_runs', $run->id], ['media_assets', $run->source_asset_id]] as [$table, $id]) {
            try {
                DB::table($table)->where('id', $id)->update(['status' => 'failed']);
                $this->fail('Immutable evidence was changed.');
            } catch (QueryException) {
            }
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail('Immutable evidence was deleted.');
            } catch (QueryException) {
            }
        }
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_current_claim_is_not_duplicated_and_expired_claim_can_recover(): void
    {
        $source = MediaFixtures::source($this->track);
        $run = app(QueueMediaProcessing::class)->handle($source, $this->actor);
        $run->update(['status' => 'processing', 'started_at' => now(), 'claim_token' => '00000000-0000-4000-8000-000000000001']);
        $this->assertSame('processing', app(MediaProcessor::class)->handle($run->id)->status);
        $this->assertSame(0, $run->outputs()->count());
        $run->update(['started_at' => now()->subSeconds(config('media.claim_lease_seconds') + 1)]);
        $this->assertSame('completed', app(MediaProcessor::class)->handle($run->id)->status);
    }

    public function test_output_tampering_and_symlinks_remove_verified_availability(): void
    {
        $run = $this->process();
        $asset = $run->outputs()->where('role', 'preview_tagged')->sole();
        $path = Storage::disk('local')->path($asset->storage_path);
        chmod($path, 0600);
        file_put_contents($path, 'tampered');
        $this->assertFalse(app(VerifiedMedia::class)->available($asset));
        unlink($path);
        symlink(Storage::disk('local')->path('approved-tags/test-only.wav'), $path);
        $this->assertFalse(app(VerifiedMedia::class)->available($asset));
    }

    public function test_missing_tool_and_wall_timeout_fail_with_safe_error_codes(): void
    {
        config(['media.ffprobe' => '/not-installed/ffprobe']);
        $this->assertFailure(MediaFixtures::source($this->track), 'tool_unavailable');
        $workspace = app(PrivateMediaFiles::class)->workspace();
        try {
            app(BoundedMediaProcess::class)->run(['/bin/sleep', '2'], $workspace, 1);
            $this->fail('Processor exceeded its deadline.');
        } catch (MediaFailure $failure) {
            $this->assertSame('processor_timeout', $failure->failureCode);
        } finally {
            app(PrivateMediaFiles::class)->cleanup($workspace);
        }
    }

    public function test_queued_run_is_redispatched_when_its_previous_delivery_was_lost(): void
    {
        $source = MediaFixtures::source($this->track);
        $run = app(QueueMediaProcessing::class)->handle($source, $this->actor);
        // A failed transport after commit leaves precisely this durable queued state.
        Queue::fake();
        $retried = app(QueueMediaProcessing::class)->handle($source, $this->actor);
        $this->assertSame($run->id, $retried->id);
        Queue::assertPushed(ProcessMedia::class, fn ($job) => $job->runId === $run->id);
        $this->assertDatabaseCount('media_processing_runs', 1);
        $this->assertSame('completed', app(MediaProcessor::class)->handle($run->id)->status);
    }

    public function test_valid_but_silent_seller_tag_is_rejected(): void
    {
        $tag = Storage::disk('local')->path('approved-tags/silent.wav');
        $process = new Process([config('media.ffmpeg'), '-v', 'error', '-f', 'lavfi', '-i', 'anullsrc=channel_layout=mono:sample_rate=44100', '-t', '0.2', '-c:a', 'pcm_s16le', $tag]);
        $process->setTimeout(15)->mustRun();
        config(['media.tag_path' => 'approved-tags/silent.wav', 'media.tag_sha256' => hash_file('sha256', $tag)]);
        $this->assertFailure(MediaFixtures::source($this->track), 'silent_tag');
    }

    public function test_scanner_contract_rejects_missing_or_stale_signature_metadata(): void
    {
        config(['media.clamscan' => '/bin/true']);
        foreach (['ClamAV 1.4.3', 'ClamAV 1.4.3/12345/'.date('D M d H:i:s Y', time() - 300000)] as $version) {
            $runner = new class($version) extends BoundedMediaProcess
            {
                public function __construct(private string $version) {}

                public function run(array $arguments, string $cwd, int $timeout = 0): string
                {
                    return $this->version;
                }
            };
            app()->instance(BoundedMediaProcess::class, $runner);
            try {
                (new MalwareScanner)->scan(Storage::disk('local')->path('approved-tags/test-only.wav'));
                $this->fail('Stale signature evidence was accepted.');
            } catch (MediaFailure $failure) {
                $this->assertSame('scanner_signatures_stale', $failure->failureCode);
            }
        }
    }

    public function test_json_profile_fingerprint_is_independent_of_database_key_order(): void
    {
        $profile = app(MediaProfile::class)->current('master_wav');
        $first = app(MediaProfile::class)->fingerprint($profile);
        krsort($profile);
        $this->assertSame($first, app(MediaProfile::class)->fingerprint($profile));
    }

    public function test_cleanup_removes_hidden_scratch_and_never_follows_a_symlink(): void
    {
        $files = app(PrivateMediaFiles::class);
        $workspace = $files->workspace();
        mkdir($workspace.'/.tool-cache');
        file_put_contents($workspace.'/.tool-cache/private.tmp', 'scratch');
        $outside = Storage::disk('local')->path('approved-tags');
        symlink($outside, $workspace.'/external');
        $files->cleanup($workspace);
        $this->assertDirectoryDoesNotExist($workspace);
        $this->assertFileExists($outside.'/test-only.wav');
    }

    public function test_private_test_storage_does_not_reuse_or_clean_another_test_root(): void
    {
        $previous = Storage::disk('local');
        $previous->put('processing/other-attempt/sentinel', 'previous test');

        $this->fakePrivateMediaStorage();
        $current = Storage::disk('local');
        $this->assertNotSame($previous->path(''), $current->path(''));
        $this->assertSame([], glob($current->path('processing').'/*'));
        $current->put('processing/other-attempt/sentinel', 'current test');
        $this->assertSame('previous test', $previous->get('processing/other-attempt/sentinel'));
        $this->assertSame('current test', $current->get('processing/other-attempt/sentinel'));
    }

    public function test_partial_promotion_is_removed_and_existing_revisions_are_never_unlinked(): void
    {
        $files = app(PrivateMediaFiles::class);
        stream_wrapper_register('short-media-copy', ShortReadMediaStream::class);
        try {
            try {
                $files->promote('short-media-copy://source', '00000000-0000-4000-8000-000000000001', 'master.wav');
                $this->fail('A short copy was promoted.');
            } catch (MediaFailure $failure) {
                $this->assertSame('storage_failed', $failure->failureCode);
            }
            $this->assertFalse(Storage::disk('local')->exists('media/revisions/00000000-0000-4000-8000-000000000001/master.wav'));
        } finally {
            stream_wrapper_unregister('short-media-copy');
        }
        $source = MediaFixtures::source($this->track);
        $key = $files->promote(Storage::disk('local')->path($source->storage_path), '00000000-0000-4000-8000-000000000002', 'master.wav');
        $hash = hash_file('sha256', Storage::disk('local')->path($key));
        try {
            $files->promote(Storage::disk('local')->path($source->storage_path), '00000000-0000-4000-8000-000000000002', 'master.wav');
            $this->fail('A revision path was reused.');
        } catch (MediaFailure $failure) {
            $this->assertSame('storage_failed', $failure->failureCode);
        }
        $this->assertSame($hash, hash_file('sha256', Storage::disk('local')->path($key)));
    }

    private function decode(MediaAsset $asset): array
    {
        $process = new Process([config('media.ffmpeg'), '-v', 'error', '-f', 'mp3', '-i', Storage::disk('local')->path($asset->storage_path), '-ac', '1', '-ar', '8000', '-f', 's16le', 'pipe:1']);
        $process->setTimeout(15)->mustRun();

        return array_values(array_map(fn ($value) => ($value >= 32768 ? $value - 65536 : $value) / 32768, unpack('v*', $process->getOutput())));
    }

    private function toneEnergy(array $samples, float $start, int $frequency): float
    {
        $window = array_slice($samples, (int) ($start * 8000), 400);
        $real = $imag = 0.0;
        foreach ($window as $index => $value) {
            $phase = 2 * M_PI * $frequency * $index / 8000;
            $real += $value * cos($phase);
            $imag += $value * sin($phase);
        }

        return 2 * sqrt($real ** 2 + $imag ** 2) / count($window);
    }
}
