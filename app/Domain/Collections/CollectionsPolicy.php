<?php

namespace App\Domain\Collections;

/**
 * Visibility of the public collections family (/collections, its sitemap entries and navigation item). Only the exact boolean
 * true enables it; a string such as "false" or "1" in a cached config does not. Staff editing is not gated by this flag, so
 * collections can be prepared before the pages are switched on. Publication of the member tracks is a separate, stronger gate.
 */
final class CollectionsPolicy
{
    public function enabled(): bool
    {
        return config('collections.enabled') === true;
    }
}
