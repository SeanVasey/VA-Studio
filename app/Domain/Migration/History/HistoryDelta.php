<?php

declare(strict_types=1);

namespace App\Domain\Migration\History;

use App\Domain\Migration\BeatStars\CellText;
use App\Support\CanonicalJson;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * Pure delta between a baseline history snapshot and a later snapshot of the same source system (runbook §7):
 * every new, changed and removed customer, order, line and contract, with the refunds and exclusive sales that
 * happened in between.
 *
 * A change the source can legitimately make after the baseline is captured automatically: a new record, a refund
 * (status moves forward and the refunded amount grows), a completed payment, restated raw cells or changed
 * customer contact details. Everything else (a removed record, a changed amount, track, license, buyer or date,
 * changed original contract bytes, a changed exclusive line other than its refund) is blocking until the operator
 * records an explicit disposition bound to both snapshot hashes. Any blocking item without one is unexplained, and
 * the delta does not reconcile.
 */
final class HistoryDelta
{
    public const PURPOSE = 'vasey-legacy-history-delta-v1';

    public const DISPOSITIONS_PURPOSE = 'vasey-legacy-history-delta-dispositions-v1';

    public const DISPOSITIONS = ['accepted_source_correction', 'retained_prior_record', 'excluded_with_owner_approval'];

    private const COHORTS = ['customers' => 'customer', 'orders' => 'order', 'lines' => 'line', 'contracts' => 'contract'];

    private const STATUS_FORWARD = [
        'unpaid' => ['paid'],
        'paid' => ['partially_refunded', 'refunded'],
        'partially_refunded' => ['refunded'],
    ];

    /** @return array<string, mixed> the deterministic delta report; `result` is `reconciled` only with zero unexplained items */
    public function compare(array $baseline, array $later, ?string $dispositionBytes = null): array
    {
        foreach ([$baseline, $later] as $snapshot) {
            $this->require(($snapshot['schema'] ?? null) === HistoryNormalizer::SCHEMA
                && ($snapshot['transform_version'] ?? null) === HistoryNormalizer::TRANSFORM, 'delta_snapshot');
        }
        $this->require($baseline['source_system'] === $later['source_system'] && $baseline['currency'] === $later['currency'], 'delta_source_system');
        $this->require($later['source_as_of'] > $baseline['source_as_of'], 'delta_watermark_order');
        $baselineHash = CanonicalJson::hash($baseline);
        $laterHash = CanonicalJson::hash($later);

        $items = [];
        $counts = [];
        foreach (self::COHORTS as $cohort => $singular) {
            $before = array_column($baseline[$cohort], null, 'record_key');
            $after = array_column($later[$cohort], null, 'record_key');
            $counts[$cohort] = ['baseline' => count($before), 'later' => count($after), 'new' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 0];
            foreach ($after as $key => $record) {
                if (! isset($before[$key])) {
                    $counts[$cohort]['new']++;
                    $items[] = $this->item($singular, (string) $key, 'new', 'new_record', false, []);
                } elseif ($before[$key]['record_sha256'] !== $record['record_sha256']) {
                    $counts[$cohort]['changed']++;
                    $fields = $this->changedFields($before[$key], $record);
                    [$category, $blocking] = $this->classify($singular, $before[$key], $record, $fields);
                    $items[] = $this->item($singular, (string) $key, 'changed', $category, $blocking, $fields);
                } else {
                    $counts[$cohort]['unchanged']++;
                }
            }
            foreach ($before as $key => $record) {
                if (! isset($after[$key])) {
                    $counts[$cohort]['removed']++;
                    $items[] = $this->item($singular, (string) $key, 'removed', 'record_removed', true, []);
                }
            }
        }
        usort($items, static fn (array $a, array $b): int => [$a['cohort'], $a['record_key']] <=> [$b['cohort'], $b['record_key']]);

        $dispositions = $dispositionBytes === null ? [] : $this->dispositions($dispositionBytes, $baselineHash, $laterHash, $items);
        $unexplained = 0;
        foreach ($items as &$item) {
            $disposition = $dispositions[$item['cohort'].':'.$item['record_key']] ?? null;
            $item['disposition'] = $disposition;
            if ($item['blocking'] && $disposition === null) {
                $unexplained++;
            }
        }
        unset($item);

        $baselineLines = array_column($baseline['lines'], null, 'record_key');
        $laterLines = array_column($later['lines'], null, 'record_key');
        $exclusives = ['new' => [], 'changed' => [], 'removed' => []];
        $refunds = [];
        foreach ($items as $item) {
            if ($item['cohort'] !== 'line') {
                continue;
            }
            $line = $laterLines[$item['record_key']] ?? $baselineLines[$item['record_key']];
            if ($line['exclusive'] || ($baselineLines[$item['record_key']]['exclusive'] ?? false)) {
                $exclusives[$item['change']][] = $item['record_key'];
            }
            if ($item['category'] === 'refund') {
                $refunds[] = ['record_key' => $item['record_key'],
                    'refunded_minor_delta' => $laterLines[$item['record_key']]['refunded_minor'] - $baselineLines[$item['record_key']]['refunded_minor']];
            }
        }
        $money = [];
        foreach (['baseline' => $baseline, 'later' => $later] as $side => $snapshot) {
            foreach ($snapshot['orders'] as $order) {
                $money[$order['currency']][$side.'_total_minor'] = ($money[$order['currency']][$side.'_total_minor'] ?? 0) + $order['total_minor'];
                $money[$order['currency']][$side.'_refunded_minor'] = ($money[$order['currency']][$side.'_refunded_minor'] ?? 0) + $order['refunded_minor'];
            }
        }
        foreach ($money as $currency => $totals) {
            $totals += ['baseline_total_minor' => 0, 'later_total_minor' => 0, 'baseline_refunded_minor' => 0, 'later_refunded_minor' => 0];
            $totals['total_delta_minor'] = $totals['later_total_minor'] - $totals['baseline_total_minor'];
            $totals['refunded_delta_minor'] = $totals['later_refunded_minor'] - $totals['baseline_refunded_minor'];
            ksort($totals, SORT_STRING);
            $money[$currency] = $totals;
        }
        ksort($money, SORT_STRING);

        $report = ['purpose' => self::PURPOSE, 'source_system' => $later['source_system'],
            'baseline' => ['snapshot_sha256' => $baselineHash, 'snapshot_id' => $baseline['snapshot_id'], 'source_as_of' => $baseline['source_as_of']],
            'later' => ['snapshot_sha256' => $laterHash, 'snapshot_id' => $later['snapshot_id'], 'source_as_of' => $later['source_as_of']],
            'counts' => $counts, 'items' => $items, 'exclusive_sales' => $exclusives, 'refunds' => $refunds, 'money' => $money,
            'dispositions' => count($dispositions), 'blocking' => count(array_filter($items, static fn (array $item): bool => $item['blocking'])),
            'unexplained' => $unexplained, 'result' => $unexplained === 0 ? 'reconciled' : 'unreconciled'];
        $report['delta_sha256'] = CanonicalJson::hash($report);

        return $report;
    }

