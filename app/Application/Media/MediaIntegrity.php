<?php

namespace App\Application\Media;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Support\Facades\Cache;

final class MediaIntegrity
{
    // Hits never extend the verification window. Path safety is checked by VerifiedMedia.
    public const CACHE_SECONDS = 60;

    public function matches(MediaAsset $asset, string $path): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! $stat || ($stat['mode'] & 0170000) !== 0100000 || (int) $asset->size_bytes !== $stat['size'] || ! preg_match('/\A[a-f0-9]{64}\z/D', $asset->sha256 ?? '')) {
            return false;
        }
        $identity = array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
        $key = 'media-integrity:'.hash('sha256', json_encode([$asset->id, $path, $asset->sha256, $identity], JSON_THROW_ON_ERROR));
        if (Cache::get($key) === true) {
            return true;
        }
        $hash = @hash_file('sha256', $path);
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (! is_string($hash) || ! hash_equals($asset->sha256, $hash) || ! $after || $identity !== array_intersect_key($after, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']))) {
            return false;
        }
        Cache::put($key, true, self::CACHE_SECONDS);

        return true;
    }
}
