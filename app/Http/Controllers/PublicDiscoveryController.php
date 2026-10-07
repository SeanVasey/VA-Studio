<?php

namespace App\Http\Controllers;

use App\Domain\SiteBuilder\PublicPagesSitemap;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicDiscoveryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->admit($request);
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

    private function response(string $content, string $type): Response
    {
        return response($content, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => app()->environment('production') ? 'noindex, follow' : 'noindex, nofollow',
        ]);
    }
}