    private function item(string $cohort, string $key, string $change, string $category, bool $blocking, array $fields): array
    {
        return ['cohort' => $cohort, 'record_key' => $key, 'change' => $change, 'category' => $category, 'blocking' => $blocking, 'fields' => $fields];
    }

    /** @return list<string> the top-level fields whose values differ, record hash excluded */
    private function changedFields(array $before, array $after): array
    {
        $fields = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            if ($field !== 'record_sha256' && ($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $fields[] = $field;
            }
        }
        sort($fields, SORT_STRING);

        return $fields;
    }

    /** @return array{0: string, 1: bool} category and whether it needs an explicit disposition */
    private function classify(string $cohort, array $before, array $after, array $fields): array
    {
        $only = static fn (array $allowed): bool => array_diff($fields, $allowed) === [];
        $restated = ['row', 'row_sha256'];

        return match ($cohort) {
            'customer' => $only([...$restated, 'email', 'email_normalized', 'display_name'])
                ? ['customer_details_changed', false] : ['customer_identity_changed', true],
            'order' => match (true) {
                $only(['status', 'refunded_minor']) && $this->forward($before['status'], $after['status'])
                    && $after['refunded_minor'] >= $before['refunded_minor'] => [$before['status'] === 'unpaid' ? 'payment_completed' : 'refund', false],
                default => ['order_terms_changed', true],
            },
            'line' => match (true) {
                $only($restated) => ['source_row_restated', false],
                $only([...$restated, 'refunded_minor']) && $after['refunded_minor'] > $before['refunded_minor'] => ['refund', false],
                default => [$before['exclusive'] || $after['exclusive'] ? 'exclusive_line_changed' : 'line_terms_changed', true],
            },
            'contract' => in_array('sha256', $fields, true) || in_array('bytes', $fields, true)
                ? ['contract_original_changed', true]
                : ($only($restated) || ($only([...$restated, 'declared_sha256']) && $before['declared_sha256'] === null)
                    ? ['contract_row_restated', false] : ['contract_link_changed', true]),
        };
    }

    private function forward(string $before, string $after): bool
    {
        return $before === $after || in_array($after, self::STATUS_FORWARD[$before] ?? [], true);
    }

    /** @return array<string, array{disposition: string, reason: string}> keyed `cohort:record_key` */
    private function dispositions(string $bytes, string $baselineHash, string $laterHash, array $items): array
    {
        $this->require(strlen($bytes) <= 1048576, 'dispositions_invalid');
        try {
            $decoded = json_decode($bytes, false, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('dispositions_invalid');
        }
        $this->require($decoded instanceof stdClass && $this->keys($decoded, ['schema_version', 'purpose', 'baseline_snapshot_sha256', 'later_snapshot_sha256', 'items'])
            && $decoded->schema_version === 1 && $decoded->purpose === self::DISPOSITIONS_PURPOSE && $decoded->items instanceof stdClass, 'dispositions_invalid');
        $this->require($decoded->baseline_snapshot_sha256 === $baselineHash && $decoded->later_snapshot_sha256 === $laterHash, 'dispositions_mismatch');
        $blocking = [];
        foreach ($items as $item) {
            if ($item['blocking']) {
                $blocking[$item['cohort'].':'.$item['record_key']] = true;
            }
        }
        $result = [];
        foreach (get_object_vars($decoded->items) as $key => $entry) {
            // A disposition explains one current blocking item; a stale or speculative one is refused, not ignored.
            $this->require(isset($blocking[$key]), 'dispositions_unknown_item');
            $this->require($entry instanceof stdClass && $this->keys($entry, ['disposition', 'reason'])
                && in_array($entry->disposition, self::DISPOSITIONS, true) && CellText::identity($entry->reason), 'dispositions_invalid');
            $result[(string) $key] = ['disposition' => $entry->disposition, 'reason' => $entry->reason];
        }

        return $result;
    }

    private function keys(stdClass $object, array $expected): bool
    {
        $keys = array_keys(get_object_vars($object));
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);

        return $keys === $expected;
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($code);
        }
    }
}
