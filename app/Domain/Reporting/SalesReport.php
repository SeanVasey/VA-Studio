<?php

namespace App\Domain\Reporting;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\RefundDispute\RefundDisputeCase;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use App\Support\Environment\TestEnvironment;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only sales, refund and conversion figures for staff. Integer minor units grouped by explicit currency, UTC periods.
 *
 * Two sources are reported separately and never summed: the retained test commerce chain (labelled TEST) and the
 * production checkout chain, split by its funds mode. Refunds come only from the A5 ledger and are status-aware: an order's
 * refunded amount is the latest accepted observation's succeeded total (a refund that succeeded and later failed stops
 * counting), and per-tier refunds use RefundDisputeCase::refundedByLine(), never a raw sum of retained allocations.
 * Refunds are netted against the orders paid in the period, not dated by when the refund happened.
 */
final class SalesReport
{
    public const GRANULARITIES = ['day', 'month'];

    public const ENVIRONMENTS = [
        'test' => 'TEST (synthetic, Stripe test mode)',
        'live' => 'PRODUCTION (live funds)',
        'rehearsal' => 'PRODUCTION CHAIN (test funds, rehearsal)',
    ];

    private const NOTES = [
        'Periods are UTC calendar days or months.',
        'Gross is the verified payment amount including any tax. Net is gross minus succeeded refunds.',
        'Test and production figures are never summed. Test figures are synthetic and are not revenue.',
        'Paid exceptions are payments received without a license being granted; they are reported separately and are not in net.',
        'The funnel counts events in the period, not a cohort, and applies no bot or replay filter: quotes include anonymous visitors.',
        'Membership billing, services, merchandise and the tax-calculated production checkout are not part of this report.',
    ];

    public function __construct(private readonly StaffReportingAuthority $authority) {}

    /** @return array{from: string, to: string} the last 30 UTC days ending today */
    public static function defaultRange(): array
    {
        $today = now()->utc();

        return ['from' => $today->subDays(29)->format('Y-m-d'), 'to' => $today->format('Y-m-d')];
    }

