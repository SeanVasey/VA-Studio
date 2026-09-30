<?php

namespace App\Domain\Media;

use App\Application\Media\MediaIntegrity;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class MediaProcessor
{
    /**
     * Seconds a tool gets to print its version. It answers at once, and a run's worst case, which the job's 900 seconds must hold, is
     * better without two more calls of the 120 seconds every tool has.
     */
    private const VERSION_CALL_SECONDS = 15;

    public function handle(int $runId): MediaProcessingRun
    {
        $token = (string) Str::uuid();
        $run = DB::transaction(function () use ($runId, $token) {
            $run = MediaProcessingRun::query()->lockForUpdate()->findOrFail($runId);
            if ($run->status === 'completed' || ($run->status === 'processing' && $run->started_at?->gt(now()->subSeconds(config('media.claim_lease_seconds'))))) {
                return null;
            }
            $run->update(['status' => 'processing', 'claim_token' => $token, 'started_at' => now(), 'attempts' => $run->attempts + 1, 'failed_at' => null, 'failure_code' => null, 'failure_message' => null]);

            return $run;
        });
        if (! $run) {
            return MediaProcessingRun::findOrFail($runId);
        }
        $files = app(PrivateMediaFiles::class);
        $workspace = null;
        $promoted = [];
        $budget = app(MediaWorkflowBudget::class);
        $previousDeadline = $budget->enter();
        try {
            $source = $run->source()->firstOrFail();
            if ($source->disk !== 'local' || ! str_starts_with($source->storage_path, 'quarantine/') || ! in_array($source->role, ['master_wav', 'artwork', 'stems_zip'], true) || $source->processing_run_id) {
                throw new MediaFailure('unsupported_source', 'Only private quarantined WAV masters, PNG/JPEG artwork and WAV-only stems ZIPs are supported.');
            }
            $profile = app(MediaProfile::class)->current($source->role);
            if (! hash_equals($run->profile_fingerprint, app(MediaProfile::class)->fingerprint($profile))) {
                throw new MediaFailure('profile_changed', 'Processing configuration changed. Queue a new profile revision.');
            }
            $workspace = $files->workspace();
            $input = $workspace.'/source.bin';
            $integrity = $files->snapshot($source->storage_path, $input, (int) config($source->role === 'artwork' ? 'media.max_artwork_bytes' : 'media.max_source_bytes'));
            if (! hash_equals($run->input_sha256, $integrity['sha256']) || ! hash_equals($source->sha256 ?? '', $integrity['sha256']) || (int) $source->size_bytes !== $integrity['size_bytes'] || $source->mime_type !== $integrity['mime_type']) {
                throw new MediaFailure('source_changed', 'Source hash, size, or MIME no longer matches its intake evidence.');
            }
            $scan = $this->scan($input);
            $evidence = ['source_scan' => $scan];
            if ($source->role === 'master_wav') {
                if (! is_string($profile['tag_path']) || ! is_string($profile['tag_sha256']) || ! preg_match('/\A[a-f0-9]{64}\z/D', $profile['tag_sha256'])) {
                    throw new MediaFailure('tag_not_configured', 'Configure the approved seller WAV tag and its SHA-256 before generating previews.');
                }
                $tag = $workspace.'/tag.wav';
                $tagIntegrity = $files->snapshot($profile['tag_path'], $tag, 16777216);
                if (! hash_equals($profile['tag_sha256'], $tagIntegrity['sha256'])) {
                    throw new MediaFailure('tag_hash_mismatch', 'The seller tag does not match its approved SHA-256.');
                }
                $evidence['tag_scan'] = $this->scan($tag);
                $outputs = app(AudioDerivatives::class)->build($input, $tag, $profile, $workspace);
            } elseif ($source->role === 'stems_zip') {
                $outputs = app(StemsArchive::class)->build($input, $integrity['mime_type'], $profile, $workspace, $this->scan(...));
            } else {
                $outputs = app(ArtworkDerivative::class)->build($input, $integrity['mime_type'], $workspace);
            }
            $runner = app(BoundedMediaProcess::class);
            $evidence['ffmpeg_version'] = strtok($runner->run([config('media.ffmpeg'), '-version'], $workspace, self::VERSION_CALL_SECONDS), "\n");
            $evidence['ffprobe_version'] = strtok($runner->run([config('media.ffprobe'), '-version'], $workspace, self::VERSION_CALL_SECONDS), "\n");
            $budget->assertRemaining();
            $directory = (string) Str::uuid();
            $records = [];
            foreach ($outputs as $output) {
                $relative = $files->promote($output['file'], $directory, $output['name']);
                $promoted[] = $relative;
                $path = $files->resolve($relative);
                $records[] = ['track_id' => $source->track_id, 'parent_asset_id' => $source->id, 'processing_run_id' => $run->id, 'role' => $output['role'], 'disk' => 'local', 'storage_path' => $relative, 'original_name' => $output['name'], 'mime_type' => $output['mime_type'], 'size_bytes' => filesize($path), 'sha256' => hash_file('sha256', $path), 'technical_metadata' => $output['technical_metadata'], 'status' => 'ready', 'verified_by' => $run->requested_by, 'verified_at' => now()];
            }
            $result = DB::transaction(function () use ($run, $source, $token, $records, $evidence, $budget) {
                // Match queue lock ordering: source before run.
                $lockedSource = MediaAsset::query()->lockForUpdate()->findOrFail($source->id);
                $track = Track::query()->lockForUpdate()->findOrFail($source->track_id);
                if ($track->status === 'published') {
                    throw new MediaFailure('track_published', 'Unpublish the track before processing a replacement media revision.');
                }
                $locked = MediaProcessingRun::query()->lockForUpdate()->findOrFail($run->id);
                if ($locked->status !== 'processing' || $locked->claim_token !== $token) {
                    throw new MediaFailure('claim_lost', 'This media attempt no longer owns the processing claim.');
                }
                $ids = [];
                $budget->assertRemaining();
                foreach ($records as $record) {
                    $asset = MediaAsset::create($record);
                    $ids[] = $asset->id;
                }
                if ($lockedSource->status !== 'processed') {
                    $lockedSource->update(['status' => 'processed']);
                }
                $locked->update(['status' => 'completed', 'completed_at' => now(), 'output_asset_ids' => $ids, 'evidence' => $evidence, 'claim_token' => null]);
                $preview = collect($records)->firstWhere('role', 'preview_tagged');
                if ($preview) {
                    Track::query()->whereKey($source->track_id)->where('status', 'draft')->update(['duration_seconds' => (int) ceil($preview['technical_metadata']['duration_seconds']), 'waveform' => json_encode($preview['technical_metadata']['waveform'], JSON_THROW_ON_ERROR)]);
                }
                AuditEvent::record('media.processing.completed', $locked, ['source_asset_id' => $source->id, 'output_asset_ids' => $ids], $locked->requested_by);

                return $locked;
            });
            $promoted = []; // Durable immutable revisions must never be cleaned up.
            try {
                foreach ($result->outputs()->get() as $asset) {
                    // Prewarm shared digest checks; cache failure cannot undo durable completion.
                    app(MediaIntegrity::class)->matches($asset, $files->resolve($asset->storage_path));
                }
            } catch (Throwable $cacheError) {
                report($cacheError);
            }

            return $result;
        } catch (Throwable $error) {
            $failure = $error instanceof MediaFailure ? $error : new MediaFailure('processing_failed', 'Media processing failed. Review worker logs and retry after correcting the cause.');
            DB::transaction(function () use ($runId, $token, $failure) {
                $locked = MediaProcessingRun::query()->lockForUpdate()->findOrFail($runId);
                if ($locked->status === 'processing' && $locked->claim_token === $token) {
                    $locked->update(['status' => 'failed', 'failed_at' => now(), 'failure_code' => $failure->failureCode, 'failure_message' => $failure->getMessage(), 'claim_token' => null]);
                    AuditEvent::record('media.processing.failed', $locked, ['failure_code' => $failure->failureCode], $locked->requested_by);
                }
            });
            throw $failure;
        } finally {
            $budget->leave($previousDeadline);
            foreach ($promoted as $relative) {
                // Only this unsuccessful attempt's unreferenced random objects.
                if (! MediaAsset::query()->where('storage_path', $relative)->exists()) {
                    @unlink($files->root().'/'.$relative);
                }
            }
            if ($workspace) {
                $files->cleanup($workspace);
            }
        }
    }

    /** @param  ?int  $budgetSeconds  the wall-clock seconds this scan may take at most, when the caller has a budget of its own */
    private function scan(string $path, ?int $budgetSeconds = null): array
    {
        $scanner = app(MalwareScanner::class);
        $scanner->boundBy($budgetSeconds);
        try {
            $result = $scanner->scan($path);
        } finally {
            $scanner->boundBy(null);
        }
        if (! ScanEngines::accepted($result['engine'] ?? null) || ($result['status'] ?? null) !== 'clean' || ! hash_equals(hash_file('sha256', $path), $result['sha256'] ?? '')) {
            throw new MediaFailure('scan_not_clean', 'The scanner did not return clean evidence for these exact bytes.');
        }

        return $result;
    }
}
