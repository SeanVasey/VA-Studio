<?php

namespace App\Domain\SoundKits\Products;

use App\Domain\Media\AudioDerivatives;
use App\Domain\Media\BoundedMediaProcess;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\MediaProfile;
use App\Domain\Media\MediaWorkflowBudget;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\ScanEngines;
use App\Domain\Media\StagingSellerTag;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scans a quarantined kit demo and turns it into the public preview a track gets: the same WAV validation, the same approved
 * seller tag mixed in at the configured interval, and the same 192k MP3 and waveform ({@see AudioDerivatives::build()}), through
 * the same scanner, bounded tool runner and private revision store as track media. Only the tagged preview is kept; the
 * full-quality source never leaves private quarantine and is never served.
 *
 * Problems with the environment (scanner, tools, storage, tag configuration) return the demo to quarantine for a retry; problems
 * with the file fail it for good. It never throws for a processing outcome, so a synchronous queue still completes.
 */
final class SoundKitDemoProcessor
{
    public const LEASE_SECONDS = 960;

    /** An upper bound for the probe only; the demo's own cap is {@see SoundKitProductMedia::DEMO_MAX_SECONDS}. A 100 MiB WAV is under two hours of 8 kHz mono. */
    private const PROBE_CEILING_SECONDS = 86400;

    /** Failures an operator can fix without a new upload. */
    public const TRANSIENT = ['scanner_unavailable', 'scanner_signatures_stale', 'tool_unavailable', 'processor_timeout', 'storage_failed', 'unsafe_storage',
        'missing_source', 'processing_interrupted', 'tag_not_configured', 'tag_hash_mismatch', 'staging_tag_refused'];

