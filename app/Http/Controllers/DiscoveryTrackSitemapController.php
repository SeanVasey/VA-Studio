<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Discovery\CurrentEligibleTrackSnapshot;
use App\Domain\Catalog\DiscoverySitemap\DiscoverySitemapConsumption;
use App\Domain\Catalog\DiscoverySitemap\SitemapConfiguration;
use App\Domain\Catalog\DiscoverySitemap\SitemapException;
use App\Domain\Catalog\DiscoverySitemap\SitemapStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DiscoveryTrackSitemapController
{
    public function __invoke(Request $request, string $generation, string $slot): Response
    {
        $headers = ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, follow'];
        if (! app()->environment('production')) {
            return new Response('', 404, $headers);
        }
        try {
            SitemapConfiguration::assertEnabled();
            SitemapException::require(in_array($request->method(), ['GET', 'HEAD'], true) && $request->query->all() === []
                && preg_match('/\A[a-f0-9]{32}\z/D', $generation) === 1 && preg_match('/\A(?:[1-9]|[1-9][0-9]|1[01][0-9]|12[0-8])\z/D', $slot) === 1, 'unavailable', 404);
            $stream = $request->getContent(true);
            SitemapException::require(is_resource($stream) && fgetc($stream) === false, 'unavailable', 404);
            $consumption = DiscoverySitemapConsumption::prepare(app(SitemapStore::class), $generation, (int) $slot);
            $xml = app(CurrentEligibleTrackSnapshot::class)->consumeIdentities($consumption);

            return new Response($xml, 200, $headers);
        } catch (SitemapException $error) {
            return new Response('', $error->status, $headers);
        } catch (\Throwable) {
            return new Response('', 503, $headers);
        }
    }
}
