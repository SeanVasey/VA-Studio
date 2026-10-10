<?php

declare(strict_types=1);

namespace App\Domain\Migration\BeatStars;

use App\Domain\Migration\CatalogOnboarding\NormalizedSourceSnapshot;
use App\Support\CanonicalJson;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Mapping-driven normalizer from a BeatStars CSV export to `vasey-private-catalog-drafts-v1`.
 *
 * Pure: no database, no filesystem, no clock. The result is fully determined by the export
 * bytes, the export basename and the mapping bytes, so a report built from it is reproducible.
 * Anything the mapping does not declare, and any row value that cannot be interpreted exactly,
 * becomes a finding; nothing is defaulted. The draft schema is metadata-only, so prices,
 * license names and rights references are validated and retained as evidence in the result
 * but never written into the snapshot and never turned into offers, licenses or rights.
 */
final class BeatStarsExportNormalizer
{
    public const TRANSFORM = 'vasey-beatstars-export-normalizer-v1';

    public const MODE = 'beatstars-export-dry-run';

    public const ARTIFACT_ID = 'beatstars-export';

    public const MAX_EXPORT_BYTES = 8388608;

    /** The drafts-v1 decoder's cap on snapshot bytes (`NormalizedSourceSnapshot::decode`); a larger snapshot is a sheet finding. */
    public const MAX_SNAPSHOT_BYTES = 1048576;

    /** Row findings that withhold only their own row. Sheet findings withhold every row. */
    public const ROW_CODES = ['column_count_mismatch', 'missing_source_id', 'invalid_source_id', 'duplicate_source_id',
        'duplicate_row_content', 'missing_title', 'invalid_title', 'synthetic_title_required', 'missing_slug', 'invalid_slug',
        'slug_underivable', 'duplicate_slug', 'missing_artist', 'invalid_artist', 'invalid_bpm', 'invalid_musical_key',
        'invalid_genre', 'invalid_mood', 'invalid_tags', 'invalid_description', 'missing_visibility', 'unknown_visibility_value',
        'missing_rights_reference', 'invalid_rights_reference', 'currency_mismatch', 'missing_license_name',
        'unknown_license_name', 'missing_price', 'non_numeric_price'];

    public const SHEET_CODES = ['unmapped_column', 'mapped_column_missing', 'blank_record', 'snapshot_too_large'];

    /**
     * Normalize one export. Throws InvalidArgumentException with a `mapping_*` or `export_*`
     * reason code when the inputs cannot be interpreted at all; otherwise every problem is a
     * finding in the returned result.
     */
    public function normalize(string $exportBytes, string $exportName, string $mappingBytes): array
    {
        $this->require(preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,119}\z/D', $exportName) === 1
            && ! in_array($exportName, ['.', '..'], true), 'export_name');
        $this->require(strlen($exportBytes) <= self::MAX_EXPORT_BYTES, 'export_too_large');
        $mapping = (new BeatStarsExportMapping)->decode($mappingBytes);
        $sheet = (new BeatStarsExportSheet)->read($exportBytes);
        $headers = $sheet['headers'];
        $index = array_flip($headers);

        $findings = [];
        $declared = [...array_values($mapping['columns']), ...$mapping['ignored_columns']];
        foreach ($mapping['offers'] as $offer) {
            $declared[] = $offer['price_column'];
            if ($offer['license_column'] !== null) {
                $declared[] = $offer['license_column'];
            }
        }
        foreach ($headers as $header) {
            if (! in_array($header, $declared, true)) {
                $findings[] = $this->finding('sheet', null, null, 'unmapped_column', $header, null);
            }
        }
        foreach ($declared as $header) {
            if (! isset($index[$header])) {
                $findings[] = $this->finding('sheet', null, null, 'mapped_column_missing', $header, null);
            }
        }
        $columnsPresent = array_all($declared, static fn (string $header): bool => isset($index[$header]));

        $entries = [];
        foreach ($sheet['records'] as $position => $record) {
            $row = $position + 1;
            if ($record === null) {
                $findings[] = $this->finding('sheet', $row, null, 'blank_record', null, null);

                continue;
            }
            if (count($record) !== count($headers)) {
                $findings[] = $this->finding('row', $row, null, 'column_count_mismatch', null,
                    sprintf('expected %d cells, found %d', count($headers), count($record)));
                $entries[] = $this->entry($row, null, CanonicalJson::hash($record), null);

                continue;
            }
            $cells = array_combine($headers, $record);
            $sourceColumn = $mapping['columns']['source_id'];
            $content = $cells;
            unset($content[$sourceColumn]);
            $sourceId = isset($cells[$sourceColumn]) ? trim($cells[$sourceColumn]) : null;
            if ($sourceId === '') {
                $sourceId = null;
            }
            if (! $columnsPresent) {
                // Without every declared column the row cannot be interpreted; the sheet findings already say why.
                $entries[] = $this->entry($row, $sourceId, CanonicalJson::hash($content), null);

                continue;
            }
            $entries[] = $this->interpret($row, $cells, $content, $mapping, $findings);
        }

