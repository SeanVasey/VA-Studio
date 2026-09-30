<?php

namespace App\Domain\SiteBuilder;

use App\Application\SiteBuilder\IngestSiteImage;
use App\Domain\Media\ArtworkDerivative;
use App\Domain\Media\BoundedMediaProcess;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\ScanEngines;
use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\Models\SiteImageVariant;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scans, inspects and re-encodes a quarantined site image into its slot's sizes (D-25).
 *
 * Problems with the environment return the image to quarantine for a retry; problems with the file fail it permanently.
 * It never throws for a processing outcome, so an upload handled by a synchronous queue still completes.
 */
final class SiteImageProcessor
{
    public const LEASE_SECONDS = 960;

    /** Failures an operator can fix without a new upload. */
    public const TRANSIENT = ['scanner_unavailable', 'scanner_signatures_stale', 'tool_unavailable', 'processor_timeout', 'storage_failed', 'unsafe_storage', 'missing_source', 'processing_interrupted'];

    public function handle(int $imageId): SiteImage
    {
        [$image, $token] = $this->claim($imageId);
        if ($image === null) {
            return SiteImage::findOrFail($imageId);
        }
        $files = app(PrivateMediaFiles::class);
        $workspace = null;
        $promoted = [];
        try {
            $workspace = $files->workspace();
            $input = $workspace.'/source';
            $snapshot = $files->snapshot($image->source_path, $input, IngestSiteImage::MAX_BYTES);
            if (! hash_equals($image->source_sha256, $snapshot['sha256']) || $snapshot['size_bytes'] !== $image->size_bytes || $snapshot['mime_type'] !== $image->mime_type) {
                throw new MediaFailure('source_changed', 'The quarantined upload changed after it was received.');
            }
            $scan = app(MalwareScanner::class)->scan($input);
            if (! ScanEngines::accepted($scan['engine'] ?? null) || ($scan['status'] ?? null) !== 'clean' || ! hash_equals($image->source_sha256, $scan['sha256'] ?? '')) {
                throw new MediaFailure('scan_not_clean', 'The scanner did not return clean evidence for these exact bytes.');
            }
            $problem = SiteImageInspection::headerProblem($input, $image->mime_type, @getimagesize($input) ?: []);
            if ($problem !== null) {
                throw new MediaFailure($problem, 'The image headers show a problem that intake should have refused.');
            }
            $probe = app(SiteImageInspection::class)->inspect($input, $image->mime_type, $workspace);
            if ($probe['width'] !== $image->width || $probe['height'] !== $image->height || ! SiteImageSlot::accepts($image->slot, $probe['width'], $probe['height'])) {
                throw new MediaFailure('invalid_image', 'The scanned image does not match the size and shape checked at upload.');
            }
            $sanitized = app(ArtworkDerivative::class)->build($input, $image->mime_type, $workspace)[0]['file'];
            if (SiteImageInspection::pngHasTransparency($sanitized)) {
                throw new MediaFailure('transparent_image', 'Use an image without transparency.');
            }
            $outputs = app(SiteImageDerivatives::class)->build($sanitized, $image->slot, $image->width, $image->height, $workspace);
            $runner = app(BoundedMediaProcess::class);
            $evidence = [
                'source_scan' => $scan, 'pixel_format' => $probe['pix_fmt'],
                'ffmpeg_version' => strtok($runner->run([config('media.ffmpeg'), '-version'], $workspace), "\n"),
                'ffprobe_version' => strtok($runner->run([config('media.ffprobe'), '-version'], $workspace), "\n"),
            ];
            $directory = (string) Str::uuid();
            $variants = [];
            foreach ($outputs as $output) {
                $relative = $files->promoteUnder('site-images/revisions', $output['file'], $directory, $output['name']);
                $promoted[] = $relative;
                $path = $files->resolve($relative);
                $variants[] = ['format' => $output['format'], 'width' => $output['width'], 'height' => $output['height'],
                    'storage_path' => $relative, 'sha256' => hash_file('sha256', $path), 'size_bytes' => filesize($path)];
            }
            $ready = $this->complete($imageId, $token, $variants, $evidence);
            $promoted = [];

            return $ready;
        } catch (MediaFailure $failure) {
            if ($failure->failureCode !== 'claim_lost') {
                $this->fail($imageId, $token, $failure->failureCode);
            }

            return SiteImage::findOrFail($imageId);
        } catch (Throwable $exception) {
            // Unexpected errors (a lost database connection, a killed tool) are logged and treated as temporary.
            report($exception);
            $this->fail($imageId, $token, 'processing_interrupted');

            return SiteImage::findOrFail($imageId);
        } finally {
            if ($promoted !== []) {
                // A commit can succeed and still raise afterwards (a listener, a dropped connection), so only files no variant
                // row references are this run's to remove. If that cannot be checked, orphans are safer than a ready image's files.
                try {
                    $referenced = SiteImageVariant::query()->whereIn('storage_path', $promoted)->pluck('storage_path')->all();
                    foreach (array_diff($promoted, $referenced) as $relative) {
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

    /**
     * Takes the image for this run: a quarantined image, or one whose previous claim expired.
     *
     * @return array{0: ?SiteImage, 1: ?string}
     */
    private function claim(int $imageId): array
    {
        return DB::transaction(function () use ($imageId): array {
            $image = SiteImage::query()->whereKey($imageId)->lockForUpdate()->first();
            if ($image === null || ! in_array($image->status, ['quarantined', 'processing'], true)
                || ($image->status === 'processing' && $image->claimed_until !== null && $image->claimed_until->isFuture())) {
                return [null, null];
            }
            if ($image->status === 'processing') {
                // The worker holding this claim stopped without recording an outcome; audit that attempt before taking over.
                AuditEvent::record('site.image.retry_pending', $image, ['failure_code' => 'processing_interrupted', 'attempt' => $image->attempts,
                    'claimed_until' => $image->claimed_until?->utc()->toIso8601ZuluString()]);
            }
            $token = (string) Str::uuid();
            $image->forceFill(['status' => 'processing', 'claim_token' => $token, 'claimed_until' => now()->addSeconds(self::LEASE_SECONDS),
                'attempts' => $image->attempts + 1, 'failure_code' => null])->save();
            AuditEvent::record('site.image.processing', $image, ['attempt' => $image->attempts]);

            return [$image, $token];
        });
    }

    /** @param  list<array{format: string, width: int, height: int, storage_path: string, sha256: string, size_bytes: int}>  $variants */
    private function complete(int $imageId, string $token, array $variants, array $evidence): SiteImage
    {
        return DB::transaction(function () use ($imageId, $token, $variants, $evidence): SiteImage {
            $image = SiteImage::query()->whereKey($imageId)->lockForUpdate()->firstOrFail();
            if ($image->status !== 'processing' || $image->claim_token !== $token) {
                throw new MediaFailure('claim_lost', 'Another worker took over this image.');
            }
            $now = now();
            foreach ($variants as $variant) {
                SiteImageVariant::create($variant + ['site_image_id' => $image->id, 'created_at' => $now]);
            }
            $fingerprint = SiteImageSlot::fingerprint();
            $manifest = SiteImageManifest::hash($image->slot, $fingerprint, $variants);
            $image->forceFill(['status' => 'ready', 'claim_token' => null, 'claimed_until' => null, 'profile_version' => SiteImageSlot::PROFILE_VERSION,
                'profile_fingerprint' => $fingerprint, 'evidence' => $evidence, 'manifest_sha256' => $manifest, 'processed_at' => $now])->save();
            AuditEvent::record('site.image.processed', $image, ['variants' => count($variants), 'manifest_sha256' => $manifest]);

            return $image->fresh();
        });
    }

    private function fail(int $imageId, ?string $token, string $code): void
    {
        DB::transaction(function () use ($imageId, $token, $code): void {
            $image = SiteImage::query()->whereKey($imageId)->lockForUpdate()->first();
            // Another worker may own it now; only the run holding the claim records the outcome.
            if ($image === null || $image->status !== 'processing' || $token === null || $image->claim_token !== $token) {
                return;
            }
            $transient = in_array($code, self::TRANSIENT, true);
            $image->forceFill($transient
                ? ['status' => 'quarantined', 'claim_token' => null, 'claimed_until' => null, 'failure_code' => $code]
                : ['status' => 'failed', 'claim_token' => null, 'claimed_until' => null, 'failure_code' => $code, 'processed_at' => now()])->save();
            AuditEvent::record($transient ? 'site.image.retry_pending' : 'site.image.failed', $image, ['failure_code' => $code, 'attempt' => $image->attempts]);
        });
    }
}
