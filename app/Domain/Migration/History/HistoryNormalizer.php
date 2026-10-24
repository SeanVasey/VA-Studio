<?php

declare(strict_types=1);

namespace App\Domain\Migration\History;

use App\Domain\Migration\BeatStars\BeatStarsExportSheet;
use App\Domain\Migration\BeatStars\CellText;
use App\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Pure, deterministic normalizer from one history source package (see HistorySourceFiles) to the
 * `vasey-legacy-history-v1` snapshot. It opens no database, writes nothing and never boots Laravel.
 *
 * Every value comes from the sheets, the contract files or the mapping. Nothing is defaulted: an undeclared
 * column, an unknown status or license, an unparseable amount, a broken reference or a contract whose bytes do
 * not match its declared hash is a finding, and any finding withholds the whole snapshot. Facts that are true of
 * the source but stop a historical grant from being proven (a missing original contract, an ambiguous buyer, an
 * exclusive sold twice) are recorded exceptions; they never withhold, rewrite or revoke a record.
 *
 * Every line is `archive_only`. `eligibility` only reports whether the archived evidence could support a later,
 * separately reviewed `verified_grant`; it grants nothing. Marketing consent is `unknown` on every customer.
 */
final class HistoryNormalizer
{
    public const SCHEMA = 'vasey-legacy-history-v1';

    public const TRANSFORM = 'vasey-legacy-history-transform-v1';

    public const CLASSIFICATION = 'archive_only';

    public const CONSENT = 'unknown';

    private const IDENTIFIER = '/\A[A-Za-z0-9][A-Za-z0-9._:\/#-]{0,189}\z/D';

