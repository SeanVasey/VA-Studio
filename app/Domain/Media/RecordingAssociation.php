<?php

namespace App\Domain\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\StemsRecording;
use App\Support\CanonicalJson;
use Throwable;

/** Processing ancestry stays intact; stems use a separate immutable recording attestation. */
class RecordingAssociation
{
    public function matches(MediaAsset $asset, MediaAsset $preview): bool
    {
        if ($asset->track_id !== $preview->track_id || $preview->role !== 'preview_tagged' || ! $preview->parent_asset_id) {
            return false;
        }
        if ($asset->role === 'stems_zip') {
            return $this->verified($asset)?->recording_source_id === $preview->parent_asset_id;
        }

        return in_array($asset->role, ['master_wav', 'download_mp3'], true) && $asset->parent_asset_id === $preview->parent_asset_id;
    }

    /** Immutable recording attestation, without opening any media file. */
    public function retained(MediaAsset $stems): ?StemsRecording
    {
        try {
            $binding = StemsRecording::where('stems_asset_id', $stems->id)->first();
            $master = $binding?->master;
            $preview = $binding?->preview;
            if (! $binding || ! $master || ! $preview || $stems->role !== 'stems_zip' || $master->role !== 'master_wav' || $preview->role !== 'preview_tagged'
                || $binding->track_id !== $stems->track_id || $master->track_id !== $stems->track_id || $preview->track_id !== $stems->track_id
                || ! $master->parent_asset_id || $binding->recording_source_id !== $master->parent_asset_id || $preview->parent_asset_id !== $master->parent_asset_id
                || $preview->processing_run_id !== $master->processing_run_id || ! $binding->verified_by || ! $binding->verified_at || trim($binding->verification_reference) === ''
                || $binding->canonicalization_version !== CanonicalJson::VERSION || ! hash_equals($binding->evidence_hash, CanonicalJson::hash($binding->evidence))
                || ! hash_equals($binding->evidence_hash, CanonicalJson::hash($this->evidence($binding, $stems, $master, $preview)))) {
                return null;
            }
            return $binding;
        } catch (Throwable) {
            return null;
        }
    }

    public function verified(MediaAsset $stems): ?StemsRecording
    {
        try {
            $binding = $this->retained($stems);
            if (! $binding) { return null; }
            foreach ([$stems, $binding->master, $binding->preview] as $asset) {
                if (! app(VerifiedMedia::class)->available($asset)) {
                    return null;
                }
            }

            return $binding;
        } catch (Throwable) {
            return null;
        }
    }

    public function evidence(StemsRecording $binding, MediaAsset $stems, MediaAsset $master, MediaAsset $preview): array
    {
        $identity = fn (MediaAsset $asset) => $asset->only(['id', 'track_id', 'role', 'sha256', 'size_bytes', 'parent_asset_id', 'processing_run_id']);

        return [
            'schema_version' => 1, 'track_id' => $binding->track_id, 'recording_source_id' => $binding->recording_source_id,
            'stems' => $identity($stems) + ['manifest_sha256' => $stems->technical_metadata['manifest_sha256'] ?? null],
            'master' => $identity($master), 'preview' => $identity($preview),
            'attestation' => ['same_recording_confirmed' => true, 'reference' => $binding->verification_reference, 'verified_by' => $binding->verified_by, 'verified_at' => $binding->verified_at?->toISOString()],
        ];
    }

    public function snapshot(MediaAsset $asset): ?array
    {
        return $this->verified($asset)?->only(['id', 'stems_asset_id', 'master_asset_id', 'preview_asset_id', 'recording_source_id', 'evidence_hash']);
    }

    /** These additional private revisions are part of the evidence promised by a new offer/quote. */
    public function relatedAssetIds(MediaAsset $asset): array
    {
        if ($asset->role !== 'stems_zip') {
            return [];
        }
        $binding = $this->verified($asset);

        return $binding ? [$binding->master_asset_id, $binding->preview_asset_id] : [];
    }

    public function freshDigestMatches(MediaAsset $asset): bool
    {
        $path = app(VerifiedMedia::class)->path($asset);
        if (! $path) {
            return false;
        }
        clearstatcache(true, $path);
        $hash = @hash_file('sha256', $path);

        return is_string($hash) && hash_equals($asset->sha256, $hash) && @filesize($path) === $asset->size_bytes;
    }
}
