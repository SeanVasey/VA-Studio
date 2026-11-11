<?php

namespace App\Application\Media;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Support\Facades\Cache;

final class MediaIntegrity
{
    // Hits never extend the verification window. Path safety is checked by VerifiedMedia.
    public const CACHE_SECONDS = 60;

    // A full hash that disagreed with the record is remembered for that exact file identity, so a listing can hide the file without reading it.
    public const MISMATCH_SECONDS = 30 * 86400;

    public function matches(MediaAsset $asset, string $path): bool
    {
        $identity = $this->identity($asset, $path);
        if ($identity === null) {
            return false;
        }
        $digest = $this->digest($asset, $path, $identity);
        if (Cache::get('media-integrity:'.$digest) === true) {
            return true;
        }
        $hash = @hash_file('sha256', $path);
        clearstatcache(true, $path);
        $after = @lstat($path);
        $stable = $after && $identity === array_intersect_key($after, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
        if (! is_string($hash) || ! $stable) {
            return false;
        }
        if (! hash_equals($asset->sha256, $hash)) {
            Cache::put('media-integrity-mismatch:'.$digest, true, self::MISMATCH_SECONDS);

            return false;
        }
        Cache::forget('media-integrity-mismatch:'.$digest);
        Cache::put('media-integrity:'.$digest, true, self::CACHE_SECONDS);

        return true;
    }

    /**
     * Public listing only; never reads file content. The file must be a regular file of the recorded size, and no full hash may have
     * disagreed with the record for its current identity. Anything that hands bytes to anyone, or commits to selling them, uses matches().
     */
    public function plausible(MediaAsset $asset, string $path): bool
    {
        $identity = $this->identity($asset, $path);

        return $identity !== null && Cache::get('media-integrity-mismatch:'.$this->digest($asset, $path, $identity)) !== true;
    }

    /** @return ?array<string, int> device, inode, size, modification and change times */
    private function identity(MediaAsset $asset, string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! $stat || ($stat['mode'] & 0170000) !== 0100000 || (int) $asset->size_bytes !== $stat['size'] || ! preg_match('/\A[a-f0-9]{64}\z/D', $asset->sha256 ?? '')) {
            return null;
        }

        return array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }

    private function digest(MediaAsset $asset, string $path, array $identity): string
    {
        return hash('sha256', json_encode([$asset->id, $path, $asset->sha256, $identity], JSON_THROW_ON_ERROR));
    }
}
