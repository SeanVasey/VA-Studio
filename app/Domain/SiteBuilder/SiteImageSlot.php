<?php

namespace App\Domain\SiteBuilder;

use App\Domain\Media\MediaProfile;

/** The fixed places a site image can fill, and the sizes each one is prepared in (D-25). */
final class SiteImageSlot
{
    /** How far an upload's shape may differ from its slot's, as a fraction of the slot's aspect ratio. */
    public const TOLERANCE = 0.03;

    public const PROFILE_VERSION = 'site-image-v1';

    /**
     * Each slot's reference size sets its shape. Uploads must be at least as wide as the largest output, so nothing is enlarged.
     *
     * @var array<string, array{label: string, width: int, height: int, widths: list<int>, formats: list<string>, crop: bool}>
     */
    public const DEFINITIONS = [
        'hero_desktop' => ['label' => 'Home hero, desktop', 'width' => 2400, 'height' => 890, 'widths' => [1200, 1800, 2400], 'formats' => ['jpeg', 'webp'], 'crop' => false],
        'hero_mobile' => ['label' => 'Home hero, mobile', 'width' => 960, 'height' => 890, 'widths' => [480, 720, 960], 'formats' => ['jpeg', 'webp'], 'crop' => false],
        'studio' => ['label' => 'Studio image', 'width' => 1440, 'height' => 630, 'widths' => [720, 1080, 1440], 'formats' => ['jpeg', 'webp'], 'crop' => false],
        // Social platforms expect exactly 1200 x 630, so the share image is trimmed to it and kept as JPEG only.
        'share' => ['label' => 'Share image', 'width' => 1200, 'height' => 630, 'widths' => [1200], 'formats' => ['jpeg'], 'crop' => true],
    ];

    /** Encoder settings that are part of the recorded profile, so any change is visible in the evidence. */
    private const ENCODING = ['jpeg' => ['codec' => 'mjpeg', 'q' => 3, 'pix_fmt' => 'yuvj420p'], 'webp' => ['codec' => 'libwebp', 'quality' => 80, 'compression_level' => 4],
        'scaler' => 'lanczos', 'metadata' => 'none', 'bitexact' => true];

    /** The FFmpeg option that carries each recorded encoder setting. */
    private const ENCODER_OPTIONS = ['codec' => '-c:v', 'q' => '-q:v', 'pix_fmt' => '-pix_fmt', 'quality' => '-quality', 'compression_level' => '-compression_level'];

    /**
     * A format's encoder arguments, built from the recorded settings in their order, so the fingerprint always describes the bytes.
     *
     * @return list<string>
     */
    public static function encoderArguments(string $format): array
    {
        $arguments = [];
        foreach (self::ENCODING[$format] as $setting => $value) {
            array_push($arguments, self::ENCODER_OPTIONS[$setting], (string) $value);
        }

        return $arguments;
    }

    public static function exists(string $slot): bool
    {
        return array_key_exists($slot, self::DEFINITIONS);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_map(fn (array $definition): string => $definition['label'], self::DEFINITIONS);
    }

    public static function label(string $slot): string
    {
        return self::DEFINITIONS[$slot]['label'] ?? $slot;
    }

    /** Plain-language size and shape requirement shown to staff. */
    public static function requirement(string $slot): string
    {
        $definition = self::DEFINITIONS[$slot];
        $ratio = number_format($definition['width'] / $definition['height'], 2);

        return $definition['crop']
            ? "At least {$definition['width']} × {$definition['height']} px, about {$ratio}:1. Trimmed to exactly {$definition['width']} × {$definition['height']}."
            : "At least {$definition['width']} px wide, about {$ratio}:1 (for example {$definition['width']} × {$definition['height']}).";
    }

    public static function accepts(string $slot, int $width, int $height): bool
    {
        if (! self::exists($slot) || $width < 1 || $height < 1) {
            return false;
        }
        $definition = self::DEFINITIONS[$slot];
        $expected = $definition['width'] / $definition['height'];

        return $width >= $definition['width'] && (! $definition['crop'] || $height >= $definition['height'])
            && abs(($width / $height) / $expected - 1) <= self::TOLERANCE;
    }

    public static function variantCount(string $slot): int
    {
        return count(self::DEFINITIONS[$slot]['widths']) * count(self::DEFINITIONS[$slot]['formats']);
    }

    /**
     * The exact output sizes for a source, keeping the source's own shape except for the trimmed share image.
     *
     * @return list<array{format: string, width: int, height: int}>
     */
    public static function variants(string $slot, int $sourceWidth, int $sourceHeight): array
    {
        $definition = self::DEFINITIONS[$slot];
        $sizes = [];
        foreach ($definition['formats'] as $format) {
            foreach ($definition['widths'] as $width) {
                $height = $definition['crop'] ? $definition['height'] : max(1, (int) round($width * $sourceHeight / $sourceWidth));
                $sizes[] = ['format' => $format, 'width' => $width, 'height' => $height];
            }
        }

        return $sizes;
    }

    /** @return array<string, mixed> */
    public static function profile(): array
    {
        return ['version' => self::PROFILE_VERSION, 'slots' => self::DEFINITIONS, 'tolerance' => self::TOLERANCE, 'encoding' => self::ENCODING];
    }

    public static function fingerprint(): string
    {
        return app(MediaProfile::class)->fingerprint(self::profile());
    }
}
