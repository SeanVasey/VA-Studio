<?php

namespace App\Domain\Catalog;

/**
 * The five track states of SQ-15 and the only moves between them. The same table is enforced a second time by
 * the `tracks_visibility_*` database triggers, so a writer that bypasses the domain services still cannot reach
 * an undefined state or skip a step.
 *
 * Exposure follows the state, never the other way round:
 * - `published`: in the catalog, search, sitemaps and structured data, and purchasable.
 * - `unlisted`: served at its own URL, purchasable under the same guards, and absent from every listing.
 * - `draft`, `scheduled`, `private`: staff only; never served and never purchasable.
 */
final class TrackVisibility
{
    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const PUBLISHED = 'published';

    public const UNLISTED = 'unlisted';

    public const PRIVATE = 'private';

    public const STATES = [self::DRAFT, self::SCHEDULED, self::PUBLISHED, self::UNLISTED, self::PRIVATE];

    /** States whose page, preview and purchase path are served to the public. */
    public const LIVE = [self::PUBLISHED, self::UNLISTED];

    /** States a schedule may activate into. */
    public const TARGETS = [self::PUBLISHED, self::UNLISTED];

    /**
     * Allowed moves, from => to. Moves into a live state from a non-live one run every publication guard
     * (PublishTrack); moves out of exposure never do, because withdrawing a track must always be possible.
     */
    public const TRANSITIONS = [
        self::DRAFT => [self::PUBLISHED, self::UNLISTED, self::SCHEDULED, self::PRIVATE],
        self::SCHEDULED => [self::DRAFT, self::PUBLISHED, self::UNLISTED],
        self::PUBLISHED => [self::DRAFT, self::UNLISTED, self::PRIVATE],
        self::UNLISTED => [self::DRAFT, self::PUBLISHED, self::PRIVATE],
        self::PRIVATE => [self::DRAFT],
    ];

    public static function isLive(?string $status): bool
    {
        return in_array($status, self::LIVE, true);
    }

    public static function allows(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** SQL list of the live states for raw trigger and query text. */
    public static function liveSql(): string
    {
        return "'".implode("','", self::LIVE)."'";
    }
}
