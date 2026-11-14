<?php

namespace App\Domain\Migration\BeatStarsImport;

use App\Domain\Catalog\Models\Track;
use App\Domain\Migration\CatalogOnboarding\CatalogDatabaseEvidence;
use App\Domain\Migration\CatalogOnboarding\CatalogDraftImporter;
use App\Domain\Migration\CatalogOnboarding\Models\CatalogImportMapping;
use App\Domain\Migration\CatalogOnboarding\NormalizedSourceSnapshot;
use App\Models\User;
use Closure;

/**
 * The two phases of the BeatStars import, in the order they must run.
 *
 * Drafts: the reviewed draft importer (`CatalogDraftImporter::review()` then `apply()`) creates every unmapped record as an
 * unpublished metadata draft and retains its source id, record hash and `catalog_import_mappings` row. It refuses a mapped
 * track that has gained any media, rights or offer row, so every record is imported before the first file is attached; once
 * all are mapped it is not called again and a re-run only continues the media phase.
 *
 * Media: for each mapped draft in source-id order, `BeatStarsTrackMedia` attaches what the export lists. Prices, offers,
 * licenses, rights declarations and publication are not touched.
 */
final class BeatStarsCatalogImport
{
    /**
     * Every record of the snapshot in source-id order with its mapping, if any.
     *
     * @return list<array{source_id: string, record_key: string, record_sha256: string, track_id: ?int, title: string}>
     */
    public function rows(array $source): array
    {
        $snapshot = new NormalizedSourceSnapshot;
        $rows = [];
        foreach ($source['snapshot']['records'] as $record) {
            $rows[] = ['source_id' => $record['source_id'], 'record_key' => $snapshot->recordKey($source['snapshot']['source_system'], $record['source_id']),
                'record_sha256' => $record['source_record_sha256'], 'track_id' => null, 'title' => $record['metadata']['title']];
        }
        usort($rows, static fn (array $left, array $right): int => strcmp($left['source_id'], $right['source_id']));
        $mapped = CatalogImportMapping::query()->whereIn('record_key', array_column($rows, 'record_key'))
            ->get(['record_key', 'track_id', 'source_record_sha256'])->keyBy('record_key');
        foreach ($rows as &$row) {
            $mapping = $mapped->get($row['record_key']);
            if ($mapping !== null) {
                if ($mapping->source_record_sha256 !== $row['record_sha256']) {
                    throw new BeatStarsImportRefusal('A draft was already imported from different content for the same record. Review the source before running again.');
                }
                $row['track_id'] = (int) $mapping->track_id;
            }
        }

        return $rows;
    }

    /**
     * The draft importer's read-only review of the unmapped records, or null when every record is already mapped (the
     * importer would then flag mapped tracks that gained media as changed, so it is not asked).
     */
    public function review(array $source, array $release, User $operator): ?array
    {
        if (array_all($this->rows($source), static fn (array $row): bool => $row['track_id'] !== null)) {
            return null;
        }

        return (new CatalogDraftImporter)->review($source, $release, $operator);
    }

    /** Counts per conflict reason of a review, without any record value. */
    public function conflicts(array $review): array
    {
        $reasons = [];
        foreach ($review['entries'] as $entry) {
            foreach ($entry['reasons'] as $reason) {
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }
        ksort($reasons);

        return $reasons;
    }

    /**
     * Creates every unmapped record as a draft through the reviewed importer, in segments of its maximum size.
     *
     * @return array{created: int, already_mapped: int}
     */
    public function drafts(array $source, array $release, User $operator): array
    {
        $mappedBefore = count(array_filter($this->rows($source), static fn (array $row): bool => $row['track_id'] !== null));
        $review = $this->review($source, $release, $operator);
        if ($review === null) {
            return ['created' => 0, 'already_mapped' => $mappedBefore];
        }
        if (($review['counts']['conflict'] ?? 0) > 0) {
            throw new BeatStarsImportRefusal('The draft review found conflicts and nothing was applied: '.$this->reasons($this->conflicts($review)).'.');
        }
        $importer = new CatalogDraftImporter;
        $created = 0;
        $result = ['complete' => false, 'processed' => 0];
        for ($segment = 0; $segment <= $review['total'] && ! $result['complete']; $segment++) {
            $previous = $result['processed'];
            $result = $importer->apply($source, $release, $review, $review['review_sha256'], CatalogDraftImporter::MAX_SEGMENT, $operator);
            $created += count($result['created_track_ids']);
            if (! $result['complete'] && $result['processed'] <= $previous) {
                throw new BeatStarsImportRefusal('The draft import made no progress and stopped. Re-run the command to resume.');
            }
        }
        if (! $result['complete']) {
            throw new BeatStarsImportRefusal('The draft import did not complete. Re-run the command to resume.');
        }

        return ['created' => $created, 'already_mapped' => $mappedBefore];
    }

    /**
     * Attaches media to up to $limit drafts that still need a file, in source-id order from $from. A draft whose listed files are
     * all missing or refused costs only file checks and does not use the limit, so they never starve the drafts behind them.
     *
     * @param  array<string, array<string, string>>  $listings
     * @param  array<string, array>  $outcomes  filled by reference with the outcome of every draft visited (source_id => outcome), so a
     *                                          stop part-way still leaves what was done for the report
     * @param  ?Closure(string, array): void  $progress  receives the draft's internal id and its outcome
     */
    public function media(array $source, array $listings, BeatStarsTrackMedia $media, User $operator, ?string $from, int $limit, array &$outcomes, ?Closure $progress = null): void
    {
        $worked = 0;
        foreach ($this->rows($source) as $row) {
            if ($worked >= $limit) {
                break;
            }
            if ($row['track_id'] === null || ($from !== null && strcmp($row['source_id'], $from) < 0)) {
                continue;
            }
            $track = Track::query()->find($row['track_id']);
            if ($track === null || $track->status !== 'draft') {
                $outcomes[$row['source_id']] = ['attempted' => false, 'roles' => [], 'stems_association' => 'not_applicable', 'note' => 'not_a_draft'];

                continue;
            }
            $outcome = $media->attach($track, $listings[$row['source_id']] ?? [], $operator);
            $outcomes[$row['source_id']] = $outcome;
            $worked += $outcome['attempted'] ? 1 : 0;
            $progress?->__invoke((string) $row['track_id'], $outcome);
        }
    }

    /** The release identity the draft importer binds: source commit and tree, and the live schema proof. */
    public function release(string $commit, string $tree): array
    {
        return ['commit' => $commit, 'tree' => $tree, 'schema_hash' => (new CatalogDatabaseEvidence)->schema()];
    }

    private function reasons(array $reasons): string
    {
        return implode(', ', array_map(static fn (string $reason, int $count): string => $reason.' x'.$count, array_keys($reasons), $reasons));
    }
}
