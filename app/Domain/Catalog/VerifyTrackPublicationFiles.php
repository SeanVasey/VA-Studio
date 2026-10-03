<?php

namespace App\Domain\Catalog;

use App\Domain\Media\MediaFailure;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\VerifiedMedia;
use Illuminate\Validation\ValidationException;

/** Fresh local-file inspection, independent of the public-read integrity cache. */
class VerifyTrackPublicationFiles
{
    public function handle(TrackPublicationManifest $manifest): void
    {
        try {
            $this->inspect($manifest);
        } catch (MediaFailure) {
            $this->invalid();
        }
    }

    private function inspect(TrackPublicationManifest $manifest): void
    {
        $payload = $manifest->payload();
        $ids = [$payload['artwork']['asset_id'], $payload['preview_tagged']['asset_id']];
        foreach ($payload['offers'] as $offer) {
            foreach ($offer['deliverables'] as $entry) {
                $ids[] = $entry['asset_id'];
                if ($entry['recording_binding'] !== null) {
                    $ids[] = $entry['recording_binding']['master_asset_id'];
                    $ids[] = $entry['recording_binding']['preview_asset_id'];
                }
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $inspected = [];
        foreach ($ids as $id) {
            $asset = MediaAsset::find($id);
            if (! $asset || $asset->track_id !== $payload['track']['id']
                || app(VerifiedMedia::class)->evidence($asset) === null) {
                $this->invalid();
            }
            $files = app(PrivateMediaFiles::class);
            $path = $files->resolve($asset->storage_path);
            clearstatcache(true, $path);
            $before = @lstat($path);
            $input = @fopen($path, 'rb');
            if (! is_resource($input)) {
                $this->invalid();
            }
            try {
                $opened = fstat($input);
                if (! $before || ! $opened || ($opened['mode'] & 0170000) !== 0100000
                    || $opened['size'] !== $asset->size_bytes || $this->identity($before) !== $this->identity($opened)) {
                    $this->invalid();
                }
                $hash = hash_init('sha256');
                $bytes = hash_update_stream($hash, $input, $opened['size'] + 1);
                $digest = hash_final($hash);
                clearstatcache(true, $path);
                $after = @lstat($path);
                $descriptor = fstat($input);
                if ($bytes !== $opened['size'] || ! hash_equals($asset->sha256, $digest)
                    || ! $after || ! $descriptor || $files->resolve($asset->storage_path) !== $path
                    || $this->identity($opened) !== $this->identity($after)
                    || $this->identity($opened) !== $this->identity($descriptor)) {
                    $this->invalid();
                }
                $inspected[] = [$asset->storage_path, $path, $this->identity($opened)];
            } finally {
                fclose($input);
            }
        }
        // Catch a previously inspected path being replaced while a later file was hashed.
        // This is an inspection proof, not a claim that SQL locks prevent external disk writes.
        foreach ($inspected as [$relative, $path, $identity]) {
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (! $current || app(PrivateMediaFiles::class)->resolve($relative) !== $path
                || $this->identity($current) !== $identity) {
                $this->invalid();
            }
        }
    }

    private function identity(array $stat): array
    {
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime']));
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['publication' => 'Publication requires fresh unchanged bytes for every reviewed media revision.']);
    }
}
