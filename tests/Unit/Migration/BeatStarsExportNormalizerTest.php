<?php

namespace Tests\Unit\Migration;

use App\Domain\Migration\BeatStars\BeatStarsExportMapping;
use App\Domain\Migration\BeatStars\BeatStarsExportNormalizer;
use App\Domain\Migration\CatalogOnboarding\NormalizedSourceSnapshot;
use App\Support\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure normalizer behaviour on the synthetic fixture and programmatic variants; no database, clock or filesystem writes. */
class BeatStarsExportNormalizerTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/migration/beatstars-synthetic';

    private const HEADERS = ['Track ID', 'Title', 'BPM', 'Key', 'Genre', 'Mood', 'Tags', 'Description', 'Status',
        'Lease Name', 'Lease Price', 'Exclusive Price', 'Rights Reference', 'Plays'];

    public function test_synthetic_fixture_normalizes_with_zero_findings_into_a_decodable_draft_snapshot(): void
    {
        $export = file_get_contents(self::FIXTURES.'/export.csv');
        $mapping = file_get_contents(self::FIXTURES.'/mapping.json');
        $result = (new BeatStarsExportNormalizer)->normalize($export, 'export.csv', $mapping);
        $this->assertSame($result, (new BeatStarsExportNormalizer)->normalize($export, 'export.csv', $mapping));
        $this->assertSame(['rows' => 4, 'normalized' => 4, 'withheld' => 0, 'findings' => 0, 'sheet_findings' => 0, 'row_findings' => 0], $result['counts']);
        $this->assertSame([], $result['findings']);
        $this->assertFalse($result['applied']);
        $this->assertSame(0, $result['database_writes']);
        $this->assertSame(NormalizedSourceSnapshot::SCHEMA, $result['draft_schema']);
        $this->assertSame(['bs-synthetic-0001', 'bs-synthetic-0002', 'bs-synthetic-0003', 'bs-synthetic-0004'], array_column($result['entries'], 'source_id'));
        $this->assertSame(['normalized', 'normalized', 'normalized', 'normalized'], array_column($result['entries'], 'disposition'));
        $this->assertSame(['public', 'private', 'draft', 'sold'], array_column($result['entries'], 'visibility'));
        $this->assertSame(['synthetic-track-01', 'synthetic-track-02', 'synthetic-track-03', 'synthetic-track-04'],
            array_map(static fn (array $entry): string => $entry['metadata']['slug'], $result['entries']));
        $first = $result['entries'][0];
        $this->assertSame(['title' => 'SYNTHETIC Track 01', 'slug' => 'synthetic-track-01', 'artist' => 'SYNTHETIC Producer', 'bpm' => 95,
            'musical_key' => 'C minor', 'genre' => 'Synthetic', 'mood' => null, 'tags' => ['synthetic', 'fixture'],
            'description' => 'SYNTHETIC description, with a comma and "quotes".'], $first['metadata']);
        $this->assertSame(['title' => 'SYNTHETIC Track 01', 'slug' => null, 'artist' => null, 'bpm' => '95', 'musical_key' => 'C minor',
            'genre' => 'Synthetic', 'mood' => '', 'tags' => 'synthetic;fixture',
            'description' => 'SYNTHETIC description, with a comma and "quotes".'], $first['raw_metadata']);
        $this->assertSame([100, 1000], array_column($first['offers'], 'price_minor_units'));
        $this->assertSame(['synthetic-basic', 'synthetic-exclusive'], array_column($first['offers'], 'license_label'));
        $this->assertSame('SYNTHETIC-RIGHTS-0001', $first['rights_reference']);
        $this->assertSame("SYNTHETIC two-line\ndescription", $result['entries'][1]['metadata']['description']);
        $this->assertSame([250, 2000], array_column($result['entries'][1]['offers'], 'price_minor_units'));
        $third = $result['entries'][2];
        $this->assertSame([null, null, null, [], null], [$third['metadata']['bpm'], $third['metadata']['musical_key'],
            $third['metadata']['mood'], $third['metadata']['tags'], $third['metadata']['description']]);
        $this->assertSame([300, 3000], array_column($third['offers'], 'price_minor_units'));
        $fourth = $result['entries'][3];
        $this->assertSame(88, $fourth['metadata']['bpm']);
        $this->assertSame('0088', $fourth['raw_metadata']['bpm']);
        $this->assertSame(['synthetic', 'fixture', 'dark'], $fourth['metadata']['tags']);
        $this->assertSame([499, 4000], array_column($fourth['offers'], 'price_minor_units'));
        foreach ($result['entries'] as $entry) {
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $entry['row_sha256']);
            $this->assertSame((new NormalizedSourceSnapshot)->recordKey('synthetic/beatstars-export:fixture', $entry['source_id']), $entry['record_key']);
        }
        $this->assertCount(4, array_unique(array_column($result['entries'], 'row_sha256')));

        $snapshot = $result['snapshot'];
        $bytes = CanonicalJson::encode($snapshot)."\n";
        $this->assertSame(hash('sha256', $bytes), $result['snapshot_sha256']);
        $decoded = (new NormalizedSourceSnapshot)->decode($bytes);
        $this->assertSame(CanonicalJson::encode($snapshot), CanonicalJson::encode($decoded));
        $this->assertSame('synthetic_fixture', $decoded['acquisition_method']);
        $this->assertSame(CanonicalJson::encode([['artifact_id' => 'beatstars-export', 'relative_path' => 'raw/export.csv',
            'sha256' => hash('sha256', $export), 'bytes' => strlen($export)]]), CanonicalJson::encode($decoded['artifacts']));
        $this->assertSame(array_column($result['entries'], 'source_id'), array_column($decoded['records'], 'source_id'));
        $this->assertSame(array_column($result['entries'], 'source_record_sha256'), array_column($decoded['records'], 'source_record_sha256'));
        foreach ($decoded['records'] as $position => $record) {
            $this->assertSame(CanonicalJson::encode($result['entries'][$position]['metadata']), CanonicalJson::encode($record['metadata']));
            $this->assertSame(CanonicalJson::encode($result['entries'][$position]['raw_metadata']), CanonicalJson::encode($record['raw_metadata']));
            $this->assertSame([], $record['assets']);
            $this->assertArrayNotHasKey('price', $record);
            $this->assertArrayNotHasKey('offers', $record);
            $this->assertArrayNotHasKey('rights_reference', $record);
        }
        $this->assertStringNotContainsString('consent', CanonicalJson::encode($snapshot));
        $this->assertSame('not_inferred', $result['statements']['marketing_consent']);
    }

    public function test_one_unmapped_column_and_one_missing_rights_reference_are_exactly_the_findings_and_withhold_rows(): void
    {
        $result = (new BeatStarsExportNormalizer)->normalize(file_get_contents(self::FIXTURES.'/export-findings.csv'),
            'export-findings.csv', file_get_contents(self::FIXTURES.'/mapping.json'));
        $this->assertSame([
            ['scope' => 'sheet', 'row' => null, 'source_id' => null, 'code' => 'unmapped_column', 'column' => 'Likes', 'detail' => null],
            ['scope' => 'row', 'row' => 3, 'source_id' => 'bs-synthetic-0003', 'code' => 'missing_rights_reference', 'column' => 'Rights Reference', 'detail' => null],
        ], $result['findings']);
        $this->assertSame(['rows' => 4, 'normalized' => 0, 'withheld' => 4, 'findings' => 2, 'sheet_findings' => 1, 'row_findings' => 1], $result['counts']);
        $this->assertNull($result['snapshot']);
        $this->assertNull($result['snapshot_sha256']);
        foreach ($result['entries'] as $entry) {
            $this->assertSame('withheld', $entry['disposition']);
            $this->assertNull($entry['metadata']);
            $this->assertNull($entry['offers']);
            $this->assertSame($entry['row'] === 3 ? ['missing_rights_reference'] : [], $entry['findings']);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $entry['row_sha256']);
        }
        // The same source ids are still identified, so the operator can correct the exact rows.
        $this->assertSame(['bs-synthetic-0001', 'bs-synthetic-0002', 'bs-synthetic-0003', 'bs-synthetic-0004'], array_column($result['entries'], 'source_id'));
    }

    public function test_a_row_finding_withholds_only_its_row_while_any_finding_withholds_the_snapshot(): void
    {
        $rows = $this->rows();
        $rows[2][12] = '';
        $result = $this->normalize($rows);
        $this->assertSame(['missing_rights_reference'], array_column($result['findings'], 'code'));
        $this->assertSame(['normalized', 'normalized', 'withheld', 'normalized'], array_column($result['entries'], 'disposition'));
        $this->assertSame(['rows' => 4, 'normalized' => 3, 'withheld' => 1, 'findings' => 1, 'sheet_findings' => 0, 'row_findings' => 1], $result['counts']);
        $this->assertNotNull($result['entries'][0]['metadata']);
        $this->assertNull($result['entries'][2]['metadata']);
        $this->assertNull($result['snapshot']);

        $rows = $this->rows();
        $result = $this->normalize($rows, self::HEADERS, ['ignored_columns' => []]);
        $this->assertSame([['scope' => 'sheet', 'row' => null, 'source_id' => null, 'code' => 'unmapped_column', 'column' => 'Plays', 'detail' => null]], $result['findings']);
        $this->assertSame(0, $result['counts']['normalized']);
    }

    public static function rowFindings(): array
    {
        return [
            'missing price' => [[0, 10, ''], 'missing_price', 'Lease Price'],
            'word price' => [[0, 10, 'Negotiable'], 'non_numeric_price', 'Lease Price'],
            'grouped price' => [[0, 11, '1,299.00'], 'non_numeric_price', 'Exclusive Price'],
            'negative price' => [[0, 10, '-5'], 'non_numeric_price', 'Lease Price'],
            'three decimals' => [[0, 10, '3.999'], 'non_numeric_price', 'Lease Price'],
            'unknown license' => [[1, 9, 'SYNTHETIC Basic Lease'], 'unknown_license_name', 'Lease Name'],
            'missing license' => [[1, 9, ''], 'missing_license_name', 'Lease Name'],
            'unknown visibility' => [[1, 8, 'Archived'], 'unknown_visibility_value', 'Status'],
            'missing visibility' => [[1, 8, ''], 'missing_visibility', 'Status'],
            'float bpm' => [[0, 2, '95.0'], 'invalid_bpm', 'BPM'],
            'low bpm' => [[0, 2, '10'], 'invalid_bpm', 'BPM'],
            'word bpm' => [[0, 2, 'fast'], 'invalid_bpm', 'BPM'],
            'missing title' => [[2, 1, ''], 'missing_title', 'Title'],
            'control title' => [[2, 1, "SYNTHETIC\tTab"], 'invalid_title', 'Title'],
            'long title' => [[2, 1, 'SYNTHETIC '.str_repeat('x', 250)], 'invalid_title', 'Title'],
            'untagged synthetic title' => [[2, 1, 'Plain title'], 'synthetic_title_required', 'Title'],
            'missing source id' => [[3, 0, ''], 'missing_source_id', 'Track ID'],
            'long source id' => [[3, 0, str_repeat('i', 191)], 'invalid_source_id', 'Track ID'],
            'padded source id' => [[3, 0, ' bs-synthetic-padded '], 'invalid_source_id', 'Track ID'],
            'trailing-space source id' => [[3, 0, "bs-synthetic-padded\t"], 'invalid_source_id', 'Track ID'],
            'duplicate tag' => [[3, 6, 'dark;dark'], 'invalid_tags', 'Tags'],
            'too many tags' => [[3, 6, implode(';', range(1, 21))], 'invalid_tags', 'Tags'],
            'long key' => [[3, 3, str_repeat('k', 25)], 'invalid_musical_key', 'Key'],
            'long genre' => [[3, 4, str_repeat('g', 256)], 'invalid_genre', 'Genre'],
            'long rights reference' => [[3, 12, str_repeat('r', 193)], 'invalid_rights_reference', 'Rights Reference'],
            'control rights reference' => [[3, 12, "ref\x01"], 'invalid_rights_reference', 'Rights Reference'],
        ];
    }

    #[DataProvider('rowFindings')]
    public function test_each_row_defect_is_a_finding_on_that_row_only(array $change, string $code, string $column): void
    {
        [$row, $cell, $value] = $change;
        $rows = $this->rows();
        $rows[$row][$cell] = $value;
        $result = $this->normalize($rows);
        $this->assertCount(1, $result['findings']);
        $finding = $result['findings'][0];
        $this->assertSame(['row', $row + 1, $code, $column], [$finding['scope'], $finding['row'], $finding['code'], $finding['column']]);
        $this->assertSame($cell === 0 ? null : $rows[$row][0], $finding['source_id']);
        $expected = array_fill(0, 4, 'normalized');
        $expected[$row] = 'withheld';
        $this->assertSame($expected, array_column($result['entries'], 'disposition'));
        $this->assertSame([$code], $result['entries'][$row]['findings']);
        $this->assertNull($result['snapshot']);
        if ($finding['detail'] !== null) {
            $this->assertStringNotContainsString("\x01", $finding['detail']);
            $this->assertLessThanOrEqual(120, mb_strlen($finding['detail']));
        }
    }

    public function test_duplicate_source_ids_repeated_row_content_and_duplicate_slugs_withhold_every_involved_row(): void
    {
        $rows = $this->rows();
        $rows[1][0] = $rows[0][0];
        $result = $this->normalize($rows);
        $this->assertSame([[1, 'duplicate_source_id'], [2, 'duplicate_source_id']],
            array_map(static fn (array $finding): array => [$finding['row'], $finding['code']], $result['findings']));
        $this->assertSame(['withheld', 'withheld', 'normalized', 'normalized'], array_column($result['entries'], 'disposition'));

        $rows = $this->rows();
        $rows[3] = $rows[2];
        $rows[3][0] = 'bs-synthetic-0004-copy';
        $result = $this->normalize($rows);
        $codes = array_map(static fn (array $finding): array => [$finding['row'], $finding['code']], $result['findings']);
        $this->assertSame([[3, 'duplicate_row_content'], [3, 'duplicate_slug'], [4, 'duplicate_row_content'], [4, 'duplicate_slug']], $codes);
        $this->assertSame($result['entries'][2]['row_sha256'], $result['entries'][3]['row_sha256']);
        $this->assertSame($result['findings'][0]['detail'], $result['entries'][2]['row_sha256']);

        $rows = $this->rows();
        $rows[1][1] = 'SYNTHETIC Track 01!';
        $result = $this->normalize($rows);
        $this->assertSame(['duplicate_slug', 'duplicate_slug'], array_column($result['findings'], 'code'));
        $this->assertSame(['withheld', 'withheld', 'normalized', 'normalized'], array_column($result['entries'], 'disposition'));
    }

    public function test_sheet_structure_findings_withhold_every_row(): void
    {
        $rows = $this->rows();
        $headers = self::HEADERS;
        unset($headers[5]);
        foreach ($rows as &$row) {
            unset($row[5]);
            $row = array_values($row);
        }
        unset($row);
        $result = $this->normalize($rows, array_values($headers));
        $this->assertSame([['sheet', null, 'mapped_column_missing', 'Mood']],
            array_map(static fn (array $finding): array => [$finding['scope'], $finding['row'], $finding['code'], $finding['column']], $result['findings']));
        $this->assertSame(0, $result['counts']['normalized']);
        $this->assertSame(['bs-synthetic-0001', 'bs-synthetic-0002', 'bs-synthetic-0003', 'bs-synthetic-0004'], array_column($result['entries'], 'source_id'));

        $csv = $this->csv($this->rows());
        $lines = explode("\n", $csv);
        array_splice($lines, 2, 0, ['']);
        $result = (new BeatStarsExportNormalizer)->normalize(implode("\n", $lines), 'export.csv', $this->mapping());
        $this->assertSame([['sheet', 2, 'blank_record']], array_map(static fn (array $finding): array => [$finding['scope'], $finding['row'], $finding['code']], $result['findings']));
        $this->assertSame([1, 3, 4, 5], array_column($result['entries'], 'row'));
        $this->assertSame(0, $result['counts']['normalized']);

        $rows = $this->rows();
        $rows[2] = array_slice($rows[2], 0, 13);
        $result = $this->normalize($rows);
        $this->assertSame([['row', 3, 'column_count_mismatch', 'expected 14 cells, found 13']],
            array_map(static fn (array $finding): array => [$finding['scope'], $finding['row'], $finding['code'], $finding['detail']], $result['findings']));
        $this->assertNull($result['entries'][2]['source_id']);
        $this->assertSame(['normalized', 'normalized', 'withheld', 'normalized'], array_column($result['entries'], 'disposition'));
    }

    public function test_explicit_slug_and_currency_columns_are_checked_exactly(): void
    {
        $headers = [...self::HEADERS, 'Slug', 'Currency'];
        $rows = $this->rows();
        foreach ($rows as $index => &$row) {
            $row[] = 'synthetic-explicit-'.($index + 1);
            $row[] = 'USD';
        }
        unset($row);
        $overrides = ['slug_policy' => 'column', 'columns' => ['slug' => 'Slug', 'currency' => 'Currency']];
        $result = $this->normalize($rows, $headers, $overrides);
        $this->assertSame([], $result['findings']);
        $this->assertSame(['synthetic-explicit-1', 'synthetic-explicit-2', 'synthetic-explicit-3', 'synthetic-explicit-4'],
            array_map(static fn (array $entry): string => $entry['metadata']['slug'], $result['entries']));
        $this->assertSame('synthetic-explicit-1', $result['entries'][0]['raw_metadata']['slug']);

        $rows[0][14] = 'Bad Slug';
        $rows[1][14] = '';
        $rows[2][15] = 'EUR';
        $result = $this->normalize($rows, $headers, $overrides);
        $this->assertSame([[1, 'invalid_slug', 'Bad Slug'], [2, 'missing_slug', null], [3, 'currency_mismatch', 'EUR']],
            array_map(static fn (array $finding): array => [$finding['row'], $finding['code'], $finding['detail']], $result['findings']));
        $this->assertSame(['withheld', 'withheld', 'withheld', 'normalized'], array_column($result['entries'], 'disposition'));
    }

    public function test_slug_derivation_is_ascii_only_and_reports_an_underivable_title(): void
    {
        $rows = $this->rows();
        $rows[0][1] = 'Été — Track 01 (Remix)';
        $rows[1][1] = '???';
        $overrides = ['acquisition_method' => 'official_export', 'source_system' => 'beatstars/pro-page:synthetic-test'];
        $result = $this->normalize($rows, self::HEADERS, $overrides);
        $this->assertSame([[2, 'slug_underivable', 'Title']],
            array_map(static fn (array $finding): array => [$finding['row'], $finding['code'], $finding['column']], $result['findings']));
        $this->assertSame('t-track-01-remix', $result['entries'][0]['metadata']['slug']);
        $this->assertSame('Été — Track 01 (Remix)', $result['entries'][0]['metadata']['title']);
    }

    public function test_one_decimal_and_sub_dollar_prices_convert_exactly_to_minor_units(): void
    {
        $rows = $this->rows();
        $rows[0][10] = '12.5';
        $rows[0][11] = '$0.5';
        $rows[1][10] = '0.05';
        $rows[1][11] = '$29.9';
        $result = $this->normalize($rows);
        $this->assertSame([], $result['findings']);
        $this->assertSame([1250, 50], array_column($result['entries'][0]['offers'], 'price_minor_units'));
        $this->assertSame([5, 2990], array_column($result['entries'][1]['offers'], 'price_minor_units'));
        $this->assertSame(['USD', 'USD'], array_column($result['entries'][0]['offers'], 'currency'));
    }

    public function test_a_snapshot_over_the_decoder_limit_is_a_sheet_finding_and_a_large_sheet_under_it_still_normalizes(): void
    {
        // 120 rows with 5,000-character descriptions: a 0.6 MB sheet whose snapshot (raw and normalized metadata) exceeds 1 MiB.
        $result = $this->normalize($this->largeRows(120));
        $this->assertCount(1, $result['findings']);
        $finding = $result['findings'][0];
        $this->assertSame(['sheet', null, null, 'snapshot_too_large', null], [$finding['scope'], $finding['row'], $finding['source_id'],
            $finding['code'], $finding['column']]);
        $this->assertMatchesRegularExpression('/\A([0-9]+) bytes, maximum 1048576\z/D', $finding['detail']);
        $this->assertGreaterThan(BeatStarsExportNormalizer::MAX_SNAPSHOT_BYTES, (int) $finding['detail']);
        $this->assertContains('snapshot_too_large', BeatStarsExportNormalizer::SHEET_CODES);
        $this->assertSame(['rows' => 120, 'normalized' => 0, 'withheld' => 120, 'findings' => 1, 'sheet_findings' => 1, 'row_findings' => 0],
            $result['counts']);
        $this->assertNull($result['snapshot']);
        $this->assertNull($result['snapshot_sha256']);
        foreach ($result['entries'] as $position => $entry) {
            $this->assertSame([sprintf('bs-synthetic-large-%04d', $position + 1), 'withheld', [], null],
                [$entry['source_id'], $entry['disposition'], $entry['findings'], $entry['metadata']]);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $entry['row_sha256']);
        }

        // The same rows at a third of the count stay under the limit: no finding, and the decoder admits the snapshot.
        $result = $this->normalize($this->largeRows(40));
        $this->assertSame([], $result['findings']);
        $this->assertSame(40, $result['counts']['normalized']);
        $bytes = CanonicalJson::encode($result['snapshot'])."\n";
        $this->assertGreaterThan(400000, strlen($bytes));
        $this->assertLessThanOrEqual(BeatStarsExportNormalizer::MAX_SNAPSHOT_BYTES, strlen($bytes));
        $this->assertSame(hash('sha256', $bytes), $result['snapshot_sha256']);
        $this->assertSame(CanonicalJson::encode($result['snapshot']), CanonicalJson::encode((new NormalizedSourceSnapshot)->decode($bytes)));
    }

    public function test_byte_order_mark_and_crlf_are_accepted_and_raw_cells_keep_their_spacing(): void
    {
        $rows = $this->rows();
        $rows[0][4] = '  Synthetic  ';
        $csv = "\xEF\xBB\xBF".str_replace("\n", "\r\n", $this->csv($rows));
        $result = (new BeatStarsExportNormalizer)->normalize($csv, 'export.csv', $this->mapping());
        $this->assertSame([], $result['findings']);
        $this->assertSame(self::HEADERS, $result['export']['columns']);
        $this->assertSame('  Synthetic  ', $result['entries'][0]['raw_metadata']['genre']);
        $this->assertSame('Synthetic', $result['entries'][0]['metadata']['genre']);
        $this->assertSame("SYNTHETIC two-line\r\ndescription", $result['entries'][1]['metadata']['description']);
    }

    public static function mappingRefusals(): array
    {
        return [
            'invalid json' => [static fn (array $mapping): string => '{', 'mapping_json'],
            'unknown key' => [['defaults' => []], 'mapping_keys'],
            'consent column' => [['columns' => ['marketing_consent' => 'Opt-in']], 'mapping_columns'],
            'customer column' => [['columns' => ['customer_email' => 'Email']], 'mapping_columns'],
            'missing rights column' => [['columns' => ['rights_reference' => null]], 'mapping_required_field'],
            'missing artist' => [['constants' => null], 'mapping_required_field'],
            'constants as list' => [['constants' => []], 'mapping_constants'],
            'artist column and constant' => [['columns' => ['artist' => 'Plays']], 'mapping_constants'],
            'slug policy without column' => [['slug_policy' => 'column'], 'mapping_slug_policy'],
            'slug column without policy' => [['columns' => ['slug' => 'Plays']], 'mapping_slug_policy'],
            'bad timestamp' => [['acquired_at' => '2026-10-10 00:00:00'], 'mapping_timestamp'],
            'watermark after acquisition' => [['source_as_of' => '2026-10-11T00:00:00Z'], 'mapping_timestamp'],
            'bad acquisition method' => [['acquisition_method' => 'guessed'], 'mapping_acquisition_method'],
            'foreign currency' => [['currency' => 'EUR'], 'mapping_currency'],
            'license label' => [['licenses' => ['SYNTHETIC Basic' => 'Basic Lease']], 'mapping_licenses'],
            'no offers' => [['offers' => []], 'mapping_offers'],
            'offer with two license sources' => [['offers' => [['license' => 'SYNTHETIC Basic', 'license_column' => 'Lease Name', 'price_column' => 'Lease Price']]], 'mapping_offers'],
            'offer with undeclared license' => [['offers' => [['license' => 'SYNTHETIC Gold', 'price_column' => 'Lease Price']]], 'mapping_offers'],
            'ignored and mapped' => [['ignored_columns' => ['Title']], 'mapping_column_reuse'],
            'price column mapped twice' => [['ignored_columns' => ['Lease Price']], 'mapping_column_reuse'],
            'tags without separator' => [['tag_separator' => null], 'mapping_tag_separator'],
            'visibility without values' => [['visibility_values' => null], 'mapping_visibility_values'],
            'unknown visibility target' => [['visibility_values' => ['Public' => 'live']], 'mapping_visibility_values'],
            'constant visibility invalid' => [['columns' => ['visibility' => null], 'visibility_values' => null, 'constants' => ['artist' => 'SYNTHETIC Producer', 'visibility' => 'live']], 'mapping_constants'],
            'wrong purpose' => [['purpose' => 'vasey-beatstars-export-mapping-v2'], 'mapping_purpose'],
            'blank identity' => [['snapshot_id' => ' '], 'mapping_identity'],
            'oversized' => [static fn (array $mapping): string => json_encode($mapping + ['pad' => str_repeat('x', 1048577)]), 'mapping_too_large'],
        ];
    }

    #[DataProvider('mappingRefusals')]
    public function test_an_undeclared_or_ambiguous_mapping_is_refused_with_a_reason_code(mixed $change, string $code): void
    {
        $bytes = is_callable($change) ? $change($this->mappingArray()) : $this->mapping($change);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($code);
        (new BeatStarsExportNormalizer)->normalize($this->csv($this->rows()), 'export.csv', $bytes);
    }

    public static function exportRefusals(): array
    {
        return [
            'invalid utf8' => ["Track ID,Title\n\xFF,SYNTHETIC\n", 'export.csv', 'export_encoding'],
            'nul byte' => ["Track ID,Title\n\0,SYNTHETIC\n", 'export.csv', 'export_encoding'],
            'duplicate header' => ["Track ID,Track ID\n1,2\n", 'export.csv', 'export_header'],
            'blank header' => ["Track ID,\n1,2\n", 'export.csv', 'export_header'],
            'empty file' => ['', 'export.csv', 'export_header'],
            'header only' => ["Track ID,Title\n", 'export.csv', 'export_empty'],
            'blank records only' => ["Track ID,Title\n\n\n", 'export.csv', 'export_empty'],
            'too many rows' => ["Track ID\n".str_repeat("1\n", 1001), 'export.csv', 'export_too_many_rows'],
            'bad name' => ["Track ID\n1\n", 'export file.csv', 'export_name'],
            'dot name' => ["Track ID\n1\n", '..', 'export_name'],
            'oversized' => [str_repeat('x', 8388609), 'export.csv', 'export_too_large'],
        ];
    }

    #[DataProvider('exportRefusals')]
    public function test_an_uninterpretable_export_is_refused_with_a_reason_code(string $export, string $name, string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($code);
        (new BeatStarsExportNormalizer)->normalize($export, $name, $this->mapping());
    }

    public function test_mapping_decoder_exposes_only_declared_fields(): void
    {
        $mapping = (new BeatStarsExportMapping)->decode(file_get_contents(self::FIXTURES.'/mapping.json'));
        $this->assertSame(['snapshot_id', 'source_system', 'operator_reference', 'acquisition_method', 'acquired_at', 'source_as_of',
            'currency', 'slug_policy', 'columns', 'constants', 'visibility_values', 'tag_separator', 'licenses', 'offers', 'ignored_columns'], array_keys($mapping));
        $this->assertSame(['artist' => 'SYNTHETIC Producer'], $mapping['constants']);
        $this->assertSame(';', $mapping['tag_separator']);
        $this->assertSame([['license' => null, 'license_column' => 'Lease Name', 'price_column' => 'Lease Price'],
            ['license' => 'SYNTHETIC Exclusive', 'license_column' => null, 'price_column' => 'Exclusive Price']], $mapping['offers']);
        $this->assertNotContains('marketing_consent', BeatStarsExportMapping::FIELDS);
        $this->assertNotContains('email', BeatStarsExportMapping::FIELDS);
    }

    private function rows(): array
    {
        return [
            ['bs-synthetic-0001', 'SYNTHETIC Track 01', '95', 'C minor', 'Synthetic', '', 'synthetic;fixture', 'SYNTHETIC description, with a comma and "quotes".', 'Public', 'SYNTHETIC Basic', '1.00', '10.00', 'SYNTHETIC-RIGHTS-0001', '12'],
            ['bs-synthetic-0002', 'SYNTHETIC Track 02', '140', 'F# major', 'Synthetic', 'Calm', 'synthetic', "SYNTHETIC two-line\ndescription", 'Private', 'SYNTHETIC Premium', '2.50', '20.00', 'SYNTHETIC-RIGHTS-0002', '0'],
            ['bs-synthetic-0003', 'SYNTHETIC Track 03', '', '', 'Synthetic', '', '', '', 'Draft', 'SYNTHETIC Basic', '$3', '30.00', 'SYNTHETIC-RIGHTS-0003', '7'],
            ['bs-synthetic-0004', 'SYNTHETIC Track 04', '0088', 'A minor', 'Synthetic', 'Dark', 'synthetic; fixture ;dark', 'SYNTHETIC sold item', 'Sold', 'SYNTHETIC Premium', '4.99', '40.00', 'SYNTHETIC-RIGHTS-0004', '3'],
        ];
    }

    /** Distinct, clean synthetic rows with long descriptions, for the snapshot size limit. */
    private function largeRows(int $count): array
    {
        $rows = [];
        for ($index = 1; $index <= $count; $index++) {
            $rows[] = [sprintf('bs-synthetic-large-%04d', $index), sprintf('SYNTHETIC Large %04d', $index), '95', 'C minor', 'Synthetic', '',
                'synthetic', 'SYNTHETIC '.str_repeat('d', 4990), 'Public', 'SYNTHETIC Basic', '1.00', '10.00',
                sprintf('SYNTHETIC-RIGHTS-L%04d', $index), '0'];
        }

        return $rows;
    }

    private function normalize(array $rows, array $headers = self::HEADERS, array $overrides = []): array
    {
        return (new BeatStarsExportNormalizer)->normalize($this->csv($rows, $headers), 'export.csv', $this->mapping($overrides));
    }

    private function csv(array $rows, array $headers = self::HEADERS): string
    {
        $handle = fopen('php://temp', 'r+b');
        fputcsv($handle, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        rewind($handle);
        $bytes = stream_get_contents($handle);
        fclose($handle);

        return $bytes;
    }

    private function mappingArray(): array
    {
        return json_decode(file_get_contents(self::FIXTURES.'/mapping.json'), true, 16, JSON_THROW_ON_ERROR);
    }

    /** Overrides merge one level deep; a null value removes the key so refusals can test absence. */
    private function mapping(array $overrides = []): string
    {
        $mapping = $this->mappingArray();
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($mapping[$key]);
            } elseif (is_array($value) && isset($mapping[$key]) && is_array($mapping[$key]) && ! array_is_list($value) && ! array_is_list($mapping[$key])) {
                foreach ($value as $inner => $item) {
                    if ($item === null) {
                        unset($mapping[$key][$inner]);
                    } else {
                        $mapping[$key][$inner] = $item;
                    }
                }
            } else {
                $mapping[$key] = $value;
            }
        }

        return json_encode($mapping, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}
