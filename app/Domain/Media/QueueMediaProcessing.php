<?php

namespace App\Domain\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Jobs\ProcessMedia;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class QueueMediaProcessing
{
    public function handle(MediaAsset $source, User $actor): MediaProcessingRun
    {
        Gate::forUser($actor)->authorize('administer-catalog');

        return DB::transaction(function () use ($source, $actor) {
            $source = MediaAsset::query()->lockForUpdate()->findOrFail($source->id);
            $track = $source->track()->lockForUpdate()->firstOrFail();
            if ($track->status === 'published') {
                throw ValidationException::withMessages(['media' => 'Unpublish the track before processing a replacement media revision.']);
            }
            if ($source->disk !== 'local' || ! str_starts_with($source->storage_path, 'quarantine/') || $source->processing_run_id || ! in_array($source->role, ['master_wav', 'artwork'], true)) {
                throw ValidationException::withMessages(['media' => 'Only quarantined master WAV or PNG/JPEG artwork uploads are supported. Stems and uploaded previews remain quarantined.']);
            }
            if (! in_array($source->status, ['quarantined', 'processed'], true)) {
                throw ValidationException::withMessages(['media' => 'This source is not eligible for processing.']);
            }
            try {
                $path = app(PrivateMediaFiles::class)->resolve($source->storage_path);
            } catch (MediaFailure $failure) {
                throw ValidationException::withMessages(['media' => $failure->getMessage()]);
            }
            $maxBytes = (int) config($source->role === 'artwork' ? 'media.max_artwork_bytes' : 'media.max_source_bytes');
            if (filesize($path) < 12 || filesize($path) > $maxBytes) {
                throw ValidationException::withMessages(['media' => 'The upload is empty or exceeds the role size limit.']);
            }
            $hash = hash_file('sha256', $path);
            if (! $source->sha256 || ! hash_equals($source->sha256, $hash) || (int) $source->size_bytes !== filesize($path)) {
                throw ValidationException::withMessages(['media' => 'The upload integrity does not match intake evidence. Upload a new revision.']);
            }
            $profile = app(MediaProfile::class)->current($source->role);
            $fingerprint = app(MediaProfile::class)->fingerprint($profile);
            $run = MediaProcessingRun::query()->where('source_asset_id', $source->id)->where('profile_fingerprint', $fingerprint)->lockForUpdate()->first();
            if ($run && $run->status === 'completed') {
                return $run;
            }
            if ($run && $run->status === 'processing' && $run->started_at?->gt(now()->subSeconds(config('media.claim_lease_seconds')))) {
                return $run;
            }
            if (! $run) {
                $run = MediaProcessingRun::create(['source_asset_id' => $source->id, 'requested_by' => $actor->id, 'profile_version' => $profile['version'], 'profile_fingerprint' => $fingerprint, 'input_sha256' => $hash, 'profile' => $profile]);
            } else {
                $run->update(['status' => 'queued', 'claim_token' => null, 'failure_code' => null, 'failure_message' => null, 'failed_at' => null]);
            }
            AuditEvent::record('media.processing.queued', $run, ['source_asset_id' => $source->id, 'profile_version' => $profile['version']], $actor->id);
            ProcessMedia::dispatch($run->id)->onQueue(config('media.queue'))->afterCommit();

            return $run;
        });
    }
}
