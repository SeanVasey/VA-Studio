<?php

namespace App\Domain\SiteBuilder;

use App\Domain\Albums\AlbumsPolicy;
use App\Domain\Catalog\PublicCatalog;
use App\Domain\Rights\PublishedLicenseComparison;

/** Route projections accept one already verified release, never a second pointer read. */
final class EditorialContent
{
    /**
     * @param  bool  $families  add the navigation items of default-off public families that are switched on (the albums page).
     *                          Staff previews pass false: a family page is not part of a saved site release.
     */
    public function chrome(array $verifiedContent, bool $families = true): array
    {
        $chrome = $this->baseChrome($verifiedContent);
        if ($families && app(AlbumsPolicy::class)->enabled() && ! in_array('/albums', array_column($chrome['navigation'], 'href'), true)) {
            $chrome['navigation'][] = ['label' => 'Albums', 'href' => '/albums'];
        }

        return $chrome;
    }

    private function baseChrome(array $verifiedContent): array
    {
        // Existing v1 props remain exact. Editorial bodies belong only to their selected route.
        if ($verifiedContent['schema_version'] === 1) {
            return $verifiedContent;
        }

        $chrome = array_intersect_key($verifiedContent, array_flip([
            'schema_version', 'hero', 'studio', 'footer', 'navigation', 'seo',
        ]));
        if (in_array($verifiedContent['schema_version'], SiteContentSchema::LEGAL_VERSIONS, true)) {
            // The footer lists published legal pages by title and path only; their text belongs to their own route.
            $chrome['legal_links'] = [];
            foreach (SiteContentSchema::LEGAL_DOCUMENTS as $document) {
                if ($this->listed($verifiedContent, $document)) {
                    $chrome['legal_links'][] = ['title' => $verifiedContent['legal'][$document]['title'], 'path' => '/legal/'.$document];
                }
            }
        }

        return $chrome;
    }

    /**
     * One published legal document, or null when the release predates legal pages or leaves the slot empty. The approval
     * reference is staff evidence and is deliberately not projected.
     *
     * The license comparison exists only in schema 6, generated from the published license versions (DD-31); with no such
     * version it is absent. A schema 5 comparison is hand-typed text that may differ from what is sold, so it is never
     * shown, and publication refuses it (LegalPublicationGuard). A staff preview shows the generated page even when it
     * is empty, so the release can be reviewed before any version is published.
     */
    public function legal(array $verifiedContent, string $document, bool $preview = false): ?array
    {
        $version = $verifiedContent['schema_version'] ?? null;
        if (! in_array($version, SiteContentSchema::LEGAL_VERSIONS, true)
            || ! in_array($document, SiteContentSchema::LEGAL_DOCUMENTS, true)
            || ! is_array($verifiedContent['legal'][$document] ?? null)) {
            return null;
        }
        $selected = $verifiedContent['legal'][$document];
        if ($document === 'licenses') {
            if ($version !== SiteContentSchema::GENERATED_LICENSES_VERSION) {
                return null;
            }
            $licenses = app(PublishedLicenseComparison::class)->versions();
            if ($licenses === [] && ! $preview) {
                return null;
            }

            return [
                'section' => 'legal', 'kind' => 'legal', 'path' => '/legal/licenses',
                'title' => $selected['title'], 'description' => $selected['description'],
                'paragraphs' => [], 'entries' => [], 'email' => null, 'contactHref' => null, 'video' => null,
                'legal' => ['document' => 'licenses', 'generated' => true, 'licenses' => $licenses],
            ];
        }

        return [
            'section' => 'legal', 'kind' => 'legal', 'path' => '/legal/'.$document,
            'title' => $selected['title'], 'description' => $selected['description'],
            'paragraphs' => [], 'entries' => [], 'email' => null, 'contactHref' => null, 'video' => null,
            'legal' => [
                'document' => $document, 'version' => $selected['version'], 'effectiveDate' => $selected['effective_date'],
                'sections' => array_map(fn (array $section): array => [
                    'heading' => $section['heading'], 'paragraphs' => $section['paragraphs'],
                ], $selected['sections']),
            ],
        ];
    }

    public function page(array $verifiedContent, string $section, ?string $slug = null, bool $includeRelatedHref = true, bool $preview = false): ?array
    {
        if ($section === 'legal') {
            return $slug === null ? null : $this->legal($verifiedContent, $slug, $preview);
        }
        if (! in_array($verifiedContent['schema_version'] ?? null, SiteContentSchema::EDITORIAL_VERSIONS, true)
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

        if ($entry !== null && in_array($verifiedContent['schema_version'], SiteContentSchema::RELATED_TRACK_VERSIONS, true)) {
            $page['relatedTracks'] = app(PublicCatalog::class)->relatedLinks($entry['related_track_ids'], $includeRelatedHref);
        }

        return $page;
    }

    /** Whether the footer links a legal page: the comparison only while its generated page would not be empty. */
    private function listed(array $verifiedContent, string $document): bool
    {
        if (! is_array($verifiedContent['legal'][$document] ?? null)) {
            return false;
        }

        return $document !== 'licenses' || ($verifiedContent['schema_version'] === SiteContentSchema::GENERATED_LICENSES_VERSION
            && app(PublishedLicenseComparison::class)->offered());
    }
}
