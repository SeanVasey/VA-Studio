<?php

namespace App\Domain\Catalog\DiscoverySitemap;

/** Fixed pure serialization, completed before the last raw source/store/config/clock proof. */
final class SitemapProjection
{
    public static function urlset(string $origin, array $paths): string
    {
        SitemapException::require(array_is_list($paths) && count($paths) <= SitemapConfiguration::IDS && count(array_unique($paths)) === count($paths), 'invalid_paths');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($paths as $path) {
            SitemapException::require(is_string($path) && preg_match('/\A\/tracks\/[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $path) === 1 && strlen($origin.$path) < 2048, 'invalid_paths');
            $xml .= '<url><loc>'.htmlspecialchars($origin.$path, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
        }
        $xml .= '</urlset>'."\n";
        SitemapException::require(strlen($xml) <= SitemapConfiguration::XML_BYTES, 'xml_limit');

        return $xml;
    }
}
