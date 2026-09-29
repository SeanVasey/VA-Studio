<?php

namespace App\Support;

use App\Domain\SiteBuilder\SiteContentSchema;
use Illuminate\Support\Str;

/** Metadata accepts verified catalog or site-release projections, never request URLs. */
final class StorefrontMetadata
{
    public function forPage(?array $track, ?array $siteContent = null): array
    {
        $siteContent ??= SiteContentSchema::defaults();
        $title = $this->text($siteContent['seo']['title'], 120);
        $description = $this->text($siteContent['seo']['description'], 300);
        $path = route('home', [], false);
        $imagePath = '/images/storefront-hero.jpg';
        $imageAlt = 'VASEY.AUDIO studio and audio production artwork';

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
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonicalUrl' => $this->absolute($path),
            'imageUrl' => $this->absolute($imagePath),
            'imageAlt' => $imageAlt,
            'type' => $track === null ? 'website' : 'music.song',
            'robots' => app()->environment('production') ? 'index, follow' : 'noindex, nofollow',
        ];
    }

    public function forEditorial(array $page): array
    {
        return [
            'title' => $this->text($page['title'].' — VASEY.AUDIO', 160),
            'description' => $this->text($page['description'], 300),
            'canonicalUrl' => $this->absolute($page['path']),
            'imageUrl' => $this->absolute('/images/storefront-hero.jpg'),
            'imageAlt' => 'VASEY.AUDIO studio and audio production artwork',
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
