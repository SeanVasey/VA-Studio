<?php

namespace App\Domain\Media;

use App\Application\Media\MediaIntegrity;
use App\Domain\Media\Models\MediaAsset;
use Throwable;

class VerifiedMedia
{
    public function available(MediaAsset $asset): bool
    {
        return $this->path($asset) !== null;
    }

    public function path(MediaAsset $asset): ?string
    {
        try {
            if ($asset->status !== 'ready' || $asset->disk !== 'local' || ! $asset->verified_at || ! $asset->verified_by || ! $asset->parent_asset_id || ! $asset->processing_run_id || ! preg_match('~\Amedia/revisions/[a-f0-9-]{36}/(?:master\.wav|delivery\.mp3|preview\.mp3|artwork\.png)\z~D', $asset->storage_path)) {
                return null;
            }
            $run = $asset->processingRun()->first();
            $source = $asset->parent()->first();
            if (! $run || ! $source || $run->status !== 'completed' || ! $run->completed_at || $source->status !== 'processed' || $run->source_asset_id !== $source->id || $source->track_id !== $asset->track_id || ! in_array($asset->id, $run->output_asset_ids ?? [], true) || ! hash_equals($source->sha256 ?? '', $run->input_sha256) || ! hash_equals($run->profile_fingerprint, app(MediaProfile::class)->fingerprint($run->profile))) {
                return null;
            }
            $scan = $run->evidence['source_scan'] ?? [];
            $engine = $scan['engine'] ?? null;
            if (($scan['status'] ?? null) !== 'clean' || ! hash_equals($run->input_sha256, $scan['sha256'] ?? '') || ! ($engine === 'clamav' || ($engine === 'test-only' && app()->environment('testing')))) {
                return null;
            }
            $expectedRoles = $source->role === 'master_wav' ? ['download_mp3', 'master_wav', 'preview_tagged'] : ($source->role === 'artwork' ? ['artwork'] : []);
            $outputs = $run->outputs()->get();
            if ($expectedRoles === [] || $outputs->pluck('role')->sort()->values()->all() !== $expectedRoles || $outputs->pluck('id')->sort()->values()->all() !== collect($run->output_asset_ids)->sort()->values()->all()) {
                return null;
            }
            foreach ($outputs as $output) {
                if ($output->track_id !== $source->track_id || $output->parent_asset_id !== $source->id || $output->status !== 'ready') {
                    return null;
                }
            }
            if ($asset->role === 'master_wav' && ! hash_equals($asset->sha256 ?? '', $run->input_sha256)) {
                return null;
            }
            if ($source->role === 'master_wav') {
                $tagScan = $run->evidence['tag_scan'] ?? [];
                if (($tagScan['status'] ?? null) !== 'clean' || ! hash_equals($run->profile['tag_sha256'] ?? '', $tagScan['sha256'] ?? '') || ! (($tagScan['engine'] ?? null) === 'clamav' || (($tagScan['engine'] ?? null) === 'test-only' && app()->environment('testing')))) {
                    return null;
                }
            }
            if ($asset->role === 'preview_tagged') {
                $metadata = $asset->technical_metadata ?? [];
                if (empty($metadata['waveform']) || ! hash_equals($metadata['waveform_sha256'] ?? '', hash('sha256', json_encode($metadata['waveform'], JSON_THROW_ON_ERROR))) || ! hash_equals($metadata['tag_sha256'] ?? '', $run->profile['tag_sha256'] ?? '') || ($metadata['full_length'] ?? false) !== true) {
                    return null;
                }
            }
            $path = app(PrivateMediaFiles::class)->resolve($asset->storage_path);
            if (! app(MediaIntegrity::class)->matches($asset, $path)) {
                return null;
            }

            return $path;
        } catch (Throwable) {
            return null;
        }
    }
}