    public function handle(int $demoId): SoundKitDemo
    {
        [$demo, $token] = $this->claim($demoId);
        if ($demo === null) {
            return SoundKitDemo::findOrFail($demoId);
        }
        $files = app(PrivateMediaFiles::class);
        $workspace = null;
        $promoted = [];
        $committing = false;
        $budget = app(MediaWorkflowBudget::class);
        $previousDeadline = $budget->enter();
        try {
            $workspace = $files->workspace();
            $input = $workspace.'/source.bin';
            $snapshot = $files->snapshot($demo->source_path, $input, SoundKitProductMedia::DEMO_MAX_BYTES);
            if (! hash_equals($demo->source_sha256, $snapshot['sha256']) || $snapshot['size_bytes'] !== $demo->size_bytes || $snapshot['mime_type'] !== $demo->mime_type) {
                throw new MediaFailure('source_changed', 'The quarantined upload changed after it was received.');
            }
            // Same order as a track (MediaProcessor): snapshot, scan the source, check and scan the tag, and only then run any tool on
            // the bytes. ffprobe and ffmpeg never see an unscanned file.
            $scan = $this->scan($input);
            $evidence = ['source_scan' => $scan];
            $profile = app(MediaProfile::class)->current('master_wav');
            if (! is_string($profile['tag_path']) || ! is_string($profile['tag_sha256']) || ! preg_match('/\A[a-f0-9]{64}\z/D', $profile['tag_sha256'])) {
                throw new MediaFailure('tag_not_configured', 'Configure the approved seller WAV tag and its SHA-256 before generating previews.');
            }
            $tag = $workspace.'/tag.wav';
            $tagIntegrity = $files->snapshot($profile['tag_path'], $tag, 16777216);
            if (! hash_equals($profile['tag_sha256'], $tagIntegrity['sha256'])) {
                throw new MediaFailure('tag_hash_mismatch', 'The seller tag does not match its approved SHA-256.');
            }
            // The synthetic staging tag stands in for the approved one on rehearsal hosts only (docs/ops/staging-placeholders.md).
            if (StagingSellerTag::refused($profile['tag_path'], $tag)) {
                throw new MediaFailure('staging_tag_refused', 'The configured seller tag is the synthetic staging placeholder. Install the approved seller tag and its SHA-256.');
            }
            $evidence['tag_scan'] = $this->scan($tag);
            $audio = app(AudioDerivatives::class);
            // Full validation of the scanned copy, then the duration cap: a demo is a short taster, not the kit. The probe ceiling is
            // far above the cap so that a long demo gets its own failure code rather than a generic audio one.
            $wav = $audio->validateWav($input, $snapshot['mime_type'], self::PROBE_CEILING_SECONDS);
            if ($wav['duration_seconds'] > SoundKitProductMedia::DEMO_MAX_SECONDS) {
                throw new MediaFailure('demo_too_long', 'A demo may be at most '.(SoundKitProductMedia::DEMO_MAX_SECONDS / 60).' minutes long.');
            }
            $evidence['profile_fingerprint'] = app(MediaProfile::class)->fingerprint($profile);
            // A demo keeps a single tagged preview: the untagged variant is a track-only feature (docs/media-processing.md).
            $outputs = $audio->build($input, $tag, $profile, $workspace, untaggedPreview: false);
            $preview = collect($outputs)->firstWhere('role', 'preview_tagged');
            if ($preview === null || ($preview['technical_metadata']['full_length'] ?? false) !== true) {
                throw new MediaFailure('processor_failed', 'The tagged preview could not be produced.');
            }
            $runner = app(BoundedMediaProcess::class);
            $evidence['ffmpeg_version'] = strtok($runner->run([config('media.ffmpeg'), '-version'], $workspace, 15), "\n");
            $evidence['ffprobe_version'] = strtok($runner->run([config('media.ffprobe'), '-version'], $workspace, 15), "\n");
            $evidence['tag_sha256'] = $preview['technical_metadata']['tag_sha256'];
            $evidence['tag_interval_seconds'] = $preview['technical_metadata']['tag_interval_seconds'];
            $budget->assertRemaining();
            $relative = $files->promoteUnder('sound-kit-demos/revisions', $preview['file'], (string) Str::uuid(), 'preview.mp3');
            $promoted[] = $relative;
            $path = $files->resolve($relative);
            $result = [
                'preview_path' => $relative, 'preview_sha256' => hash_file('sha256', $path), 'preview_size_bytes' => filesize($path),
                'duration_seconds' => (int) ceil($preview['technical_metadata']['duration_seconds']), 'waveform' => $preview['technical_metadata']['waveform'],
            ];
            $budget->assertRemaining();

            return $this->complete($demoId, $token, $result, $evidence, $committing);
        } catch (MediaFailure $failure) {
            if ($failure->failureCode !== 'claim_lost') {
                $this->fail($demoId, $token, $failure->failureCode);
            }

            return SoundKitDemo::findOrFail($demoId);
        } catch (Throwable $exception) {
            // Unexpected errors (a lost database connection, a killed tool) are logged and treated as temporary.
            report($exception);
            $this->fail($demoId, $token, 'processing_interrupted');

            return SoundKitDemo::findOrFail($demoId);
        } finally {
            $budget->leave($previousDeadline);
            // Once the ready commit has started, the files are kept (at worst as orphans) rather than delete bytes a row may reference.
            if ($promoted !== [] && ! $committing) {
                try {
                    foreach ($promoted as $relative) {
                        @unlink($files->root().'/'.$relative);
                    }
                    @rmdir(dirname($files->root().'/'.$promoted[0]));
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }
            if ($workspace !== null) {
                $files->cleanup($workspace);
            }
        }
    }

    /** @return array<string, mixed> the scanner's clean evidence for exactly these bytes */
    private function scan(string $path): array
    {
        $result = app(MalwareScanner::class)->scan($path);
        if (! ScanEngines::accepted($result['engine'] ?? null) || ($result['status'] ?? null) !== 'clean' || ! hash_equals(hash_file('sha256', $path), $result['sha256'] ?? '')) {
            throw new MediaFailure('scan_not_clean', 'The scanner did not return clean evidence for these exact bytes.');
        }

        return $result;
    }

    /** @return array{0: ?SoundKitDemo, 1: ?string} */
    private function claim(int $demoId): array
    {
        return DB::transaction(function () use ($demoId): array {
            $demo = SoundKitDemo::query()->whereKey($demoId)->lockForUpdate()->first();
            if ($demo === null || ! in_array($demo->status, ['quarantined', 'processing'], true)
                || ($demo->status === 'processing' && $demo->claimed_until !== null && $demo->claimed_until->isFuture())) {
                return [null, null];
            }
            if ($demo->status === 'processing') {
                AuditEvent::record('sound_kit_demo.retry_pending', $demo, ['failure_code' => 'processing_interrupted', 'attempt' => $demo->attempts]);
            }
            $token = (string) Str::uuid();
            $demo->forceFill(['status' => 'processing', 'claim_token' => $token, 'claimed_until' => now()->addSeconds(self::LEASE_SECONDS),
                'attempts' => $demo->attempts + 1, 'failure_code' => null])->save();
            AuditEvent::record('sound_kit_demo.processing', $demo, ['attempt' => $demo->attempts]);

            return [$demo, $token];
        });
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $evidence
     * @param  bool  $committing  set by the transaction's last statement, so an error after it may follow an applied commit
     */
    private function complete(int $demoId, string $token, array $result, array $evidence, bool &$committing): SoundKitDemo
    {
        return DB::transaction(function () use ($demoId, $token, $result, $evidence, &$committing): SoundKitDemo {
            $demo = SoundKitDemo::query()->whereKey($demoId)->lockForUpdate()->firstOrFail();
            if ($demo->status !== 'processing' || $demo->claim_token !== $token) {
                throw new MediaFailure('claim_lost', 'Another worker took over this demo.');
            }
            app(MediaWorkflowBudget::class)->assertRemaining();
            $demo->forceFill($result + ['status' => 'ready', 'claim_token' => null, 'claimed_until' => null, 'failure_code' => null,
                'evidence' => $evidence, 'processed_at' => now()])->save();
            AuditEvent::record('sound_kit_demo.processed', $demo, ['preview_sha256' => $result['preview_sha256'], 'duration_seconds' => $result['duration_seconds']]);
            $ready = $demo->fresh();
            $committing = true;

            return $ready;
        });
    }

    private function fail(int $demoId, ?string $token, string $code): void
    {
        DB::transaction(function () use ($demoId, $token, $code): void {
            $demo = SoundKitDemo::query()->whereKey($demoId)->lockForUpdate()->first();
            // Another worker may own it now; only the run holding the claim records the outcome.
            if ($demo === null || $demo->status !== 'processing' || $token === null || $demo->claim_token !== $token) {
                return;
            }
            $transient = in_array($code, self::TRANSIENT, true);
            $demo->forceFill($transient
                ? ['status' => 'quarantined', 'claim_token' => null, 'claimed_until' => null, 'failure_code' => $code]
                : ['status' => 'failed', 'claim_token' => null, 'claimed_until' => null, 'failure_code' => $code, 'processed_at' => now()])->save();
            AuditEvent::record($transient ? 'sound_kit_demo.retry_pending' : 'sound_kit_demo.failed', $demo, ['failure_code' => $code, 'attempt' => $demo->attempts]);
        });
    }
}
