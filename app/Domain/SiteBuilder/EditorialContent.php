<?php

namespace App\Domain\SiteBuilder;

use App\Domain\Catalog\PublicCatalog;

/** Route projections accept one already verified release, never a second pointer read. */
final class EditorialContent
{
    public function chrome(array $verifiedContent): array
    {
        // Existing v1 props remain exact. Editorial bodies belong only to their selected route.
        if ($verifiedContent['schema_version'] === 1) {
            return $verifiedContent;
        }

        return array_intersect_key($verifiedContent, array_flip([
            'schema_version', 'hero', 'studio', 'footer', 'navigation', 'seo',
        ]));
    }

    public function page(array $verifiedContent, string $section, ?string $slug = null, bool $includeRelatedHref = true): ?array
    {
        if (! in_array($verifiedContent['schema_version'] ?? null, [2, 3, 4], true)
            || ! in_array($section, ['about', 'contact', 'blog', 'videos'], true)
            || ($verifiedContent[$section] ?? null) === null) {
            return null;
        }
        $content = $verifiedContent[$section];
        $collection = in_array($section, ['blog', 'videos'], true);
        if (! $collection && $slug !== null) {
            return null;
        }
        $entry = null;
        if ($slug !== null) {
            foreach ($content['entries'] as $candidate) {
                if ($candidate['slug'] === $slug) {
                    $entry = $candidate;
                    break;
                }
            }
            if ($entry === null) {
                return null;
            }
        }
        $selected = $entry ?? $content;
        $page = [
            'section' => $section,
            'kind' => $collection ? ($entry === null ? 'collection' : 'entry') : 'page',
            'path' => '/'.$section.($slug === null ? '' : '/'.$slug),
            'title' => $selected['title'], 'description' => $selected['description'],
            'paragraphs' => $selected['paragraphs'] ?? [],
            'entries' => [], 'email' => null, 'contactHref' => null, 'video' => null,
        ];
        if ($collection && $entry === null) {
            $page['entries'] = array_map(fn (array $item): array => [
                'slug' => $item['slug'], 'title' => $item['title'], 'description' => $item['description'],
                'path' => '/'.$section.'/'.$item['slug'],
            ], $content['entries']);
        }
        if ($section === 'contact') {
            $page['email'] = $content['email'];
            // Encode even otherwise legal address punctuation: it must never become URI headers.
            $page['contactHref'] = 'mailto:'.rawurlencode($content['email']);
        }
        if ($section === 'videos' && $entry !== null) {
            $page['video'] = [
                'provider' => $entry['provider'], 'videoId' => $entry['video_id'],
                'watchUrl' => $entry['provider'] === 'youtube'
                    ? 'https://www.youtube.com/watch?v='.$entry['video_id']
                    : 'https://vimeo.com/'.$entry['video_id'],
            ];
        }

        if ($entry !== null && $verifiedContent['schema_version'] === 4) {
            $page['relatedTracks'] = app(PublicCatalog::class)->relatedLinks($entry['related_track_ids'], $includeRelatedHref);
        }

        return $page;
    }
}
