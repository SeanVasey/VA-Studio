<?php

namespace App\Domain\SiteBuilder;

use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\Models\SiteImageVariant;
use Illuminate\Support\Collection;

/**
 * Turns an already verified release's image references into page props (D-25). Public pages link content-hashed public URLs;
 * a staff preview links the private preview route instead, because a draft's images are not public yet.
 */
final class SiteImagePresentation
{
    /**
     * The storefront's images; null keeps the built-in image for that place.
     *
     * @return array{hero: ?array{alt: string, desktop: array, mobile: array}, studio: ?array}
     */
    public function storefront(array $verifiedContent, bool $preview = false): array
    {
        $references = SiteImageReferences::of($verifiedContent);
        $images = $this->images($references);
        $set = fn (string $slot): ?array => isset($references[$slot]) ? $this->set($images->get($references[$slot]['id']), $preview) : null;
        [$desktop, $mobile, $studio] = [$set('hero_desktop'), $set('hero_mobile'), $set('studio')];

        return [
            'hero' => $desktop !== null && $mobile !== null ? ['alt' => $verifiedContent['images']['hero']['alt'], 'desktop' => $desktop, 'mobile' => $mobile] : null,
            'studio' => $studio === null ? null : ['alt' => $verifiedContent['images']['studio']['alt']] + $studio,
        ];
    }

    /**
     * The image shown when a page is shared: the share image, else the desktop hero's 1200 px JPEG, else null for the built-in hero.
     *
     * @return array{path: string, width: int, height: int, alt: string, type: string}|null
     */
    public function share(array $verifiedContent, bool $preview = false): ?array
    {
        $references = SiteImageReferences::of($verifiedContent);
        [$slot, $alt] = isset($references['share']) ? ['share', $verifiedContent['images']['share']['alt']]
            : (isset($references['hero_desktop']) ? ['hero_desktop', $verifiedContent['images']['hero']['alt']] : [null, null]);
        $image = $slot === null ? null : $this->images([$slot => $references[$slot]])->first();
        $jpeg = $image?->variants->where('format', 'jpeg')->sortBy(fn (SiteImageVariant $variant): int => abs($variant->width - 1200))->first();

        return $jpeg === null ? null : ['path' => $this->url($jpeg, $preview), 'width' => $jpeg->width, 'height' => $jpeg->height, 'alt' => $alt, 'type' => 'image/jpeg'];
    }

    /** @return Collection<int, SiteImage> */
    private function images(array $references): Collection
    {
        $ids = array_values(array_filter(array_column($references, 'id'), 'is_int'));

        return $ids === [] ? new Collection : SiteImage::query()->with('variants')->whereKey($ids)->get()->keyBy('id');
    }

    /** @return array{width: int, height: int, jpeg: list<array{url: string, width: int, height: int}>, webp: list<array{url: string, width: int, height: int}>} */
    private function set(?SiteImage $image, bool $preview): ?array
    {
        if ($image === null) {
            return null;
        }
        $set = ['width' => 0, 'height' => 0, 'jpeg' => [], 'webp' => []];
        foreach ($image->variants->sortBy('width') as $variant) {
            $set[$variant->format][] = ['url' => $this->url($variant, $preview), 'width' => $variant->width, 'height' => $variant->height];
            if ($variant->format === 'jpeg' && $variant->width > $set['width']) {
                [$set['width'], $set['height']] = [$variant->width, $variant->height];
            }
        }

        return $set;
    }

    private function url(SiteImageVariant $variant, bool $preview): string
    {
        return $preview
            ? route('filament.admin.site-images.preview', $variant->id, false)
            : route('site-images.show', ['file' => $variant->sha256.($variant->format === 'webp' ? '.webp' : '.jpg')], false);
    }
}
