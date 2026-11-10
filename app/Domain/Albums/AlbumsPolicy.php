<?php

namespace App\Domain\Albums;

/**
 * Visibility of the public albums family (/albums, its sitemap entries, structured data, navigation item and cover art). Only the
 * exact boolean true enables it; a string such as "false" or "1" in a cached config does not. Staff editing is not gated by this
 * flag, so releases can be prepared before the page is switched on.
 */
final class AlbumsPolicy
{
    public function enabled(): bool
    {
        return config('albums.enabled') === true;
    }
}
