<?php

namespace App\Domain\SiteBuilder;

use App\Domain\SiteBuilder\Models\SiteImage;
use Illuminate\Support\Facades\Cache;

/**
 * Warns staff when a hero image is bright behind the heading, which is printed in white straight onto it (D-25). This is a
 * prompt to check the preview, not a gate: the measurement is an average over the area the heading usually covers.
 */
final class SiteImageContrast
{
    /** Where the heading sits over each hero image, as fractions of its width and height: left, top, right, bottom. */
    private const HEADING = ['hero_desktop' => [0.0, 0.1, 0.6, 0.8], 'hero_mobile' => [0.0, 0.1, 0.95, 0.65]];

    /** Mean relative luminance above which white text drops below about 3:1, the minimum for large text. */
    public const LIMIT = 0.3;

    /** True when bright, false when dark enough, null when the image cannot be measured (the preview still decides). */
    public function brightUnderHeading(SiteImage $image): ?bool
    {
        if (! isset(self::HEADING[$image->slot]) || $image->status !== 'ready' || ! is_string($image->manifest_sha256)) {
            return null;
        }
        // Ready images never change, so the measurement is kept for the exact manifest it was taken from.
        $key = 'site-image-contrast:v1:'.$image->id.':'.$image->manifest_sha256;
        $cached = Cache::get($key);
        if (is_bool($cached)) {
            return $cached;
        }
        $variant = $image->thumbnail();
        $bytes = $variant === null ? null : app(SiteImageFiles::class)->verifiedBytes($variant);
        $picture = $bytes === null ? false : @imagecreatefromstring($bytes);
        if (! $picture instanceof \GdImage) {
            return null;
        }
        [$left, $top, $right, $bottom] = self::HEADING[$image->slot];
        [$width, $height] = [imagesx($picture), imagesy($picture)];
        $sum = 0.0;
        $samples = 0;
        for ($y = (int) ($top * $height); $y < (int) ($bottom * $height); $y += 4) {
            for ($x = (int) ($left * $width); $x < (int) ($right * $width); $x += 4) {
                $rgb = imagecolorat($picture, $x, $y);
                $sum += $this->luminance(($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
                $samples++;
            }
        }
        $bright = $samples > 0 && $sum / $samples > self::LIMIT;
        Cache::forever($key, $bright);

        return $bright;
    }

    /** WCAG relative luminance of an sRGB colour. */
    private function luminance(int $red, int $green, int $blue): float
    {
        $linear = fn (int $channel): float => ($value = $channel / 255) <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $linear($red) + 0.7152 * $linear($green) + 0.0722 * $linear($blue);
    }
}