        $this->duplicates($entries, $findings);
        $snapshot = null;
        if ($findings === []) {
            // Clean rows can still add up to more than the decoder admits; that is the operator's sheet, so it is a finding.
            $snapshot = $this->snapshot($mapping, $exportBytes, $exportName, $entries);
            $bytes = strlen(CanonicalJson::encode($snapshot)."\n");
            if ($bytes > self::MAX_SNAPSHOT_BYTES) {
                $findings[] = $this->finding('sheet', null, null, 'snapshot_too_large', null,
                    sprintf('%d bytes, maximum %d', $bytes, self::MAX_SNAPSHOT_BYTES));
                $snapshot = null;
            } else {
                $this->admitted($snapshot);
            }
        }
        $findings = $this->sort($findings);
        $sheetFindings = array_values(array_filter($findings, static fn (array $finding): bool => $finding['scope'] === 'sheet'));
        $withheldRows = [];
        foreach ($findings as $finding) {
            if ($finding['scope'] === 'row') {
                $withheldRows[$finding['row']][] = $finding['code'];
            }
        }
        $normalized = 0;
        foreach ($entries as &$entry) {
            $codes = array_values(array_unique($withheldRows[$entry['row']] ?? []));
            sort($codes, SORT_STRING);
            $entry['findings'] = $codes;
            if ($codes !== [] || $sheetFindings !== []) {
                $entry['disposition'] = 'withheld';
                $entry['record_key'] = $entry['source_record_sha256'] = $entry['visibility'] = $entry['metadata'] = null;
                $entry['raw_metadata'] = $entry['offers'] = $entry['rights_reference'] = null;
            } else {
                $entry['disposition'] = 'normalized';
                $normalized++;
            }
        }
        unset($entry);

        $snapshotBytes = $snapshot === null ? null : CanonicalJson::encode($snapshot)."\n";

