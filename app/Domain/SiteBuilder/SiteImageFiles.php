<?php

namespace App\Domain\SiteBuilder;

use App\Domain\Media\PrivateMediaFiles;
use App\Domain\SiteBuilder\Models\SiteImageVariant;
use Throwable;

/**
 * Reads a variant's private file only when its image is ready, its manifest matches and the bytes read match the recorded hash.
 *
 * Variants are small, so every read hashes the exact bytes that will be sent: nothing is cached, and a file changed after
 * the check cannot be served in its place.
 */
final class SiteImageFiles
{
    /** Far above any prepared size; a larger record is treated as damaged rather than read into memory. */
    public const MAX_BYTES = 16 * 1024 * 1024;

    public function verifiedBytes(SiteImageVariant $variant): ?string
    {
        try {
            $image = $variant->image()->first();
            if ($image === null || ! SiteImageManifest::matches($image)
                || ! preg_match('~\Asite-images/revisions/[a-f0-9-]{36}/[0-9]+\.(?:jpg|webp)\z~D', $variant->storage_path)
                || ! preg_match('/\A[a-f0-9]{64}\z/D', $variant->sha256) || $variant->size_bytes < 1 || $variant->size_bytes > self::MAX_BYTES) {
                return null;
            }
            $path = app(PrivateMediaFiles::class)->resolve($variant->storage_path);
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if (! $stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== $variant->size_bytes) {
                return null;
            }
            $bytes = @file_get_contents($path, false, null, 0, $variant->size_bytes + 1);

            return is_string($bytes) && strlen($bytes) === $variant->size_bytes && hash_equals($variant->sha256, hash('sha256', $bytes)) ? $bytes : null;
        } catch (Throwable) {
            return null;
        }
    }
}
