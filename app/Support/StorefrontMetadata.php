<?php

namespace App\Support;

use App\Domain\SiteBuilder\SiteContentSchema;
use Illuminate\Support\Str;

/** Metadata accepts verified catalog or site-release projections, never request URLs. */
final class StorefrontMetadata
{
    private const BUILT_IN_SHARE = ['path' => '/images/storefront-hero.jpg', 'width' => 2400, 'height' => 890,
        'alt' => 'VASEY.AUDIO studio and audio production artwork', 'type' => 'image/jpeg'];

    /** @param  array{path: string, width: int, height: int, alt: string, type: string}|null  $share  the release's share image, if it sets one */
    public function forPage(?array $track, ?array $siteContent = null, ?array $share = null): array
    {
        $siteContent ??= SiteContentSchema::defaults();
        $title = $this->text($siteContent['seo']['title'], 120);
        $description = $this->text($siteContent['seo']['description'], 300);
        $path = route('home', [], false);
        $share ??= self::BUILT_IN_SHARE;
        $imagePath = $share['path'];
        $imageAlt = $this->text($share['alt'], 200);
        $imageSize = [$share['width'], $share['height'], $share['type']];

        if ($track !== null) {
            $name = $this->text($track['title'], 100);
            $artist = $this->text($track['artist'], 80);
            $title = $name.' by '.$artist.' — VASEY.AUDIO';
            $details = implode(' · ', array_filter([
                $this->text($track['genre'], 40),
                $track['bpm'].' BPM',
                $this->text($track['musicalKey'], 40),
            ]));
            $description = $this->text('Listen to '.$name.' by '.$artist.'. '.$details.'. Explore the available licenses on VASEY.AUDIO.', 200);
            $path = route('tracks.show', ['slug' => $track['slug']], false);
            // This URL was made by the public media route, never an operator-supplied object key.
            $imagePath = parse_url($track['artworkUrl'], PHP_URL_PATH);
            $imageAlt = $this->text('Cover artwork for '.$name.' by '.$artist, 200);
            // Artwork dimensions are not part of the public track projection, so none are claimed.
            $imageSize = [null, null, null];
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonicalUrl' => $this->absolute($path),
            'imageUrl' => $this->absolute($imagePath),
            'imageAlt' => $imageAlt,
            'imageWidth' => $imageSize[0], 'imageHeight' => $imageSize[1], 'imageType' => $imageSize[2],
            'type' => $track === null ? 'website' : 'music.song',
            'robots' => app()->environment('production') ? 'index, follow' : 'noindex, nofollow',
        ];
    }

    /** @param  array{path: string, width: int, height: int, alt: string, type: string}|null  $share  the release's share image, if it sets one */
    public function forEditorial(array $page, ?array $share = null): array
    {
        $share ??= self::BUILT_IN_SHARE;

        return [
            'title' => $this->text($page['title'].' — VASEY.AUDIO', 160),
            'description' => $this->text($page['description'], 300),
            'canonicalUrl' => $this->absolute($page['path']),
            'imageUrl' => $this->absolute($share['path']),
            'imageAlt' => $this->text($share['alt'], 200),
            'imageWidth' => $share['width'], 'imageHeight' => $share['height'], 'imageType' => $share['type'],
            'type' => $page['section'] === 'blog' && $page['kind'] === 'entry' ? 'article' : 'website',
            'robots' => app()->environment('production') ? 'index, follow' : 'noindex, nofollow',
        ];
    }

    private function absolute(string $path): string
    {
        // Pin sharing identity to the configured site URL; ignore request Host and tracking queries.
        return rtrim(config('app.url'), '/').'/'.ltrim($path, '/');
    }

    private function text(string $value, int $limit): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags($value))), $limit - 1, '…');
    }
}
