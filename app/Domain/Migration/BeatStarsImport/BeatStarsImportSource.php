<?php

namespace App\Domain\Migration\BeatStarsImport;

use App\Domain\Migration\BeatStars\BeatStarsExportNormalizer;
use App\Domain\Migration\BeatStars\BeatStarsExportSheet;
use App\Domain\Migration\CatalogOnboarding\PrivateSourceFiles;
use InvalidArgumentException;

/**
 * The staged private snapshot (`catalog.json` plus `raw/<export>`, produced by the dry-run tool) together with the file
 * paths its export lists for each track. The snapshot is metadata only; the media columns are read from the export
 * artifact that `PrivateSourceFiles` has already hashed, so a listing can never come from a file the snapshot does not bind.
 */
final class BeatStarsImportSource
{
    public const ID_COLUMN = 'Track ID';

    /** Role => export header. The preview is read only to say whether BeatStars listed one; the pipeline generates the tagged preview. */
    public const MEDIA_COLUMNS = [
        'master_wav' => 'Master WAV Path',
        'stems_zip' => 'Stems ZIP Path',
        'preview_mp3' => 'Preview MP3 Path',
        'artwork' => 'Artwork Path',
        'artwork_high_res' => 'High-Res Local Artwork Path',
    ];

    /** @return array{source: array, listings: array<string, array<string, string>>} source_id => role => the cell as exported */
    public static function load(string $directory): array
    {
        try {
            $source = (new PrivateSourceFiles)->snapshot($directory);
        } catch (InvalidArgumentException) {
            throw new BeatStarsImportRefusal('The source directory is not a valid private snapshot: a caller-owned 0700 directory holding catalog.json and the hashed raw export, each file 0600 with one link.');
        }
        $artifact = null;
        foreach ($source['snapshot']['artifacts'] as $candidate) {
            if ($candidate['artifact_id'] === BeatStarsExportNormalizer::ARTIFACT_ID) {
                $artifact = $candidate;
            }
        }
        if ($artifact === null) {
            throw new BeatStarsImportRefusal('The snapshot does not bind a BeatStars export artifact (dry-run output is required).');
        }
        $bytes = @file_get_contents($directory.'/'.$artifact['relative_path']);
        if (! is_string($bytes) || strlen($bytes) !== $artifact['bytes'] || ! hash_equals($artifact['sha256'], hash('sha256', $bytes))) {
            throw new BeatStarsImportRefusal('The raw export no longer matches the hash the snapshot binds.');
        }
        try {
            $sheet = (new BeatStarsExportSheet)->read($bytes);
        } catch (InvalidArgumentException) {
            throw new BeatStarsImportRefusal('The raw export could not be read as a BeatStars sheet.');
        }
        $index = array_flip($sheet['headers']);
        foreach ([self::ID_COLUMN, ...array_values(self::MEDIA_COLUMNS)] as $header) {
            if (! isset($index[$header])) {
                throw new BeatStarsImportRefusal('The export lacks a required column: '.$header.'.');
            }
        }
        $listings = [];
        foreach ($sheet['records'] as $record) {
            if ($record === null) {
                continue;
            }
            if (count($record) !== count($sheet['headers']) || isset($listings[$record[$index[self::ID_COLUMN]]])) {
                throw new BeatStarsImportRefusal('The export has a row that cannot be aligned or a repeated track identifier.');
            }
            $listing = [];
            foreach (self::MEDIA_COLUMNS as $role => $header) {
                $listing[$role] = $record[$index[$header]];
            }
            $listings[$record[$index[self::ID_COLUMN]]] = $listing;
        }
        $ids = array_column($source['snapshot']['records'], 'source_id');
        // PHP turns a numeric-string key into an integer, so identifiers are compared as the strings the export holds.
        $listed = array_map('strval', array_keys($listings));
        sort($ids, SORT_STRING);
        sort($listed, SORT_STRING);
        if ($ids !== $listed) {
            throw new BeatStarsImportRefusal('The snapshot records and the raw export rows name different tracks.');
        }

        return ['source' => $source, 'listings' => $listings];
    }
}
