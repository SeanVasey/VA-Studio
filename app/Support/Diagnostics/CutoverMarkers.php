<?php

namespace App\Support\Diagnostics;

use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalCatalog;
use App\Domain\Media\StagingSellerTag;

/**
 * Every marker the cutover check looks for, in one place, so a convention that changes is aligned by editing one
 * constant. The conventions themselves are set elsewhere: the production cleanup ledger
 * (docs/production-cleanup-ledger.md, "Rules that hold for every entry"), the synthetic rehearsal catalog and the
 * staging placeholder record (docs/ops/staging-placeholders.md). Nothing here creates, fills or removes a marker.
 */
final class CutoverMarkers
{
    /**
     * The synthetic STAGING seller tag marker, as `vasey:staging-seller-tag` writes it into the WAV's RIFF `INFO` comment
     * (docs/ops/staging-placeholders.md, P-1). It is that generator's own constant, so the two cannot drift.
     */
    public const STAGING_SELLER_TAG_MARKER = StagingSellerTag::MARKER;

    /** A tag path that names a staging or synthetic file, in any letter case (the same rule the tag guard applies). */
    public const STAGING_SELLER_TAG_PATH_WORDS = ['staging', 'synthetic'];

    /** File-name stem of every staging stand-in on the private disk: `STAGING-synthetic-…` (ledger rules). */
    public const STAGING_FILE_STEM = 'STAGING-synthetic';

    /** The specimen signature reads "STAGING SPECIMEN — NOT A SIGNATURE" (ledger PCL-06). */
    public const SPECIMEN_SIGNATURE_MARKER = 'STAGING SPECIMEN';

    public const SPECIMEN_SIGNATURE_PATH_WORDS = ['staging', 'specimen'];

    public const SYNTHETIC_TITLE = SyntheticRehearsalCatalog::TITLE_PREFIX;

    public const SYNTHETIC_SLUG_PREFIX = SyntheticRehearsalCatalog::MARKER;

    public const SYNTHETIC_ARTIST = SyntheticRehearsalCatalog::ARTIST;

    public const SYNTHETIC_REFERENCE_PREFIX = SyntheticRehearsalCatalog::PROVENANCE_PREFIX;

    public const SYNTHETIC_STAFF_NAME_PREFIX = SyntheticRehearsalCatalog::OPERATOR_NAME_PREFIX;

    /** Synthetic licenses are named `[SYNTHETIC] TEST PRICE …` (ledger PCL-02). */
    public const TEST_PRICE = 'TEST PRICE';

    /**
     * Staging free-license text and legal pages open with "STAGING PLACEHOLDER" (ledger PCL-05, PCL-07), and the ledger
     * asks that the published pages hold no "STAGING" at all. Upper case only: the marker, not the ordinary word.
     */
    public const STAGING_TEXT = 'STAGING';

    /** Staging legal pages read "set at production" where a production value is still missing (ledger PCL-07). */
    public const SET_AT_PRODUCTION = 'set at production';

    /** The staging nginx basic-auth file (ops/staging/nginx/vasey-staging.conf); the production kit has none. */
    public const STAGING_BASIC_AUTH_FILE = '/etc/nginx/vasey-staging.htpasswd';

    /** @param  list<string>  $words */
    public static function pathCarriesWord(mixed $path, array $words): bool
    {
        if (! is_string($path)) {
            return false;
        }
        foreach ($words as $word) {
            if (stripos($path, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Free-license and legal text: the staging placeholder banner, the synthetic title prefix or "set at production". */
    public static function textCarriesStagingMarker(string $text): bool
    {
        return str_contains($text, self::STAGING_TEXT) || stripos($text, self::SYNTHETIC_TITLE) !== false
            || stripos($text, self::SET_AT_PRODUCTION) !== false;
    }

    /**
     * True when the file's bytes hold the marker anywhere. An unreadable file reads as marked, so a production check
     * fails closed. The file is opened read-only and nothing of it is kept or reported.
     */
    public static function fileCarriesMarker(string $file, string $marker): bool
    {
        $stream = @fopen($file, 'rb');
        if ($stream === false) {
            return true;
        }
        try {
            $tail = '';
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    return true;
                }
                if (str_contains($tail.$chunk, $marker)) {
                    return true;
                }
                // Keep enough of the end to find a marker that straddles two reads.
                $tail = substr($tail.$chunk, -(strlen($marker) - 1));
            }
        } finally {
            fclose($stream);
        }

        return false;
    }
}
