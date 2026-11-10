<?php

namespace App\Domain\Albums;

use App\Domain\Albums\Models\AlbumRelease;
use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\Models\SiteImageVariant;
use Illuminate\Support\Collection;

/**
 * Public page props and page metadata for published releases. Callers check AlbumsPolicy first; this class only ever reads
 * published releases, and it shows cover art only while the image is still the exact version that was reviewed at publication.
 */
final class AlbumPresentation
{
    /** The index never lists more than this many releases, which also bounds the sitemap. */
    public const LIMIT = 200;

    /** @return list<array<string, mixed>> published releases, newest first */
    public function index(): array
    {
        $releases = AlbumRelease::query()->where('is_published', true)->withCount('tracks')
            ->orderByDesc('release_date')->orderByDesc('id')->limit(self::LIMIT)->get();
        $images = $this->images($releases);

        return $releases->map(fn (AlbumRelease $release): array => $this->summary($release, $images->get($release->artwork_image_id)))->all();
    }

    /** @return array<string, mixed>|null */
    public function detail(string $slug): ?array
    {
        $release = AlbumRelease::query()->where('is_published', true)->where('slug', $slug)->with('tracks')->first();
        if ($release === null) {
            return null;
        }
        $summary = $this->summary($release, $this->images(collect([$release]))->get($release->artwork_image_id));

        return $summary + [
            'description' => $release->description,
            'tracks' => $release->tracks->map(fn ($track): array => [
                'position' => $track->position, 'title' => $track->title, 'durationSeconds' => $track->duration_seconds,
                'duration' => self::clock($track->duration_seconds),
            ])->all(),
            'links' => array_map(fn (array $link): array => [
                'platform' => $link['platform'], 'label' => ListeningPlatforms::label($link['platform']), 'url' => $link['url'],
            ], $release->listening_links ?? []),
        ];
    }

    /** @return list<string> paths of every published release, for the sitemap */
    public function paths(): array
    {
        return AlbumRelease::query()->where('is_published', true)->orderByDesc('release_date')->orderByDesc('id')->limit(self::LIMIT)->pluck('slug')
            ->map(fn (string $slug): string => '/albums/'.$slug)->all();
    }

    /**
     * @param  array<string, mixed>|null  $album  a detail projection, or null for the index
     * @return array<string, mixed>
     */
    public function metadata(?array $album): array
    {
        $share = $album['cover']['share'] ?? null;
        $fallback = ['path' => '/images/storefront-hero.jpg', 'width' => 2400, 'height' => 890, 'type' => 'image/jpeg'];
        $image = $share ?? $fallback;
        if ($album === null) {
            $title = 'Albums — VASEY.AUDIO';
            $description = 'Albums, EPs and singles released by Sean Vasey, with tracklists and links to listen.';
            $path = '/albums';
            $imageAlt = 'VASEY.AUDIO studio and audio production artwork';
        } else {
            $title = $this->text($album['title'].' — '.$album['artist'].' — VASEY.AUDIO', 160);
            $description = $this->text($album['description'] ?? ($album['typeLabel'].' by '.$album['artist'].', released '.$album['releaseDate'].'.'), 300);
            $path = $album['path'];
            $imageAlt = $album['cover']['alt'] ?? 'VASEY.AUDIO studio and audio production artwork';
        }

        return [
            'title' => $title, 'description' => $description, 'canonicalUrl' => $this->absolute($path), 'imageUrl' => $this->absolute($image['path']),
            'imageAlt' => $this->text($imageAlt, 200), 'imageWidth' => $image['width'] ?? null, 'imageHeight' => $image['height'] ?? null,
            'imageType' => $image['type'] ?? null, 'type' => $album === null ? 'website' : 'music.album',
            'robots' => app()->environment('production') ? 'index, follow' : 'noindex, nofollow',
        ];
    }

    public static function clock(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);

        return $hours > 0 ? sprintf('%d:%02d:%02d', $hours, intdiv($seconds % 3600, 60), $seconds % 60) : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /** @return array<string, mixed> */
    private function summary(AlbumRelease $release, ?SiteImage $image): array
    {
        return [
            'slug' => $release->slug, 'path' => '/albums/'.$release->slug, 'title' => $release->title, 'artist' => $release->artist_name,
            'type' => $release->release_type->value, 'typeLabel' => $release->release_type->label(),
            'releaseDate' => $release->release_date->format('Y-m-d'), 'trackCount' => $release->tracks_count ?? $release->tracks()->count(),
            'cover' => $this->cover($release, $image),
        ];
    }

    /** @return array<string, mixed>|null */
    private function cover(AlbumRelease $release, ?SiteImage $image): ?array
    {
        if ($image === null || $image->slot !== AlbumReleases::SLOT || $image->status !== 'ready' || $release->artwork_alt === null
            || ! is_string($release->artwork_manifest_sha256) || ! hash_equals((string) $image->manifest_sha256, $release->artwork_manifest_sha256)) {
            return null;
        }
        $set = ['jpeg' => [], 'webp' => []];
        foreach ($image->variants->sortBy('width') as $variant) {
            $set[$variant->format][] = ['url' => $this->url($variant), 'width' => $variant->width, 'height' => $variant->height];
        }
        $jpeg = $image->variants->where('format', 'jpeg')->sortBy('width');
        $largest = $jpeg->last();
        if ($largest === null) {
            return null;
        }

        return [
            'alt' => $release->artwork_alt, 'width' => $largest->width, 'height' => $largest->height, 'jpeg' => $set['jpeg'], 'webp' => $set['webp'],
            'share' => ['path' => $this->url($largest), 'width' => $largest->width, 'height' => $largest->height, 'type' => 'image/jpeg'],
        ];
    }

    private function url(SiteImageVariant $variant): string
    {
        return route('site-images.show', ['file' => $variant->sha256.($variant->format === 'webp' ? '.webp' : '.jpg')], false);
    }

    /** @return Collection<int, SiteImage> */
    private function images(Collection $releases): Collection
    {
        $ids = $releases->pluck('artwork_image_id')->filter()->unique()->values()->all();

        return $ids === [] ? new Collection : SiteImage::query()->with('variants')->whereKey($ids)->get()->keyBy('id');
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
