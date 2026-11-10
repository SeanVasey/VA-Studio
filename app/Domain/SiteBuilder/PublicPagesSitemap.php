<?php

namespace App\Domain\SiteBuilder;

use App\Domain\Albums\AlbumPresentation;
use App\Domain\Albums\AlbumsPolicy;
use App\Domain\Collections\CollectionPresentation;
use App\Domain\Collections\CollectionsPolicy;

/** Public URL discovery from one verified current release; never private preview content. */
final class PublicPagesSitemap
{
    public function xml(): string
    {
        $origin = $this->canonicalOrigin();
        $urls = [];
        if (app()->environment('production')) {
            $content = app(SiteContent::class)->current();
            $editorial = app(EditorialContent::class);
            $paths = ['/'];
            foreach (['about', 'contact', 'blog', 'videos'] as $section) {
                $page = $editorial->page($content, $section, null, false);
                if ($page === null) {
                    continue;
                }
                $paths[] = $page['path'];
                foreach ($page['entries'] as $entry) {
                    $paths[] = $entry['path'];
                }
            }
            // Verified editorial schema permits at most thirty entries per collection.
            abort_if(count($paths) > 65, 503);
            // Published releases only while the albums page is on (BL-67); bounded separately from the editorial schema.
            if (app(AlbumsPolicy::class)->enabled()) {
                $albums = app(AlbumPresentation::class)->paths();
                $paths = [...$paths, ...($albums === [] ? [] : ['/albums', ...$albums])];
            }
            // Published collections that still have a listed track, only while the collections pages are on (BL-54).
            if (app(CollectionsPolicy::class)->enabled()) {
                $collections = app(CollectionPresentation::class)->paths();
                $paths = [...$paths, ...($collections === [] ? [] : ['/collections', ...$collections])];
            }
            // Fail closed on a path into a product family whose own policy is off (FP-001, BL-50).
            $families = app(FamilyEntryPoints::class);
            $paths = array_filter(array_unique($paths), fn (string $path): bool => $families->admits($path));
            $urls = array_map(fn (string $path): string => rtrim($origin, '/').$path, array_values($paths));
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $xml .= '<url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
        }

        return $xml.'</urlset>'."\n";
    }

    public function canonicalOrigin(): string
    {
        $origin = config('app.url');
        $parts = is_string($origin) ? parse_url($origin) : false;
        abort_unless(is_array($parts) && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && isset($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && preg_match('/^[a-zA-Z0-9.-]+$/D', $parts['host']) === 1
            && filter_var($origin, FILTER_VALIDATE_URL) !== false, 503);

        return rtrim($origin, '/');
    }
}