    private const FILE_NAME = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,199}\z/D';

    private const MONEY = '/\A\$?(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/D';

    /** @var list<array{scope: string, sheet: string, row: ?int, source_id: ?string, code: string, column: ?string}> */
    private array $findings = [];

    /** @var list<array{cohort: string, record_key: string, code: string}> */
    private array $exceptions = [];

    /**
     * @param  array{mapping: string, sheets: array<string, string>, contracts: array<string, string>}  $source
     * @return array{counts: array<string, int>, findings: list<array>, snapshot: ?array, snapshot_sha256: ?string, mapping: array}
     */
    public function normalize(array $source): array
    {
        $this->findings = [];
        $this->exceptions = [];
        $mapping = (new HistoryMapping)->decode($source['mapping']);
        $synthetic = $mapping['acquisition_method'] === 'synthetic_fixture';
        $sheets = [];
        $inputs = ['mapping_sha256' => hash('sha256', $source['mapping']), 'sheets' => []];
        foreach (HistorySourceFiles::SHEETS as $sheet => $name) {
            try {
                $parsed = (new BeatStarsExportSheet)->read($source['sheets'][$sheet]);
            } catch (InvalidArgumentException $error) {
                throw new InvalidArgumentException($sheet.'_'.preg_replace('/\Aexport_/', '', $error->getMessage()));
            }
            $sheets[$sheet] = $this->rows($sheet, $parsed, $mapping[$sheet]);
            $inputs['sheets'][$sheet] = ['name' => $name, 'sha256' => hash('sha256', $source['sheets'][$sheet]),
                'bytes' => strlen($source['sheets'][$sheet]), 'records' => count($parsed['records'])];
        }
        $inputs['contract_files'] = ['count' => count($source['contracts']),
            'bytes' => array_sum(array_map('strlen', $source['contracts']))];

        $customers = $this->customers($sheets['customers'], $mapping, $synthetic);
        [$orders, $lines] = $this->orders($sheets['orders'], $mapping, $synthetic, $customers);
        $contracts = $this->contracts($sheets['contracts'], $mapping, $synthetic, $lines, $source['contracts']);
        $eligibility = $this->eligibility($customers, $orders, $lines, $contracts);

        $counts = ['customers' => count($customers), 'orders' => count($orders), 'lines' => count($lines),
            'contracts' => count($contracts), 'contract_files' => count($source['contracts']),
            'findings' => count($this->findings), 'exceptions' => count($this->exceptions)];
        $findings = $this->findings;
        usort($findings, static fn (array $a, array $b): int => [array_search($a['sheet'], array_keys(HistorySourceFiles::SHEETS), true), $a['row'] ?? 0, $a['code'], $a['column'] ?? '']
            <=> [array_search($b['sheet'], array_keys(HistorySourceFiles::SHEETS), true), $b['row'] ?? 0, $b['code'], $b['column'] ?? '']);
        $snapshot = null;
        if ($findings === []) {
            $exceptions = $this->exceptions;
            usort($exceptions, static fn (array $a, array $b): int => [$a['cohort'], $a['record_key'], $a['code']] <=> [$b['cohort'], $b['record_key'], $b['code']]);
            $snapshot = ['schema' => self::SCHEMA, 'transform_version' => self::TRANSFORM,
                'canonicalization_version' => CanonicalJson::VERSION, 'snapshot_id' => $mapping['snapshot_id'],
                'source_system' => $mapping['source_system'], 'operator_reference' => $mapping['operator_reference'],
                'acquisition_method' => $mapping['acquisition_method'], 'acquired_at' => $mapping['acquired_at'],
                'source_as_of' => $mapping['source_as_of'], 'currency' => $mapping['currency'], 'inputs' => $inputs,
                'customers' => array_values($customers), 'orders' => array_values($orders), 'lines' => array_values($lines),
                'contracts' => array_values($contracts), 'exceptions' => array_values(array_unique($exceptions, SORT_REGULAR)),
                'eligibility' => $eligibility];
            $counts['exceptions'] = count($snapshot['exceptions']);
        }

        return ['counts' => $counts, 'findings' => $findings, 'snapshot' => $snapshot,
            'snapshot_sha256' => $snapshot === null ? null : CanonicalJson::hash($snapshot), 'mapping' => $mapping];
    }

    /** Stable identity of one source record across snapshots of the same source system. */
    public static function recordKey(string $cohort, string $sourceSystem, string ...$sourceIds): string
    {
        return hash('sha256', self::SCHEMA."\n".$cohort."\n".$sourceSystem."\n".implode("\n", $sourceIds));
    }

    /** The record hash binds every field except the hash itself. */
    public static function recordHash(array $record): string
    {
        unset($record['record_sha256']);

        return CanonicalJson::hash($record);
    }

    /**
     * Align each record with its header and apply the sheet-level checks. A header that is neither mapped nor
     * ignored, a mapped header the sheet lacks, or a blank line means the sheet is not understood: every row of
     * that sheet is withheld by the resulting finding.
     *
     * @return list<array{row: int, cells: array<string, string>, row_sha256: string}>
     */
    private function rows(string $sheet, array $parsed, array $definition): array
    {
        $known = [...array_values($definition['columns']), ...$definition['ignored_columns']];
        foreach ($parsed['headers'] as $header) {
            if (! in_array($header, $known, true)) {
                $this->finding('sheet', $sheet, null, null, 'unmapped_column', $header);
            }
        }
        $missing = false;
        foreach ($definition['columns'] as $header) {
            if (! in_array($header, $parsed['headers'], true)) {
                $this->finding('sheet', $sheet, null, null, 'mapped_column_missing', $header);
                $missing = true;
            }
        }
        if ($missing) {
            // A mapped field the sheet lacks cannot be read from any row; the finding already withholds the sheet.
            return [];
        }
        $rows = [];
        foreach ($parsed['records'] as $index => $record) {
            $row = $index + 2;
            if ($record === null) {
                $this->finding('sheet', $sheet, $row, null, 'blank_record', null);

                continue;
            }
            if (count($record) !== count($parsed['headers'])) {
                $this->finding('row', $sheet, $row, null, 'column_count_mismatch', null);

                continue;
            }
            $cells = array_combine($parsed['headers'], $record);
            $mapped = [];
            foreach ($definition['columns'] as $field => $header) {
                $mapped[$field] = $cells[$header] ?? null;
            }
            // The row hash covers every raw cell, ignored ones included, without retaining an ignored value.
            $rows[] = ['row' => $row, 'cells' => $mapped, 'row_sha256' => CanonicalJson::hash($cells)];
        }

        return $rows;
    }

    private function customers(array $rows, array $mapping, bool $synthetic): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $id = $row['cells']['source_customer_id'];
            if (! $this->identifier($id, $synthetic)) {
                $this->finding('row', 'customers', $row['row'], null, $this->identifierCode($id, $synthetic), 'source_customer_id');

                continue;
            }
            $byId[$id][] = $row;
        }
        $customers = [];
        $emails = [];
        foreach ($byId as $id => $group) {
            if (count($group) > 1) {
                foreach ($group as $row) {
                    $this->finding('row', 'customers', $row['row'], (string) $id, 'duplicate_source_id', 'source_customer_id');
                }

                continue;
            }
            $row = $group[0];
            $email = $row['cells']['email'];
            $valid = CellText::line($email, 254) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            if (! $valid) {
                $this->finding('row', 'customers', $row['row'], (string) $id, 'invalid_email', 'email');

                continue;
            }
            $normalized = strtolower($email);
            if ($synthetic && ! str_ends_with($normalized, '.invalid')) {
                $this->finding('row', 'customers', $row['row'], (string) $id, 'synthetic_identity_required', 'email');

                continue;
            }
            $name = $row['cells']['display_name'] ?? null;
            $name = $name === null ? null : trim($name);
            if ($name === '') {
                $name = null;
            }
            if ($name !== null && ! CellText::line($name, 190)) {
                $this->finding('row', 'customers', $row['row'], (string) $id, 'invalid_display_name', 'display_name');

                continue;
            }
            $key = self::recordKey('customer', $mapping['source_system'], (string) $id);
            $record = ['record_key' => $key, 'source_customer_id' => (string) $id, 'row' => $row['row'], 'row_sha256' => $row['row_sha256'],
                'email' => $email, 'email_normalized' => $normalized, 'display_name' => $name, 'marketing_consent' => self::CONSENT];
            $record['record_sha256'] = self::recordHash($record);
            $customers[$key] = $record;
            $emails[$normalized][] = $key;
        }
        // Two source customers sharing an address are kept apart; matching them to anyone needs manual resolution.
        foreach ($emails as $keys) {
            if (count($keys) > 1) {
                foreach ($keys as $key) {
                    $this->exception('customer', $key, 'ambiguous_customer_identity');
                }
            }
        }
        ksort($customers, SORT_STRING);

        return $customers;
    }

    /** @return array{0: array<string, array>, 1: array<string, array>} orders and lines keyed by record key */
    private function orders(array $rows, array $mapping, bool $synthetic, array $customers): array
    {
        $customerKeys = [];
        foreach ($customers as $customer) {
            $customerKeys[$customer['source_customer_id']] = $customer['record_key'];
        }
        $parsed = [];
        $seen = [];
        foreach ($rows as $row) {
            $c = $row['cells'];
            $orderId = $c['source_order_id'];
            $lineId = $c['source_line_id'];
            $ok = true;
            // A line id only has to be unique within its order, so it carries no synthetic namespace of its own.
            foreach (['source_order_id' => [$orderId, $synthetic], 'source_line_id' => [$lineId, false],
                'track_source_id' => [$c['track_source_id'], $synthetic]] as $field => [$value, $namespaced]) {
                if (! $this->identifier($value, $namespaced)) {
                    $this->finding('row', 'orders', $row['row'], is_string($orderId) ? CellText::detail($orderId) : null, $this->identifierCode($value, $namespaced), $field);
                    $ok = false;
                }
            }
            if (! $ok) {
                continue;
            }
            $seen[$orderId."\n".$lineId][] = $row['row'];
            $line = ['row' => $row, 'order' => $orderId, 'line' => $lineId];
            if (! array_key_exists($c['source_customer_id'], $customerKeys)) {
                $this->finding('row', 'orders', $row['row'], $orderId, 'unknown_customer_reference', 'source_customer_id');
                $ok = false;
            }
            if (! HistoryMapping::timestamp($c['ordered_at'])) {
                $this->finding('row', 'orders', $row['row'], $orderId, 'invalid_ordered_at', 'ordered_at');
                $ok = false;
            } elseif ($c['ordered_at'] > $mapping['source_as_of']) {
                $this->finding('row', 'orders', $row['row'], $orderId, 'ordered_after_watermark', 'ordered_at');
                $ok = false;
            }
            $status = $mapping['orders']['status_values'][$c['status']] ?? null;
            if ($status === null) {
                $this->finding('row', 'orders', $row['row'], $orderId, 'unknown_status_value', 'status');
                $ok = false;
            }
            $license = $mapping['licenses'][$c['license']] ?? null;
            if ($license === null) {
                $this->finding('row', 'orders', $row['row'], $orderId, 'unknown_license_name', 'license');
                $ok = false;
            }
            $amount = $this->money($c['amount']);
            $refunded = $this->money($c['refunded_amount']);
            if ($amount === null) {
                $this->finding('row', 'orders', $row['row'], $orderId, 'invalid_amount', 'amount');
                $ok = false;
            }
            if ($refunded === null) {
                $this->finding('row', 'orders', $row['row'], $orderId, 'invalid_refunded_amount', 'refunded_amount');
                $ok = false;
            }
            if ($amount !== null && $refunded !== null && $refunded > $amount) {
                $this->finding('row', 'orders', $row['row'], $orderId, 'refund_exceeds_amount', 'refunded_amount');
                $ok = false;
            }
            if (array_key_exists('currency', $c) && $c['currency'] !== $mapping['currency']) {
                $this->finding('row', 'orders', $row['row'], $orderId, 'currency_mismatch', 'currency');
                $ok = false;
            }
            $reference = $c['payment_reference'] ?? '';
            if ($reference !== '' && ! (preg_match(self::IDENTIFIER, $reference) === 1)) {
                $this->finding('row', 'orders', $row['row'], $orderId, 'invalid_payment_reference', 'payment_reference');
                $ok = false;
            }
            if ($ok) {
                $parsed[] = $line + ['customer' => $c['source_customer_id'], 'ordered_at' => $c['ordered_at'], 'status' => $status,
                    'license_name' => $c['license'], 'license' => $license, 'amount' => $amount, 'refunded' => $refunded,
                    'payment_reference' => $reference === '' ? null : $reference];
            } else {
                $parsed[] = $line + ['invalid' => true];
            }
        }
        $duplicates = array_filter($seen, static fn (array $found): bool => count($found) > 1);
        $byOrder = [];
        foreach ($parsed as $line) {
            if (isset($duplicates[$line['order']."\n".$line['line']])) {
                $this->finding('row', 'orders', $line['row']['row'], $line['order'], 'duplicate_source_id', 'source_line_id');

                continue;
            }
            $byOrder[$line['order']][] = $line;
        }
        $orders = [];
        $lines = [];
        foreach ($byOrder as $orderId => $group) {
            $orderId = (string) $orderId;
            if (array_any($group, static fn (array $line): bool => isset($line['invalid']))) {
                continue;
            }
            $first = $group[0];
            $consistent = array_all($group, static fn (array $line): bool => $line['customer'] === $first['customer']
                && $line['ordered_at'] === $first['ordered_at'] && $line['status'] === $first['status']
                && $line['payment_reference'] === $first['payment_reference']);
            if (! $consistent) {
                foreach ($group as $line) {
                    $this->finding('row', 'orders', $line['row']['row'], $orderId, 'order_inconsistent', null);
                }

                continue;
            }
            $total = array_sum(array_column($group, 'amount'));
            $refunded = array_sum(array_column($group, 'refunded'));
            $statusFits = match ($first['status']) {
                'paid', 'unpaid' => $refunded === 0,
                'refunded' => $refunded === $total,
                'partially_refunded' => $refunded > 0 && $refunded < $total,
                default => true,
            };
            if (! $statusFits) {
                foreach ($group as $line) {
                    $this->finding('row', 'orders', $line['row']['row'], $orderId, 'refund_status_mismatch', 'refunded_amount');
                }

                continue;
            }
            $orderKey = self::recordKey('order', $mapping['source_system'], $orderId);
            $lineKeys = [];
            foreach ($group as $line) {
                $lineKey = self::recordKey('line', $mapping['source_system'], $orderId, (string) $line['line']);
                $lineKeys[] = $lineKey;
                $record = ['record_key' => $lineKey, 'source_order_id' => $orderId, 'source_line_id' => (string) $line['line'],
                    'order_record_key' => $orderKey, 'row' => $line['row']['row'], 'row_sha256' => $line['row']['row_sha256'],
                    'track_source_id' => (string) $line['row']['cells']['track_source_id'], 'license_name' => $line['license_name'],
                    'license_label' => $line['license']['label'], 'exclusive' => $line['license']['exclusive'],
                    'amount_minor' => $line['amount'], 'refunded_minor' => $line['refunded'], 'currency' => $mapping['currency'],
                    'classification' => self::CLASSIFICATION];
                $record['record_sha256'] = self::recordHash($record);
                $lines[$lineKey] = $record;
            }
            sort($lineKeys, SORT_STRING);
            $record = ['record_key' => $orderKey, 'source_order_id' => $orderId, 'source_customer_id' => $first['customer'],
                'customer_record_key' => $customerKeys[$first['customer']], 'ordered_at' => $first['ordered_at'],
                'status' => $first['status'], 'currency' => $mapping['currency'], 'total_minor' => $total,
                'refunded_minor' => $refunded, 'payment_reference' => $first['payment_reference'], 'line_record_keys' => $lineKeys];
            $record['record_sha256'] = self::recordHash($record);
            $orders[$orderKey] = $record;
        }
        // An exclusive sold more than once (not fully refunded) is preserved for every buyer and flagged; never revoked.
        $exclusive = [];
        foreach ($lines as $line) {
            if ($line['exclusive'] && in_array($orders[$line['order_record_key']]['status'], ['paid', 'partially_refunded', 'unknown'], true)
                && $line['refunded_minor'] < $line['amount_minor']) {
                $exclusive[$line['track_source_id']][] = $line['record_key'];
            }
        }
        foreach ($exclusive as $keys) {
            if (count($keys) > 1) {
                foreach ($keys as $key) {
                    $this->exception('line', $key, 'duplicate_exclusive_sale');
                }
            }
        }
        ksort($orders, SORT_STRING);
        ksort($lines, SORT_STRING);

        return [$orders, $lines];
    }

    private function contracts(array $rows, array $mapping, bool $synthetic, array $lines, array $files): array
    {
        $lineKeys = [];
        foreach ($lines as $line) {
            $lineKeys[$line['source_order_id']."\n".$line['source_line_id']] = $line;
        }
        $byId = [];
        $byFile = [];
        foreach ($rows as $row) {
            $c = $row['cells'];
            $id = $c['source_contract_id'];
            if (! $this->identifier($id, $synthetic)) {
                $this->finding('row', 'contracts', $row['row'], null, $this->identifierCode($id, $synthetic), 'source_contract_id');

                continue;
            }
            $byId[$id][] = $row;
            if (is_string($c['file_name'])) {
                $byFile[$c['file_name']][] = $row['row'];
            }
        }
        $contracts = [];
        $referenced = [];
        foreach ($byId as $id => $group) {
            $id = (string) $id;
            foreach ($group as $row) {
                if (is_string($row['cells']['file_name'])) {
                    $referenced[$row['cells']['file_name']] = true;
                }
            }
            if (count($group) > 1) {
                foreach ($group as $row) {
                    $this->finding('row', 'contracts', $row['row'], $id, 'duplicate_source_id', 'source_contract_id');
                }

                continue;
            }
            $row = $group[0];
            $c = $row['cells'];
            $line = $lineKeys[$c['source_order_id']."\n".$c['source_line_id']] ?? null;
            $ok = true;
            if ($line === null) {
                // A reference to a withheld or absent line is still a finding: the contract cannot be linked exactly.
                $this->finding('row', 'contracts', $row['row'], $id, 'unknown_line_reference', 'source_line_id');
                $ok = false;
            }
            $name = $c['file_name'];
            $extension = is_string($name) && preg_match(self::FILE_NAME, $name) === 1 && str_contains($name, '.')
                ? strtolower(substr($name, strrpos($name, '.') + 1)) : null;
            if ($extension === null) {
                $this->finding('row', 'contracts', $row['row'], $id, 'invalid_file_name', 'file_name');
                $ok = false;
            } elseif (! isset($mapping['contracts']['media_types'][$extension])) {
                $this->finding('row', 'contracts', $row['row'], $id, 'unknown_media_type', 'file_name');
                $ok = false;
            }
            if (count($byFile[$name] ?? []) > 1) {
                $this->finding('row', 'contracts', $row['row'], $id, 'duplicate_contract_file', 'file_name');
                $ok = false;
            }
            $declared = $c['declared_sha256'] ?? null;
            if ($declared === '') {
                $declared = null;
            }
            if ($declared !== null && preg_match('/\A[a-f0-9]{64}\z/D', $declared) !== 1) {
                $this->finding('row', 'contracts', $row['row'], $id, 'invalid_declared_sha256', 'declared_sha256');
                $ok = false;
            }
            $bytes = is_string($name) ? ($files[$name] ?? null) : null;
            if ($ok && $bytes !== null && $declared !== null && ! hash_equals($declared, hash('sha256', $bytes))) {
                // Tampered or substituted original: the archive never accepts bytes that differ from the declared hash.
                $this->finding('row', 'contracts', $row['row'], $id, 'contract_hash_mismatch', 'declared_sha256');
                $ok = false;
            }
            if (! $ok) {
                continue;
            }
            $key = self::recordKey('contract', $mapping['source_system'], $id);
            if ($bytes === null) {
                // A declared original that was not supplied is an exception; no contract text is ever produced for it.
                $this->exception('line', $line['record_key'], 'contract_original_missing');

                continue;
            }
            $record = ['record_key' => $key, 'source_contract_id' => $id, 'source_order_id' => $line['source_order_id'],
                'source_line_id' => $line['source_line_id'], 'order_record_key' => $line['order_record_key'],
                'line_record_key' => $line['record_key'], 'row' => $row['row'], 'row_sha256' => $row['row_sha256'],
                'file_name' => $name, 'media_type' => $mapping['contracts']['media_types'][$extension],
                'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'declared_sha256' => $declared];
            $record['record_sha256'] = self::recordHash($record);
            $contracts[$key] = $record;
        }
        foreach (array_keys($files) as $file) {
            if (! isset($referenced[$file])) {
                $this->finding('sheet', 'contracts', null, null, 'unreferenced_contract_file', CellText::detail((string) $file));
            }
        }
        ksort($contracts, SORT_STRING);

        return $contracts;
    }

    /**
     * What a later, separately reviewed `verified_grant` would need from this archive: a paid, unrefunded order,
     * an unambiguous buyer, exactly one original contract for the line, a payment reference and no competing
     * exclusive sale. Reported per line; nothing here grants, activates or classifies.
     */
    private function eligibility(array $customers, array $orders, array $lines, array $contracts): array
    {
        $contractsByLine = [];
        foreach ($contracts as $contract) {
            $contractsByLine[$contract['line_record_key']][] = $contract['record_key'];
        }
        $flags = [];
        foreach ($this->exceptions as $exception) {
            $flags[$exception['cohort']][$exception['record_key']][] = $exception['code'];
        }
        $result = [];
        foreach ($lines as $key => $line) {
            $order = $orders[$line['order_record_key']];
            $blockers = [];
            if ($order['status'] !== 'paid') {
                $blockers[] = 'order_not_paid';
            }
            if ($line['refunded_minor'] !== 0) {
                $blockers[] = 'line_refunded';
            }
            if ($order['payment_reference'] === null) {
                $blockers[] = 'payment_reference_missing';
            }
            if (in_array('ambiguous_customer_identity', $flags['customer'][$order['customer_record_key']] ?? [], true)) {
                $blockers[] = 'ambiguous_customer_identity';
            }
            foreach (['contract_original_missing', 'duplicate_exclusive_sale'] as $code) {
                if (in_array($code, $flags['line'][$key] ?? [], true)) {
                    $blockers[] = $code;
                }
            }
            $count = count($contractsByLine[$key] ?? []);
            if ($count === 0 && ! in_array('contract_original_missing', $blockers, true)) {
                $blockers[] = 'contract_absent';
                $this->exception('line', $key, 'line_without_contract');
            } elseif ($count > 1) {
                $blockers[] = 'multiple_contracts';
            }
            sort($blockers, SORT_STRING);
            $result[$key] = ['eligible' => $blockers === [], 'blockers' => $blockers,
                'contract_record_key' => $count === 1 ? $contractsByLine[$key][0] : null];
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private function identifier(mixed $value, bool $synthetic): bool
    {
        return is_string($value) && preg_match(self::IDENTIFIER, $value) === 1 && (! $synthetic || str_starts_with($value, 'synthetic-'));
    }

    private function identifierCode(mixed $value, bool $synthetic): string
    {
        if (! is_string($value) || $value === '') {
            return 'missing_source_id';
        }

        return preg_match(self::IDENTIFIER, $value) === 1 && $synthetic ? 'synthetic_identity_required' : 'invalid_source_id';
    }

    /** USD only: `12`, `12.5`, `12.50`, `$12.50` become 1200, 1250, 1250, 1250 cents; anything else is not money. */
    private function money(mixed $value): ?int
    {
        if (! is_string($value) || preg_match(self::MONEY, $value, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '0', 2, '0');
    }

    private function finding(string $scope, string $sheet, ?int $row, ?string $sourceId, string $code, ?string $column): void
    {
        $this->findings[] = ['scope' => $scope, 'sheet' => $sheet, 'row' => $row,
            'source_id' => $sourceId === null ? null : CellText::detail($sourceId), 'code' => $code, 'column' => $column];
    }

    private function exception(string $cohort, string $recordKey, string $code): void
    {
        $this->exceptions[] = ['cohort' => $cohort, 'record_key' => $recordKey, 'code' => $code];
    }
}
