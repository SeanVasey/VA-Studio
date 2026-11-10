<?php

namespace App\Domain\SoundKits\Products;

/**
 * Public page props and metadata for listed sound kits (BL-53). Callers check {@see SoundKitStorefrontPolicy} first. A kit
 * exists publicly only while {@see SoundKitProducts::listed()} holds. The projection carries the staff-entered title, summary and
 * price and a few facts counted from the verified manifest. It never carries member file names, hashes, storage paths, the
 * archive location, license text or any download or purchase target: there is none yet.
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

        return $this->summary($product) + ['summary' => $product->summary];
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
            'imageAlt' => 'VASEY.AUDIO studio and audio production artwork', 'imageWidth' => $image['width'], 'imageHeight' => $image['height'],
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
        ];
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
