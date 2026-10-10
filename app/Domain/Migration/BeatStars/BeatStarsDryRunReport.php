<?php

declare(strict_types=1);

namespace App\Domain\Migration\BeatStars;

use App\Support\CanonicalJson;

/**
 * Deterministic JSON and Markdown renderings of one normalizer result.
 *
 * Both renderings are pure functions of the result: no clock, no host name, no absolute path.
 * The JSON report omits the embedded snapshot (it is written as its own file) and carries the
 * snapshot's SHA-256 instead, so the report can later be matched against the importer review.
 */
final class BeatStarsDryRunReport
{
    public function json(array $result): string
    {
        $report = $result;
        unset($report['snapshot']);
        // Canonical first (byte-sorted keys), then pretty-printed for operators; both steps are deterministic.
        $decoded = json_decode(CanonicalJson::encode($report), false, 64, JSON_THROW_ON_ERROR);

        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    public function snapshot(array $result): ?string
    {
        return $result['snapshot'] === null ? null : CanonicalJson::encode($result['snapshot'])."\n";
    }

    public function markdown(array $result): string
    {
        $mapping = $result['mapping'];
        $counts = $result['counts'];
        $lines = ['# BeatStars export dry run', '',
            sprintf('Mode `%s`, transform `%s`, draft schema `%s`, canonicalization `%s`.', $result['mode'],
                $result['transform_version'], $result['draft_schema'], $result['canonicalization_version']),
            '', '**Nothing was applied.** Database writes: 0. This report retains source ids and hashes; it creates no track, '
            .'offer, license, rights declaration, customer record or consent.', '', '## Inputs', '',
            '| Input | Name | SHA-256 | Bytes |', '| --- | --- | --- | --- |',
            sprintf('| Export | `%s` | `%s` | %d |', $this->cell($result['export']['name']), $result['export']['sha256'], $result['export']['bytes']),
            sprintf('| Mapping | (operator file) | `%s` | — |', $mapping['sha256']), '',
            sprintf('Source system `%s`, snapshot `%s`, acquisition method `%s`, acquired at `%s`, source as of `%s`, currency `%s`, slug policy `%s`.',
                $this->cell($mapping['source_system']), $this->cell($mapping['snapshot_id']), $mapping['acquisition_method'],
                $mapping['acquired_at'], $mapping['source_as_of'], $mapping['currency'], $mapping['slug_policy']),
            '', sprintf('Operator reference: %s', $this->cell($mapping['operator_reference'])), '',
            '## Effective mapping', '', '| Field | Source |', '| --- | --- |'];
        foreach (BeatStarsExportMapping::FIELDS as $field) {
            if (isset($mapping['columns'][$field])) {
                $lines[] = sprintf('| `%s` | column `%s` |', $field, $this->cell($mapping['columns'][$field]));
            } elseif (isset($mapping['constants'][$field])) {
                $lines[] = sprintf('| `%s` | constant `%s` |', $field, $this->cell($mapping['constants'][$field]));
            } elseif ($field === 'slug') {
                $lines[] = '| `slug` | derived from the title (ASCII letters and digits only) |';
            } else {
                $lines[] = sprintf('| `%s` | not in the export (null) |', $field);
            }
        }
        $lines[] = '';
        $lines[] = '| Offer | License | Price column |';
        $lines[] = '| --- | --- | --- |';
        foreach ($mapping['offers'] as $position => $offer) {
            $license = $offer['license'] !== null ? sprintf('constant `%s`', $this->cell($offer['license']))
                : sprintf('column `%s`', $this->cell($offer['license_column']));
            $lines[] = sprintf('| %d | %s | `%s` |', $position + 1, $license, $this->cell($offer['price_column']));
        }
        $lines[] = '';
        $lines[] = sprintf('Known license names: %s.', $this->names(array_keys($mapping['licenses'])));
        $lines[] = sprintf('Visibility values: %s.', $this->pairs($mapping['visibility_values']));
        $lines[] = sprintf('Ignored columns: %s.', $this->names($mapping['ignored_columns']));
        $lines[] = sprintf('Export columns: %s.', $this->names($result['export']['columns']));
        $lines[] = '';
        $lines[] = '## Counts';
        $lines[] = '';
        $lines[] = '| Rows | Normalized | Withheld | Findings | Sheet findings | Row findings |';
        $lines[] = '| --- | --- | --- | --- | --- | --- |';
        $lines[] = sprintf('| %d | %d | %d | %d | %d | %d |', $counts['rows'], $counts['normalized'], $counts['withheld'],
            $counts['findings'], $counts['sheet_findings'], $counts['row_findings']);
        $lines[] = '';
        $lines[] = $result['snapshot_sha256'] === null
            ? 'No draft snapshot was produced: every finding above must be resolved in the export or the mapping, then rerun.'
            : sprintf('Draft snapshot `catalog.json` SHA-256 `%s` (%d records). It is an input for a separately authorized importer review, not an import.',
                $result['snapshot_sha256'], $counts['normalized']);
        $lines[] = '';
        $lines[] = '## Findings';
        $lines[] = '';
        if ($result['findings'] === []) {
            $lines[] = 'None.';
        } else {
            $lines[] = '| Scope | Row | Source id | Code | Column | Detail |';
            $lines[] = '| --- | --- | --- | --- | --- | --- |';
            foreach ($result['findings'] as $finding) {
                $lines[] = sprintf('| %s | %s | %s | `%s` | %s | %s |', $finding['scope'], $finding['row'] ?? '—',
                    $this->optional($finding['source_id']), $finding['code'], $this->optional($finding['column']), $this->optional($finding['detail']));
            }
            if ($counts['sheet_findings'] > 0) {
                $lines[] = '';
                $lines[] = 'Sheet findings withhold every row: the sheet cannot be interpreted until each column is mapped or explicitly ignored.';
            }
        }
        $lines[] = '';
        $lines[] = '## Rows';
        $lines[] = '';
        $lines[] = '| Row | Source id | Disposition | Findings | Title | Slug | Visibility | Offers (minor units) | Rights reference |';
        $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- |';
        foreach ($result['entries'] as $entry) {
            $metadata = $entry['metadata'];
            $offers = $entry['offers'] === null ? '—' : implode('; ', array_map(static fn (array $offer): string => sprintf('%s %d %s',
                $offer['license_label'], $offer['price_minor_units'], $offer['currency']), $entry['offers']));
            $lines[] = sprintf('| %d | %s | %s | %s | %s | %s | %s | %s | %s |', $entry['row'], $this->optional($entry['source_id']),
                $entry['disposition'], $entry['findings'] === [] ? '—' : implode(', ', $entry['findings']),
                $this->optional($metadata['title'] ?? null), $this->optional($metadata['slug'] ?? null), $this->optional($entry['visibility']),
                $offers, $this->optional($entry['rights_reference']));
        }
        $lines[] = '';
        $lines[] = '## Statements';
        $lines[] = '';
        $lines[] = '- This dry run applies nothing: no database, draft store, media or import batch was written.';
        $lines[] = '- Marketing consent is never inferred from a catalog export; the mapping has no consent field.';
        $lines[] = '- Rights references are operator references to private evidence, not clearance; publication still requires the reviewed declaration.';
        $lines[] = '- Prices and license names are retained as evidence only; no offer, price or license is created, approved or implied.';
        $lines[] = '- Source ids and per-row content hashes are preserved so the importer review can be reconciled to this report.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function cell(string $value): string
    {
        return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $value);
    }

    private function optional(?string $value): string
    {
        return $value === null || $value === '' ? '—' : $this->cell($value);
    }

    private function names(array $values): string
    {
        return $values === [] ? 'none' : implode(', ', array_map(fn (string $value): string => '`'.$this->cell($value).'`', $values));
    }

    private function pairs(array $values): string
    {
        $pairs = [];
        foreach ($values as $source => $target) {
            $pairs[] = sprintf('`%s` → `%s`', $this->cell((string) $source), $target);
        }

        return $pairs === [] ? 'constant' : implode(', ', $pairs);
    }
}
