<?php

namespace App\Domain\SiteBuilder;

use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\Models\SiteImageVariant;
use App\Support\CanonicalJson;

/** One hash over a site image's slot, profile and exact variant set, so a site release can pin the bytes it shows. */
final class SiteImageManifest
{
    /** @param  iterable<array{format: string, width: int, height: int, sha256: string, size_bytes: int}|SiteImageVariant>  $variants */
    public static function hash(string $slot, string $fingerprint, iterable $variants): string
    {
        $entries = [];
        foreach ($variants as $variant) {
            $row = $variant instanceof SiteImageVariant ? $variant->only(['format', 'width', 'height', 'sha256', 'size_bytes']) : $variant;
            $entries[] = ['format' => (string) $row['format'], 'width' => (int) $row['width'], 'height' => (int) $row['height'],
                'sha256' => (string) $row['sha256'], 'size_bytes' => (int) $row['size_bytes']];
        }
        usort($entries, fn (array $a, array $b): int => [$a['format'], $a['width']] <=> [$b['format'], $b['width']]);

        return CanonicalJson::hash(['profile' => $fingerprint, 'slot' => $slot, 'variants' => $entries]);
    }

    /** Recomputes a ready image's manifest from its stored variant rows, using them as loaded when the caller loaded them. */
    public static function matches(SiteImage $image): bool
    {
        if ($image->status !== 'ready' || ! is_string($image->manifest_sha256) || ! is_string($image->profile_fingerprint)) {
            return false;
        }
        $variants = $image->relationLoaded('variants') ? $image->variants : $image->variants()->get();

        return $variants->count() === SiteImageSlot::variantCount($image->slot)
            && hash_equals($image->manifest_sha256, self::hash($image->slot, $image->profile_fingerprint, $variants));
    }
}
