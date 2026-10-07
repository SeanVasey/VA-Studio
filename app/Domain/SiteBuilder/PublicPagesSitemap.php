<?php

namespace App\Domain\SiteBuilder;

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
            $urls = array_map(fn (string $path): string => rtrim($origin, '/').$path, array_unique($paths));
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