        return ['schema_version' => 1, 'mode' => self::MODE, 'transform_version' => self::TRANSFORM,
            'canonicalization_version' => CanonicalJson::VERSION, 'draft_schema' => NormalizedSourceSnapshot::SCHEMA,
            'applied' => false, 'database_writes' => 0,
            'export' => ['name' => $exportName, 'sha256' => hash('sha256', $exportBytes), 'bytes' => strlen($exportBytes),
                'columns' => $headers, 'records' => count($sheet['records'])],
            'mapping' => ['sha256' => hash('sha256', $mappingBytes)] + $mapping,
            'counts' => ['rows' => count($entries), 'normalized' => $normalized, 'withheld' => count($entries) - $normalized,
                'findings' => count($findings), 'sheet_findings' => count($sheetFindings),
                'row_findings' => count($findings) - count($sheetFindings)],
            'findings' => $findings, 'entries' => $entries,
            'snapshot' => $snapshot, 'snapshot_sha256' => $snapshotBytes === null ? null : hash('sha256', $snapshotBytes),
            'statements' => ['applies' => 'nothing', 'marketing_consent' => 'not_inferred',
                'rights' => 'operator_references_only_not_clearance', 'prices' => 'retained_evidence_not_offers',
                'licenses' => 'operator_labels_not_published_licenses']];
    }

    /** Interpret one keyed row. Every problem is appended to $findings; the entry keeps whatever was exact. */
    private function interpret(int $row, array $cells, array $content, array $mapping, array &$findings): array
    {
        $columns = $mapping['columns'];
        $sourceId = trim($cells[$columns['source_id']]);
        if ($sourceId === '') {
            $findings[] = $this->finding('row', $row, null, 'missing_source_id', $columns['source_id'], null);
            $sourceId = null;
        } elseif (! CellText::identity($sourceId)) {
            $findings[] = $this->finding('row', $row, null, 'invalid_source_id', $columns['source_id'], null);
            $sourceId = null;
        }
        $hash = CanonicalJson::hash($content);
        $metadata = [];
        $raw = [];

        $title = trim($cells[$columns['title']]);
        if ($title === '') {
            $findings[] = $this->finding('row', $row, $sourceId, 'missing_title', $columns['title'], null);
            $title = null;
        } elseif (! CellText::line($title, 255)) {
            $findings[] = $this->finding('row', $row, $sourceId, 'invalid_title', $columns['title'], $this->bounds($title, 255));
            $title = null;
        } elseif ($mapping['acquisition_method'] === 'synthetic_fixture' && ! str_starts_with($title, 'SYNTHETIC ')) {
            $findings[] = $this->finding('row', $row, $sourceId, 'synthetic_title_required', $columns['title'], null);
            $title = null;
        }
        $metadata['title'] = $title;
        $raw['title'] = $cells[$columns['title']];

        if ($mapping['slug_policy'] === 'column') {
            $slug = trim($cells[$columns['slug']]);
            $raw['slug'] = $cells[$columns['slug']];
            if ($slug === '') {
                $findings[] = $this->finding('row', $row, $sourceId, 'missing_slug', $columns['slug'], null);
                $slug = null;
            } elseif (! $this->slug($slug)) {
                $findings[] = $this->finding('row', $row, $sourceId, 'invalid_slug', $columns['slug'], CellText::detail($slug));
                $slug = null;
            }
        } else {
            $raw['slug'] = null;
            $slug = $title === null ? null : $this->deriveSlug($title);
            if ($title !== null && $slug === null) {
                $findings[] = $this->finding('row', $row, $sourceId, 'slug_underivable', $columns['title'], null);
            }
        }
        $metadata['slug'] = $slug;

        if (isset($columns['artist'])) {
            $artist = trim($cells[$columns['artist']]);
            $raw['artist'] = $cells[$columns['artist']];
            if ($artist === '') {
                $findings[] = $this->finding('row', $row, $sourceId, 'missing_artist', $columns['artist'], null);
                $artist = null;
            } elseif (! CellText::line($artist, 255)) {
                $findings[] = $this->finding('row', $row, $sourceId, 'invalid_artist', $columns['artist'], $this->bounds($artist, 255));
                $artist = null;
            }
        } else {
            $artist = $mapping['constants']['artist'];
            $raw['artist'] = null;
        }
        $metadata['artist'] = $artist;

        $bpm = null;
        $raw['bpm'] = null;
        if (isset($columns['bpm'])) {
            $raw['bpm'] = $cells[$columns['bpm']];
            $value = trim($cells[$columns['bpm']]);
            if ($value !== '') {
                if (preg_match('/\A[0-9]{1,4}\z/D', $value) !== 1 || (int) $value < 20 || (int) $value > 400) {
                    $findings[] = $this->finding('row', $row, $sourceId, 'invalid_bpm', $columns['bpm'], CellText::detail($value));
                } else {
                    $bpm = (int) $value;
                }
            }
        }
        $metadata['bpm'] = $bpm;

        foreach (['musical_key' => 24, 'genre' => 255, 'mood' => 255] as $field => $maximum) {
            $metadata[$field] = null;
            $raw[$field] = null;
            if (isset($columns[$field])) {
                $raw[$field] = $cells[$columns[$field]];
                $value = trim($cells[$columns[$field]]);
                if ($value === '') {
                    continue;
                }
                if (CellText::line($value, $maximum)) {
                    $metadata[$field] = $value;
                } else {
                    $findings[] = $this->finding('row', $row, $sourceId, 'invalid_'.$field, $columns[$field], $this->bounds($value, $maximum));
                }
            }
        }

        $metadata['tags'] = [];
        $raw['tags'] = null;
        if (isset($columns['tags'])) {
            $raw['tags'] = $cells[$columns['tags']];
            $value = trim($cells[$columns['tags']]);
            if ($value !== '') {
                $tags = array_values(array_filter(array_map('trim', explode($mapping['tag_separator'], $value)),
                    static fn (string $tag): bool => $tag !== ''));
                $problem = null;
                if (count($tags) > 20) {
                    $problem = sprintf('%d tags, maximum 20', count($tags));
                } elseif (count(array_unique($tags, SORT_STRING)) !== count($tags)) {
                    $problem = 'duplicate tag';
                } elseif (! array_all($tags, static fn (string $tag): bool => CellText::line($tag, 80))) {
                    $problem = 'a tag is longer than 80 characters or contains control characters';
                }
                if ($problem === null) {
                    $metadata['tags'] = $tags;
                } else {
                    $findings[] = $this->finding('row', $row, $sourceId, 'invalid_tags', $columns['tags'], $problem);
                }
            }
        }

        $metadata['description'] = null;
        $raw['description'] = null;
        if (isset($columns['description'])) {
            $raw['description'] = $cells[$columns['description']];
            $value = trim($cells[$columns['description']]);
            if ($value !== '') {
                if (CellText::multiline($value, 10000)) {
                    $metadata['description'] = $value;
                } else {
                    $findings[] = $this->finding('row', $row, $sourceId, 'invalid_description', $columns['description'], $this->bounds($value, 10000));
                }
            }
        }

        if (isset($columns['visibility'])) {
            $value = trim($cells[$columns['visibility']]);
            if ($value === '') {
                $findings[] = $this->finding('row', $row, $sourceId, 'missing_visibility', $columns['visibility'], null);
                $visibility = null;
            } elseif (! isset($mapping['visibility_values'][$value])) {
                $findings[] = $this->finding('row', $row, $sourceId, 'unknown_visibility_value', $columns['visibility'], CellText::detail($value));
                $visibility = null;
            } else {
                $visibility = $mapping['visibility_values'][$value];
            }
        } else {
            $visibility = $mapping['constants']['visibility'];
        }

        $rights = trim($cells[$columns['rights_reference']]);
        if ($rights === '') {
            $findings[] = $this->finding('row', $row, $sourceId, 'missing_rights_reference', $columns['rights_reference'], null);
            $rights = null;
        } elseif (! CellText::line($rights, 192)) {
            $findings[] = $this->finding('row', $row, $sourceId, 'invalid_rights_reference', $columns['rights_reference'], $this->bounds($rights, 192));
            $rights = null;
        }

        if (isset($columns['currency']) && trim($cells[$columns['currency']]) !== $mapping['currency']) {
            $findings[] = $this->finding('row', $row, $sourceId, 'currency_mismatch', $columns['currency'],
                CellText::detail(trim($cells[$columns['currency']])));
        }

        $offers = [];
        foreach ($mapping['offers'] as $offer) {
            $name = $offer['license'] ?? trim($cells[$offer['license_column']]);
            $label = null;
            if ($name === '') {
                $findings[] = $this->finding('row', $row, $sourceId, 'missing_license_name', $offer['license_column'], null);
                $name = null;
            } elseif (! isset($mapping['licenses'][$name])) {
                $findings[] = $this->finding('row', $row, $sourceId, 'unknown_license_name', $offer['license_column'], CellText::detail($name));
            } else {
                $label = $mapping['licenses'][$name];
            }
            $price = trim($cells[$offer['price_column']]);
            $minor = null;
            if ($price === '') {
                $findings[] = $this->finding('row', $row, $sourceId, 'missing_price', $offer['price_column'], null);
            } elseif (preg_match('/\A\$?([0-9]{1,7})(?:\.([0-9]{1,2}))?\z/D', $price, $match) !== 1) {
                $findings[] = $this->finding('row', $row, $sourceId, 'non_numeric_price', $offer['price_column'], CellText::detail($price));
            } else {
                // USD has two minor-unit digits; the mapping admits no other currency.
                $minor = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '0', 2, '0');
            }
            $offers[] = ['license_name' => $name, 'license_label' => $label, 'price_column' => $offer['price_column'],
                'price_minor_units' => $minor, 'currency' => $mapping['currency']];
        }

        $entry = $this->entry($row, $sourceId, $hash, null);
        $entry['metadata'] = $metadata;
        $entry['raw_metadata'] = $raw;
        $entry['visibility'] = $visibility;
        $entry['offers'] = $offers;
        $entry['rights_reference'] = $rights;
        if ($sourceId !== null) {
            $entry['record_key'] = (new NormalizedSourceSnapshot)->recordKey($mapping['source_system'], $sourceId);
        }
        if ($visibility !== null && ! in_array(null, [$metadata['title'], $metadata['slug'], $metadata['artist']], true)) {
            $entry['source_record_sha256'] = CanonicalJson::hash($this->payload($raw, $metadata, $visibility));
        }

        return $entry;
    }

    private function duplicates(array $entries, array &$findings): void
    {
        $ids = [];
        $hashes = [];
        $slugs = [];
        foreach ($entries as $entry) {
            if ($entry['source_id'] !== null) {
                $ids[$entry['source_id']][] = $entry['row'];
            }
            $hashes[$entry['row_sha256']][] = $entry['row'];
            if (($entry['metadata']['slug'] ?? null) !== null) {
                $slugs[$entry['metadata']['slug']][] = $entry['row'];
            }
        }
        foreach ($entries as $entry) {
            $row = $entry['row'];
            $id = $entry['source_id'];
            if ($id !== null && count($ids[$id]) > 1) {
                $findings[] = $this->finding('row', $row, $id, 'duplicate_source_id', null, CellText::detail($id));
            }
            if (count($hashes[$entry['row_sha256']]) > 1) {
                $findings[] = $this->finding('row', $row, $id, 'duplicate_row_content', null, $entry['row_sha256']);
            }
            $slug = $entry['metadata']['slug'] ?? null;
            if ($slug !== null && count($slugs[$slug]) > 1) {
                $findings[] = $this->finding('row', $row, $id, 'duplicate_slug', null, $slug);
            }
        }
    }

    /** Build the exact drafts-v1 object for a sheet with zero findings. */
    private function snapshot(array $mapping, string $exportBytes, string $exportName, array $entries): array
    {
        $records = [];
        foreach ($entries as $entry) {
            $payload = $this->payload($entry['raw_metadata'], $entry['metadata'], $entry['visibility']);
            $records[] = ['source_id' => $entry['source_id'], 'source_record_sha256' => CanonicalJson::hash($payload)] + $payload;
        }
        $snapshot = ['schema_version' => 1, 'purpose' => NormalizedSourceSnapshot::SCHEMA, 'snapshot_id' => $mapping['snapshot_id'],
            'source_system' => $mapping['source_system'], 'acquired_at' => $mapping['acquired_at'],
            'source_as_of' => $mapping['source_as_of'], 'acquisition_method' => $mapping['acquisition_method'],
            'operator_reference' => $mapping['operator_reference'],
            'artifacts' => [['artifact_id' => self::ARTIFACT_ID, 'relative_path' => 'raw/'.$exportName,
                'sha256' => hash('sha256', $exportBytes), 'bytes' => strlen($exportBytes)]],
            'records' => $records];

        return $snapshot;
    }

    /** Prove the snapshot decoder accepts a within-limit snapshot unchanged. */
    private function admitted(array $snapshot): void
    {
        try {
            $decoded = (new NormalizedSourceSnapshot)->decode(CanonicalJson::encode($snapshot)."\n");
            if (CanonicalJson::encode($decoded) !== CanonicalJson::encode($snapshot)) {
                throw new RuntimeException('normalizer_output_rejected');
            }
        } catch (Throwable) {
            // Within the size limit, rows that passed every finding check must decode; anything else is a normalizer defect.
            throw new RuntimeException('normalizer_output_rejected');
        }
    }

    private function payload(array $raw, array $metadata, string $visibility): array
    {
        return ['artifact_ids' => [self::ARTIFACT_ID], 'raw_metadata' => $raw, 'metadata' => $metadata,
            'visibility' => $visibility, 'assets' => []];
    }

    private function entry(int $row, ?string $sourceId, string $hash, ?string $disposition): array
    {
        return ['row' => $row, 'source_id' => $sourceId, 'row_sha256' => $hash, 'disposition' => $disposition, 'findings' => [],
            'record_key' => null, 'source_record_sha256' => null, 'visibility' => null, 'metadata' => null,
            'raw_metadata' => null, 'offers' => null, 'rights_reference' => null];
    }

    private function finding(string $scope, ?int $row, ?string $sourceId, string $code, ?string $column, ?string $detail): array
    {
        return ['scope' => $scope, 'row' => $row, 'source_id' => $sourceId, 'code' => $code, 'column' => $column, 'detail' => $detail];
    }

    private function sort(array $findings): array
    {
        usort($findings, static fn (array $left, array $right): int => [$left['scope'] === 'sheet' ? 0 : 1, $left['row'] ?? 0,
            $left['code'], $left['column'] ?? '', $left['detail'] ?? '']
            <=> [$right['scope'] === 'sheet' ? 0 : 1, $right['row'] ?? 0, $right['code'], $right['column'] ?? '', $right['detail'] ?? '']);

        return array_values($findings);
    }

    private function slug(string $value): bool
    {
        return mb_strlen($value, 'UTF-8') <= 255 && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $value) === 1;
    }

    /** ASCII-only derivation: every other character becomes a separator, so the report shows exactly what was lost. */
    private function deriveSlug(string $title): ?string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');

        return $slug !== '' && $this->slug($slug) ? $slug : null;
    }

    private function bounds(string $value, int $maximum): string
    {
        return sprintf('%d characters, maximum %d, or control characters', mb_strlen($value, 'UTF-8'), $maximum);
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($code);
        }
    }
}
