<?php

namespace App\Domain\SoundKits\Products;

use App\Domain\SiteBuilder\Models\SiteImageVariant;

/**
 * Public page props and metadata for listed sound kits (BL-53). Callers check {@see SoundKitStorefrontPolicy} first. A kit
 * exists publicly only while {@see SoundKitProducts::listed()} holds. The projection carries the staff-entered title, summary and
 * price and a few facts counted from the verified manifest. It never carries member file names, hashes, storage paths, the
 * archive location, license text or any download or purchase target: there is none yet. The approved cover and demo are separate
 * public assets ({@see SoundKitProductMedia}): the cover is served by content hash through the site image route, the demo by an
 * opaque id through {@see SoundKitStorefrontController::demo()}, and neither address can reach the private archive.
 */
final class SoundKitPresentation
{
    /** The index never lists more than this many kits, which also bounds the sitemap. */
    public const LIMIT = 50;

    /** @return list<array<string, mixed>> listed kits, newest first */
    public function index(): array
    {
        $rows = [];
        foreach (SoundKitProduct::query()->where('is_published', true)->orderByDesc('id')->limit(self::LIMIT)->get() as $product) {
            if (app(SoundKitProducts::class)->listed($product)) {
                $rows[] = $this->summary($product);
            }
        }

        return $rows;
    }

    /** @return array<string, mixed>|null null (a 404) when unpublished, unknown or not currently listable */
    public function detail(string $slug): ?array
    {
        $product = SoundKitProduct::query()->where('is_published', true)->where('slug', $slug)->first();
        if ($product === null || ! app(SoundKitProducts::class)->listed($product)) {
            return null;
        }

        return $this->summary($product) + ['summary' => $product->summary, 'demo' => $this->demo($product)];
    }

    /** @return list<string> paths of every publicly visible kit, for the sitemap */
    public function paths(): array
    {
        return array_column($this->index(), 'path');
    }

    /**
     * @param  array<string, mixed>|null  $kit  a detail projection, or null for the index
     * @return array<string, mixed>
     */
    public function metadata(?array $kit): array
    {
        $image = ['path' => '/images/storefront-hero.jpg', 'width' => 2400, 'height' => 890, 'type' => 'image/jpeg'];
        $imageAlt = 'VASEY.AUDIO studio and audio production artwork';
        if ($kit !== null && is_array($kit['cover'] ?? null)) {
            $largest = end($kit['cover']['jpeg']);
            $image = ['path' => $largest['url'], 'width' => $largest['width'], 'height' => $largest['height'], 'type' => 'image/jpeg'];
            $imageAlt = $kit['cover']['alt'];
        }
        if ($kit === null) {
            $title = 'Sound kits — VASEY.AUDIO';
            $description = 'Sample kits from the VASEY.AUDIO studio.';
            $path = '/sound-kits';
        } else {
            $title = $this->text($kit['title'].' — VASEY.AUDIO', 160);
            $description = $this->text($kit['summary'] ?? ('A sound kit of '.$kit['memberCount'].' '.($kit['memberCount'] === 1 ? 'sample' : 'samples').'.'), 300);
            $path = $kit['path'];
        }

        return [
            'title' => $title, 'description' => $description, 'canonicalUrl' => $this->absolute($path), 'imageUrl' => $this->absolute($image['path']),
            'imageAlt' => $this->text($imageAlt, 200), 'imageWidth' => $image['width'], 'imageHeight' => $image['height'],
            'imageType' => $image['type'], 'type' => 'website',
            'robots' => app()->environment('production') ? 'index, follow' : 'noindex, nofollow',
        ];
    }

    /** @return array<string, mixed> */
    private function summary(SoundKitProduct $product): array
    {
        $members = $product->archiveRevision()->first()?->manifest['members'] ?? [];
        $microseconds = 0;
        foreach ($members as $member) {
            $microseconds += is_int($member['audio']['duration_microseconds'] ?? null) ? $member['audio']['duration_microseconds'] : 0;
        }

        return [
            'slug' => $product->slug, 'path' => '/sound-kits/'.$product->slug, 'title' => $product->title,
            'memberCount' => count($members), 'totalDurationSeconds' => intdiv($microseconds, 1000000),
            'priceMinor' => $product->price_minor, 'currency' => $product->currency,
            // Always false until a kit checkout exists: the page shows a notice, never a purchase control.
            'purchasable' => app(SoundKitStorefrontPolicy::class)->purchasable(),
            'cover' => $this->cover($product),
        ];
    }

    /** @return array<string, mixed>|null the approved cover's sizes by content-hashed address, or null (nothing is drawn) */
    private function cover(SoundKitProduct $product): ?array
    {
        $image = app(SoundKitProductMedia::class)->cover($product);
        if ($image === null) {
            return null;
        }
        $set = ['jpeg' => [], 'webp' => []];
        foreach ($image->variants->sortBy('width') as $variant) {
            $set[$variant->format][] = ['url' => $this->imageUrl($variant), 'width' => $variant->width, 'height' => $variant->height];
        }
        $largest = $image->variants->where('format', 'jpeg')->sortBy('width')->last();

        return $largest === null ? null : ['alt' => $product->cover_alt, 'width' => $largest->width, 'height' => $largest->height, 'jpeg' => $set['jpeg'], 'webp' => $set['webp']];
    }

    /** @return array<string, mixed>|null the approved demo's player facts, or null; never a path, hash or source name */
    private function demo(SoundKitProduct $product): ?array
    {
        $demo = app(SoundKitProductMedia::class)->demo($product);

        return $demo === null ? null : [
            'url' => route('sound-kits.demo', ['slug' => $product->slug, 'demo' => $demo->public_id], false),
            'durationSeconds' => $demo->duration_seconds, 'waveform' => array_values($demo->waveform),
        ];
    }

    private function imageUrl(SiteImageVariant $variant): string
    {
        return route('site-images.show', ['file' => $variant->sha256.($variant->format === 'webp' ? '.webp' : '.jpg')], false);
    }

    private function absolute(string $path): string
    {
        // Pin sharing identity to the configured site URL; never the request Host.
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    private function text(string $value, int $limit): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 0, $limit);
    }
}
