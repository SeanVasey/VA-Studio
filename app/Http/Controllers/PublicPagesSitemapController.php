<?php

namespace App\Http\Controllers;

use App\Domain\SiteBuilder\PublicPagesSitemap;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicPagesSitemapController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $stream = $request->getContent(true);
        // Probe actual bytes with a fixed bound; Content-Length is caller-controlled.
        abort_if(! is_resource($stream) || stream_get_contents($stream, 1) !== '' || $request->query() !== [], 404);

        return response(app(PublicPagesSitemap::class)->xml(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => app()->environment('production') ? 'noindex, follow' : 'noindex, nofollow',
        ]);
    }
}