    /** @return array{from: string, to: string, granularity: string, days: int, start: string, end: string, startIso: string, endIso: string} */
    public function period(string $from, string $to, string $granularity): array
    {
        $parse = function (string $value): DateTimeImmutable {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
            ReportingException::require($date !== false && $date->format('Y-m-d') === $value, 'invalid_period');

            return $date;
        };
        $start = $parse($from);
        $last = $parse($to);
        ReportingException::require($start <= $last, 'period_order');
        $days = (int) $start->diff($last)->days + 1;
        ReportingException::require($days <= (int) config('reporting.sales.max_days'), 'period_too_long');
        ReportingException::require(in_array($granularity, self::GRANULARITIES, true), 'granularity');
        ReportingException::require($granularity !== 'day' || $days <= (int) config('reporting.sales.day_buckets_max_days'), 'day_buckets_too_many');
        $end = $last->modify('+1 day');

        return ['from' => $from, 'to' => $to, 'granularity' => $granularity, 'days' => $days,
            'start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s'),
            'startIso' => $start->format('Y-m-d\T00:00:00\Z'), 'endIso' => $end->format('Y-m-d\T00:00:00\Z')];
    }

    public function build(?User $actor, string $from, string $to, string $granularity): array
    {
        $this->authority->staff($actor);
        $period = $this->period($from, $to, $granularity);

        return ['period' => $period, 'test' => $this->test($period), 'production' => $this->production($period), 'notes' => self::NOTES];
    }

    /** Audited CSV export; the audit row is written before the file is returned and a refusal writes nothing. */
    public function export(?User $actor, string $from, string $to, string $granularity): array
    {
        $user = $this->authority->exporter($actor);
        $report = $this->build($user, $from, $to, $granularity);
        $rows = $this->csvRows($report);
        ReportingException::require(count($rows) <= (int) config('reporting.sales.export_row_cap'), 'too_large');
        $csv = ReportingCsv::render(['environment', 'record', 'period', 'currency', 'tier', 'tier_type', 'count', 'gross_minor', 'discount_minor',
            'tax_minor', 'refunded_minor', 'net_minor', 'note'], $rows);
        $sha256 = hash('sha256', $csv);
        AuditEvent::recordAttributed('reporting.sales_exported', $user, ['from' => $from, 'to' => $to, 'granularity' => $granularity,
            'rows' => count($rows), 'sha256' => $sha256, 'sections' => ['test' => $report['test']['available'], 'production' => $report['production']['available']]], $user->id);

        return ['filename' => 'sales-report-'.$from.'-to-'.$to.'.csv', 'csv' => $csv, 'rows' => count($rows), 'sha256' => $sha256];
    }

    /** @return list<list<int|string|null>> */
    public function csvRows(array $report): array
    {
        $rows = [];
        $test = $report['test'];
        if ($test['available']) {
            foreach ($test['periods'] as $row) {
                $rows[] = [self::ENVIRONMENTS['test'], 'period', $row['period'], $row['currency'], null, null, $row['orders'], $row['gross_minor'], null, null,
                    $row['refunded_minor'], $row['net_minor'], 'paid orders; refunds netted against orders paid in the bucket'];
                if ($row['exception_orders'] > 0) {
                    $rows[] = [self::ENVIRONMENTS['test'], 'paid_exception', $row['period'], $row['currency'], null, null, $row['exception_orders'],
                        $row['exception_gross_minor'], null, null, null, null, 'payment received, no license granted; not in net'];
                }
            }
            if ($test['tiers']['available']) {
                foreach ($test['tiers']['rows'] as $row) {
                    $rows[] = [self::ENVIRONMENTS['test'], 'tier', null, $row['currency'], $row['tier'], $row['tier_type'], $row['lines'], $row['paid_minor'],
                        $row['discount_minor'], $row['tax_minor'], $row['refunded_minor'], $row['net_minor'], 'paid is line total including tax'];
                }
                foreach ($test['tiers']['unattributed_refund_minor'] as $currency => $amount) {
                    $rows[] = [self::ENVIRONMENTS['test'], 'unattributed_refund', null, $currency, null, null, null, null, null, null, $amount, null,
                        'succeeded refund not allocated to a tier line'];
                }
            }
            foreach ($test['funnel']['counts'] as $metric => $count) {
                $rows[] = [self::ENVIRONMENTS['test'], 'funnel', null, null, $metric, null, $count, null, null, null, null, null, null];
            }
        }
        $production = $report['production'];
        if ($production['available']) {
            foreach ($production['modes'] as $mode => $section) {
                $label = self::ENVIRONMENTS[$mode];
                foreach ($section['periods'] as $row) {
                    $rows[] = [$label, 'period', $row['period'], 'USD', null, null, $row['orders'], $row['gross_minor'], null, null, null, null,
                        'refunds are not recorded for production orders'];
                    if ($row['exception_orders'] > 0) {
                        $rows[] = [$label, 'paid_exception', $row['period'], 'USD', null, null, $row['exception_orders'], $row['exception_gross_minor'], null, null, null, null,
                            'payment received after expiry; not in gross'];
                    }
                }
                foreach ($section['tiers'] as $row) {
                    $rows[] = [$label, 'tier', null, 'USD', $row['tier'], $row['tier_type'], $row['lines'], $row['gross_minor'], null, null, null, null, 'on-time paid lines'];
                }
            }
            foreach ($production['funnel'] as $metric => $count) {
                $rows[] = ['PRODUCTION CHAIN (all funds modes)', 'funnel', null, null, $metric, null, $count, null, null, null, null, null, null];
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function test(array $period): array
    {
        $empty = ['available' => false, 'label' => self::ENVIRONMENTS['test'], 'reason' => null, 'periods' => [], 'currencies' => [],
            'tiers' => ['available' => false, 'reason' => null, 'rows' => [], 'unattributed_refund_minor' => [], 'unverified_orders' => 0, 'unreadable_refund_orders' => 0],
            'funnel' => ['counts' => [], 'conversion_bps' => null]];
        if (! TestEnvironment::admitsTestCommerce()) {
            return ['reason' => 'Test commerce is not admitted in this environment.'] + $empty;
        }
        $account = ReportingQueries::testAccount();
        if ($account === null) {
            return ['reason' => 'The Stripe test account is not configured, so no test order is in scope.'] + $empty;
        }
        try {
            $periods = $this->testPeriods($account, $period);
            $currencies = [];
            foreach ($periods as $row) {
                $total = &$currencies[$row['currency']];
                $total ??= ['orders' => 0, 'gross_minor' => 0, 'refunded_minor' => 0, 'net_minor' => 0, 'exception_orders' => 0, 'exception_gross_minor' => 0];
                foreach ($total as $key => $value) {
                    $total[$key] = $value + $row[$key];
                }
                unset($total);
            }
            ksort($currencies);
            $counts = [
                'quotes_created' => DB::table('quotes')->where('created_at', '>=', $period['start'])->where('created_at', '<', $period['end'])->count(),
                'orders_prepared' => DB::table('orders')->where('created_at', '>=', $period['start'])->where('created_at', '<', $period['end'])->count(),
                'paid' => array_sum(array_column($periods, 'orders')),
                'paid_exception' => array_sum(array_column($periods, 'exception_orders')),
                'released_unpaid' => DB::table('test_unpaid_releases')->where('released_at', '>=', $period['start'])->where('released_at', '<', $period['end'])->count(),
            ];

            return ['available' => true, 'label' => self::ENVIRONMENTS['test'], 'reason' => null, 'periods' => $periods, 'currencies' => $currencies,
                'tiers' => $this->testTiers($account, $period),
                'funnel' => ['counts' => $counts, 'conversion_bps' => $counts['orders_prepared'] > 0 ? intdiv($counts['paid'] * 10000, $counts['orders_prepared']) : null]];
        } catch (Throwable $error) {
            report($error);

            return ['reason' => 'The test commerce data could not be read.'] + $empty;
        }
    }

    /** One row per bucket and currency. Only grouped columns and aggregates are selected (MySQL ONLY_FULL_GROUP_BY). */
    private function testPeriods(string $account, array $period): array
    {
        $bucket = self::bucket('f.finalized_at', $period['granularity']);
        $rows = $this->testScope($account, $period, ['paid', 'paid_exception'])->leftJoin('refund_dispute_observations as r', ReportingQueries::latestObserved(...))
            ->selectRaw($bucket.' AS period, p.currency AS currency,'
                ." COUNT(CASE WHEN f.outcome = 'paid' THEN 1 END) AS orders,"
                ." COALESCE(SUM(CASE WHEN f.outcome = 'paid' THEN p.amount_minor END), 0) AS gross_minor,"
                ." COALESCE(SUM(CASE WHEN f.outcome = 'paid' THEN r.succeeded_refund_minor END), 0) AS refunded_minor,"
                ." COUNT(CASE WHEN f.outcome = 'paid_exception' THEN 1 END) AS exception_orders,"
                ." COALESCE(SUM(CASE WHEN f.outcome = 'paid_exception' THEN p.amount_minor END), 0) AS exception_gross_minor")
            ->groupBy('period', 'p.currency')->orderBy('period')->orderBy('p.currency')->get();

        return $rows->map(fn ($row): array => ['period' => (string) $row->period, 'currency' => (string) $row->currency, 'orders' => (int) $row->orders,
            'gross_minor' => (int) $row->gross_minor, 'refunded_minor' => (int) $row->refunded_minor,
            'net_minor' => (int) $row->gross_minor - (int) $row->refunded_minor, 'exception_orders' => (int) $row->exception_orders,
            'exception_gross_minor' => (int) $row->exception_gross_minor])->all();
    }

    /** License tier breakdown from hash-verified pricing evidence; refunds only through the A5 read model. */
    private function testTiers(string $account, array $period): array
    {
        $result = ['available' => false, 'reason' => null, 'rows' => [], 'unattributed_refund_minor' => [], 'unverified_orders' => 0, 'unreadable_refund_orders' => 0];
        $orders = $this->testScope($account, $period, ['paid'])->count();
        $cap = (int) config('reporting.sales.tier_order_cap');
        if ($orders > $cap) {
            return ['reason' => "{$orders} paid orders exceed the {$cap}-order limit for the tier breakdown; narrow the period."] + $result;
        }
        $tiers = [];
        $refunded = [];
        $unverified = 0;
        $last = 0;
        while (true) {
            $chunk = $this->testScope($account, $period, ['paid'])->join('orders as o', 'o.id', '=', 'f.order_id')
                ->leftJoin('refund_dispute_observations as r', ReportingQueries::latestObserved(...))->where('f.id', '>', $last)->orderBy('f.id')->limit(500)
                ->get(['f.id as finalization_id', 'f.order_id', 'o.quote_pricing_id', 'p.currency', 'p.amount_minor', 'r.succeeded_refund_minor as succeeded']);
            if ($chunk->isEmpty()) {
                break;
            }
            $last = (int) $chunk->last()->finalization_id;
            $lines = DB::table('order_lines as l')->join('license_grants as g', 'g.order_line_id', '=', 'l.id')
                ->join('license_versions as v', 'v.id', '=', 'g.license_version_id')->join('license_templates as t', 't.id', '=', 'v.license_template_id')
                ->whereIn('l.order_id', $chunk->pluck('order_id')->all())
                ->get(['l.id as line_id', 'l.order_id', 'l.position', 't.id as template_id', 't.name', 't.type'])->groupBy('order_id');
            $pricing = DB::table('quote_pricings')->whereIn('id', $chunk->pluck('quote_pricing_id')->all())
                ->get(['id', 'snapshot', 'snapshot_hash', 'canonicalization_version'])->keyBy('id');
            foreach ($chunk as $order) {
                $priced = $this->pricedLines($pricing[$order->quote_pricing_id] ?? null, $lines[$order->order_id] ?? collect(), (string) $order->currency, (int) $order->amount_minor);
                if ($priced === null) {
                    $unverified++;
                    if ((int) $order->succeeded > 0) {
                        $refunded[] = ['finalization_id' => (int) $order->finalization_id, 'order_id' => (int) $order->order_id, 'currency' => (string) $order->currency,
                            'succeeded' => (int) $order->succeeded, 'tiers' => []];
                    }

                    continue;
                }
                $map = [];
                foreach ($priced as $line) {
                    $key = $order->currency.'|'.$line['template_id'];
                    $tiers[$key] ??= ['tier' => $line['name'], 'tier_type' => $line['type'], 'currency' => (string) $order->currency, 'lines' => 0,
                        'base_minor' => 0, 'discount_minor' => 0, 'tax_minor' => 0, 'paid_minor' => 0, 'refunded_minor' => 0];
                    $tiers[$key]['lines']++;
                    foreach (['base_minor', 'discount_minor', 'tax_minor'] as $field) {
                        $tiers[$key][$field] += $line[$field];
                    }
                    $tiers[$key]['paid_minor'] += $line['total_minor'];
                    $map[$line['line_id']] = $key;
                }
                if ((int) $order->succeeded > 0) {
                    $refunded[] = ['finalization_id' => (int) $order->finalization_id, 'order_id' => (int) $order->order_id, 'currency' => (string) $order->currency,
                        'succeeded' => (int) $order->succeeded, 'tiers' => $map];
                }
            }
        }
        $unattributed = [];
        $unreadable = 0;
        if (count($refunded) > (int) config('reporting.sales.refund_case_cap')) {
            // Netting each refunded order loads and verifies its original; past the cap the tier net would be partial.
            return ['reason' => count($refunded).' refunded orders exceed the '.(int) config('reporting.sales.refund_case_cap').'-order limit for tier refunds; narrow the period. Order-level totals above are exact.'] + $result;
        }
        foreach ($refunded as $entry) {
            try {
                $order = Order::findOrFail($entry['order_id']);
                $case = RefundDisputeCase::load($order, OrderFinalization::findOrFail($entry['finalization_id']), app(ReadOrder::class)->verify($order));
                $byLine = $case->refundedByLine();
            } catch (Throwable) {
                $unreadable++;
                $unattributed[$entry['currency']] = ($unattributed[$entry['currency']] ?? 0) + $entry['succeeded'];

                continue;
            }
            $attributed = 0;
            foreach ($byLine as $lineId => $amount) {
                if ($amount > 0 && isset($entry['tiers'][$lineId])) {
                    $tiers[$entry['tiers'][$lineId]]['refunded_minor'] += $amount;
                    $attributed += $amount;
                }
            }
            if ($entry['succeeded'] - $attributed !== 0) {
                $unattributed[$entry['currency']] = ($unattributed[$entry['currency']] ?? 0) + $entry['succeeded'] - $attributed;
            }
        }
        $rows = array_values(array_map(fn (array $row): array => $row + ['net_minor' => $row['paid_minor'] - $row['refunded_minor']], $tiers));
        usort($rows, fn (array $a, array $b): int => [$a['currency'], -$a['paid_minor'], $a['tier']] <=> [$b['currency'], -$b['paid_minor'], $b['tier']]);
        ksort($unattributed);

        return ['available' => true, 'reason' => null, 'rows' => $rows, 'unattributed_refund_minor' => $unattributed,
            'unverified_orders' => $unverified, 'unreadable_refund_orders' => $unreadable];
    }

    /**
     * Line prices from the immutable, hash-checked pricing snapshot, or null when the evidence does not verify: unknown
     * schema, a hash mismatch, a different currency, a line count that differs from the granted lines, or line totals
     * that do not add up to the verified payment.
     *
     * @return list<array{line_id: int, template_id: int, name: string, type: string, base_minor: int, discount_minor: int, tax_minor: int, total_minor: int}>|null
     */
    private function pricedLines(?object $pricing, $lines, string $currency, int $paid): ?array
    {
        try {
            if ($pricing === null || $pricing->canonicalization_version !== CanonicalJson::VERSION) {
                return null;
            }
            $snapshot = json_decode((string) $pricing->snapshot, true, 128, JSON_THROW_ON_ERROR);
            if (! is_array($snapshot) || ! hash_equals((string) $pricing->snapshot_hash, CanonicalJson::hash($snapshot)) || ($snapshot['currency'] ?? null) !== $currency
                || ! is_array($snapshot['lines'] ?? null) || count($snapshot['lines']) !== count($lines) || $lines->isEmpty()) {
                return null;
            }
            $priced = [];
            $sum = 0;
            foreach ($lines->sortBy('position') as $line) {
                $price = $snapshot['lines'][(int) $line->position] ?? null;
                foreach (['base_minor', 'discount_minor', 'tax_minor', 'total_minor'] as $field) {
                    if (! is_array($price) || ! is_int($price[$field] ?? null) || $price[$field] < 0) {
                        return null;
                    }
                }
                $sum += $price['total_minor'];
                $priced[] = ['line_id' => (int) $line->line_id, 'template_id' => (int) $line->template_id, 'name' => (string) $line->name, 'type' => (string) $line->type,
                    'base_minor' => $price['base_minor'], 'discount_minor' => $price['discount_minor'], 'tax_minor' => $price['tax_minor'], 'total_minor' => $price['total_minor']];
            }

            return $sum === $paid ? $priced : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Test-chain finalizations in the period for the configured test account, with the verified payment joined. */
    private function testScope(string $account, array $period, array $outcomes): Builder
    {
        return DB::table('order_finalizations as f')->join('verified_payments as p', 'p.id', '=', 'f.verified_payment_id')
            ->where('f.mode', 'test')->whereIn('f.outcome', $outcomes)->where('p.mode', 'test')->where('p.account_id', $account)
            ->whereColumn('p.order_id', 'f.order_id')->where('f.finalized_at', '>=', $period['start'])->where('f.finalized_at', '<', $period['end']);
    }

    /** @return array<string, mixed> */
    private function production(array $period): array
    {
        $empty = ['available' => false, 'reason' => null, 'modes' => [], 'funnel' => []];
        try {
            foreach (['production_checkout_payments', 'production_checkout_orders', 'production_checkout_order_lines'] as $table) {
                if (! Schema::hasTable($table)) {
                    return ['reason' => 'The production checkout tables are not installed.'] + $empty;
                }
            }
            $bucket = self::bucket('p.observed_at', $period['granularity'], iso: true);
            $payments = fn (): Builder => DB::table('production_checkout_payments as p')->where('p.observed_at', '>=', $period['startIso'])->where('p.observed_at', '<', $period['endIso']);
            $periods = $payments()->selectRaw($bucket.' AS period, p.funds_mode AS funds_mode,'
                ." COUNT(CASE WHEN p.outcome = 'on_time' THEN 1 END) AS orders,"
                ." COALESCE(SUM(CASE WHEN p.outcome = 'on_time' THEN p.amount_minor END), 0) AS gross_minor,"
                ." COUNT(CASE WHEN p.outcome = 'paid_exception' THEN 1 END) AS exception_orders,"
                ." COALESCE(SUM(CASE WHEN p.outcome = 'paid_exception' THEN p.amount_minor END), 0) AS exception_gross_minor")
                ->groupBy('period', 'p.funds_mode')->orderBy('period')->orderBy('p.funds_mode')->get();
            $tiers = $payments()->join('production_checkout_order_lines as l', 'l.order_id', '=', 'p.order_id')
                ->join('license_versions as v', 'v.id', '=', 'l.license_version_id')->join('license_templates as t', 't.id', '=', 'v.license_template_id')
                ->where('p.outcome', 'on_time')
                ->selectRaw('t.id AS template_id, t.name AS tier, t.type AS tier_type, p.funds_mode AS funds_mode, COUNT(*) AS lines, COALESCE(SUM(l.amount_minor), 0) AS gross_minor')
                ->groupBy('t.id', 't.name', 't.type', 'p.funds_mode')->orderBy('p.funds_mode')->orderBy('t.id')->get();
            $modes = [];
            foreach ($periods as $row) {
                $mode = $row->funds_mode === 'live' ? 'live' : 'rehearsal';
                $modes[$mode]['periods'][] = ['period' => (string) $row->period, 'orders' => (int) $row->orders, 'gross_minor' => (int) $row->gross_minor,
                    'exception_orders' => (int) $row->exception_orders, 'exception_gross_minor' => (int) $row->exception_gross_minor];
            }
            foreach ($tiers as $row) {
                $mode = $row->funds_mode === 'live' ? 'live' : 'rehearsal';
                $modes[$mode]['tiers'][] = ['tier' => (string) $row->tier, 'tier_type' => (string) $row->tier_type, 'lines' => (int) $row->lines, 'gross_minor' => (int) $row->gross_minor];
            }
            foreach ($modes as $mode => $section) {
                $modes[$mode] = ($section + ['periods' => [], 'tiers' => []]) + ['label' => self::ENVIRONMENTS[$mode], 'currency' => 'USD',
                    'orders' => array_sum(array_column($section['periods'] ?? [], 'orders')), 'gross_minor' => array_sum(array_column($section['periods'] ?? [], 'gross_minor')),
                    'exception_orders' => array_sum(array_column($section['periods'] ?? [], 'exception_orders')),
                    'exception_gross_minor' => array_sum(array_column($section['periods'] ?? [], 'exception_gross_minor'))];
            }
            ksort($modes);
            $funnel = ['orders_prepared' => DB::table('production_checkout_orders')->where('created_at', '>=', $period['startIso'])->where('created_at', '<', $period['endIso'])->count(),
                'payments_on_time' => array_sum(array_column($modes, 'orders')), 'payments_after_expiry' => array_sum(array_column($modes, 'exception_orders'))];

            return ['available' => true, 'reason' => null, 'modes' => $modes, 'funnel' => $funnel];
        } catch (Throwable $error) {
            report($error);

            return ['reason' => 'The production checkout data could not be read.'] + $empty;
        }
    }

    /** Portable UTC bucket expression; selected as an alias and grouped by it (MySQL ONLY_FULL_GROUP_BY safe). */
    private static function bucket(string $column, string $granularity, bool $iso = false): string
    {
        $length = $granularity === 'month' ? 7 : 10;
        if ($iso) {
            return "SUBSTR({$column}, 1, {$length})";
        }
        $format = $granularity === 'month' ? '%Y-%m' : '%Y-%m-%d';

        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('{$format}', {$column})",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '{$format}')",
            default => throw new ReportingException('unsupported_database'),
        };
    }
}
