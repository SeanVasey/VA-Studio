<?php

namespace App\Domain\Collections;

use App\Domain\Catalog\PublicCatalog;
use App\Domain\Collections\Models\TrackCollection;

/**
 * Public page props and metadata for published collections. Callers check CollectionsPolicy first. A collection shows only
 * member tracks that are currently listed and eligible in the public catalog, in staff order, each linking to its own track page.
 * A collection with no such track does not exist publicly. Nothing here carries a price, offer, license or bundle.
 */
final class CollectionPresentation
{
    /** The index never lists more than this many collections, which also bounds the sitemap. */
    public const LIMIT = 50;

    /** @return list<array<string, mixed>> published collections with at least one listed track, newest first */
    public function index(): array
    {
        $rows = [];
        foreach (TrackCollection::query()->where('is_published', true)->orderByDesc('id')->limit(self::LIMIT)->get() as $collection) {
            $tracks = $this->tracks($collection);
            if ($tracks !== []) {
                $rows[] = $this->summary($collection, count($tracks)) + ['preview' => array_slice(array_column($tracks, 'title'), 0, 3)];
            }
        }

        return $rows;
    }

    /** @return array<string, mixed>|null null (a 404) when unpublished, unknown or with no listed track */
    public function detail(string $slug): ?array
    {
        $collection = TrackCollection::query()->where('is_published', true)->where('slug', $slug)->first();
        if ($collection === null) {
            return null;
        }
        $tracks = $this->tracks($collection);

        return $tracks === [] ? null : $this->summary($collection, count($tracks)) + ['description' => $collection->description, 'tracks' => $tracks];
    }

    /** @return list<string> paths of every publicly visible collection, for the sitemap */
    public function paths(): array
    {
        return array_column($this->index(), 'path');
    }

    /**
     * @param  array<string, mixed>|null  $collection  a detail projection, or null for the index
     * @return array<string, mixed>
     */
    public function metadata(?array $collection): array
    {
        $image = ['path' => '/images/storefront-hero.jpg', 'width' => 2400, 'height' => 890, 'type' => 'image/jpeg'];
        if ($collection === null) {
            $title = 'Collections — VASEY.AUDIO';
            $description = 'Groups of beats from the catalog. Every track is licensed on its own page.';
            $path = '/collections';
        } else {
            $title = $this->text($collection['title'].' — VASEY.AUDIO', 160);
            $description = $this->text($collection['description'] ?? ('A collection of '.$collection['trackCount'].' '.($collection['trackCount'] === 1 ? 'track' : 'tracks').'. Every track is licensed on its own page.'), 300);
            $path = $collection['path'];
        }

        return [
            'title' => $title, 'description' => $description, 'canonicalUrl' => $this->absolute($path), 'imageUrl' => $this->absolute($image['path']),
            'imageAlt' => 'VASEY.AUDIO studio and audio production artwork', 'imageWidth' => $image['width'], 'imageHeight' => $image['height'],
            'imageType' => $image['type'], 'type' => 'website',
            'robots' => app()->environment('production') ? 'index, follow' : 'noindex, nofollow',
        ];
    }

    /** @return list<array<string, mixed>> listed member tracks in staff order */
    private function tracks(TrackCollection $collection): array
    {
        return app(PublicCatalog::class)->browseCards($collection->items()->pluck('track_id')->map(fn ($id): int => (int) $id)->all());
    }

    /** @return array<string, mixed> */
    private function summary(TrackCollection $collection, int $count): array
    {
        return ['slug' => $collection->slug, 'path' => '/collections/'.$collection->slug, 'title' => $collection->title, 'trackCount' => $count];
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
