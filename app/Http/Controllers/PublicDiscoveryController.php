<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\DiscoverySitemap\SitemapException;
use App\Domain\Catalog\DiscoverySitemap\SitemapStore;
use App\Domain\SiteBuilder\PublicPagesSitemap;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicDiscoveryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->admit($request);
        if (app()->environment('production') && config('discovery-sitemap.enabled') === true) {
            try {
                return $this->response(app(SitemapStore::class)->currentIndexXml(), 'application/xml; charset=UTF-8');
            } catch (SitemapException $error) {
                if (! in_array($error->reason, ['no_current_generation', 'expired_generation'], true)) {
                    return $this->response('', 'application/xml; charset=UTF-8', $error->status);
                }
                // The editorial family is independently available when no authentic track generation is current.
            } catch (\Throwable) {
                return $this->response('', 'application/xml; charset=UTF-8', 503);
            }
        }
        $origin = app(PublicPagesSitemap::class)->canonicalOrigin();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        if (app()->environment('production')) {
            $xml .= '<sitemap><loc>'.htmlspecialchars($origin.'/site-pages-sitemap.xml', ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></sitemap>';
        }

        return $this->response($xml.'</sitemapindex>'."\n", 'application/xml; charset=UTF-8');
    }

    public function robots(Request $request): Response
    {
        $this->admit($request);
        $origin = app(PublicPagesSitemap::class)->canonicalOrigin();
        $text = "User-agent: *\n";
        if (app()->environment('production')) {
            // Crawler advice complements server authorization; it grants no access.
            foreach (['/admin', '/account', '/orders', '/quotes', '/api', '/contact/inquiries', '/services/projects', '/customer', '/free-grants'] as $path) {
                $text .= 'Disallow: '.$path."\n";
            }
            $text .= 'Sitemap: '.$origin.'/sitemap.xml'."\n";
        } else {
            $text .= "Disallow: /\n";
        }

        return $this->response($text, 'text/plain; charset=UTF-8');
    }

    private function admit(Request $request): void
    {
        $stream = $request->getContent(true);
        abort_if(! is_resource($stream) || stream_get_contents($stream, 1) !== '' || $request->query() !== [], 404);
    }

    private function response(string $content, string $type, int $status = 200): Response
    {
        return new Response($content, $status, [
            'Content-Type' => $type,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => app()->environment('production') ? 'noindex, follow' : 'noindex, nofollow',
        ]);
    }
}
