<?php

namespace App\Domain\Reporting;

use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Environment\TestEnvironment;
use Illuminate\Support\Facades\DB;

/**
 * Staff-only, consent-aware segmentation of first-party customer accounts. Default-off (owner policy U-13).
 *
 * Scope: the first-party customer accounts and their retained test-chain orders. Production identity accounts are not
 * covered. The export reads no email address, name or order content: its fields are a pseudonymous account reference, the
 * segment, the labelled marketing consent state and purchase aggregates in integer minor units per currency. The screen
 * shows counts only; rows leave the application only through the audited export. Marketing consent is never inferred: only
 * verified granted consent enters a marketing segment.
 */
final class CustomerSegments
{
    public const SEGMENTS = [
        'purchasers' => 'Purchasers (at least one paid order)',
        'repeat_purchasers' => 'Repeat purchasers (two or more paid orders)',
        'refunded_purchasers' => 'Purchasers with a succeeded refund',
        'no_purchase' => 'Active accounts without a paid order',
        'marketing_email_eligible' => 'Marketing email: verified consent granted',
    ];

    public const MARKETING = ['marketing_email_eligible'];

    public const ENVIRONMENT = 'TEST (synthetic accounts and orders)';

    public function __construct(private readonly StaffReportingAuthority $authority) {}

    /** @return array{enabled: bool, export_enabled: bool, marketing_enabled: bool} */
    public static function flags(): array
    {
        return ['enabled' => config('reporting.customer_segments.enabled') === true, 'export_enabled' => config('reporting.customer_segments.export_enabled') === true,
            'marketing_enabled' => config('reporting.customer_segments.marketing_segment_enabled') === true];
    }

    /**
     * Counts per segment and consent state; no customer row.
     *
     * @return array<string, mixed>
     */
    public function overview(?User $actor): array
    {
        $this->authority->staff($actor);
        $flags = self::flags();
        $base = ['flags' => $flags, 'environment' => self::ENVIRONMENT, 'available' => false, 'reason' => null, 'accounts' => null, 'segments' => [], 'consent' => [],
            'notes' => ['Production identity accounts are not covered: that path is default-off and its policy is open (U-07, U-13).',
                'No email address, name or order content is read. Marketing consent is never inferred from a purchase or an import.']];
        if (! $flags['enabled']) {
            return ['reason' => 'Customer segmentation is off. Enable it only after the owner approves retention, consent purposes and processors (U-13).'] + $base;
        }
        $population = $this->population();
        $segments = [];
        foreach (self::SEGMENTS as $key => $label) {
            $marketing = in_array($key, self::MARKETING, true);
            $members = $marketing && ! $flags['marketing_enabled'] ? null : $this->members($population, $key);
            $segments[$key] = ['label' => $label, 'count' => $members === null ? null : count($members), 'marketing' => $marketing,
                'reason' => $members === null ? ($marketing && ! $flags['marketing_enabled']
                    ? 'The marketing segment is off (REPORTING_MARKETING_SEGMENT_ENABLED).' : self::unavailableReason()) : null];
        }
        $consent = array_fill_keys(array_keys(CustomerConsentStatus::LABELS), 0);
        foreach ($population['accounts'] as $account) {
            $consent[$population['consent'][$account->id]]++;
        }

        return ['available' => true, 'reason' => null, 'accounts' => count($population['accounts']), 'segments' => $segments, 'consent' => $consent] + $base;
    }

    /** Audited CSV export of one segment; the audit row is written before the file is returned and a refusal writes nothing. */
    public function export(?User $actor, string $segment): array
    {
        $user = $this->authority->exporter($actor);
        $flags = self::flags();
        ReportingException::require($flags['enabled'] && $flags['export_enabled'], 'disabled');
        ReportingException::require(array_key_exists($segment, self::SEGMENTS), 'unknown_segment');
        $marketing = in_array($segment, self::MARKETING, true);
        ReportingException::require(! $marketing || $flags['marketing_enabled'], 'marketing_disabled');
        $population = $this->population();
        $members = $this->members($population, $segment);
        ReportingException::require($members !== null, 'unavailable');
        $rows = [];
        foreach ($members as $account) {
            $consent = $population['consent'][$account->id];
            // Defence in depth: the marketing filter is applied in members() and asserted again on the rows that leave.
            ReportingException::require(! $marketing || $consent === 'granted', 'consent_filter');
            $purchases = $population['purchases'][$account->id] ?? [];
            if ($purchases === []) {
                $rows[] = [$account->public_id, self::ENVIRONMENT, $segment, (int) $account->active, $consent, 0, null, null, null, 0, 0, 0];

                continue;
            }
            foreach ($purchases as $currency => $row) {
                $rows[] = [$account->public_id, self::ENVIRONMENT, $segment, (int) $account->active, $consent, $row['orders'], substr($row['first_at'], 0, 10),
                    substr($row['last_at'], 0, 10), $currency, $row['paid_minor'], $row['refunded_minor'], $row['paid_minor'] - $row['refunded_minor']];
            }
        }
        ReportingException::require(count($rows) <= (int) config('reporting.customer_segments.export_row_cap'), 'too_large');
        $csv = ReportingCsv::render(['customer_reference', 'environment', 'segment', 'account_active', 'email_marketing_consent', 'orders', 'first_order_date_utc',
            'last_order_date_utc', 'currency', 'paid_minor', 'refunded_minor', 'net_minor'], $rows);
        $sha256 = hash('sha256', $csv);
        AuditEvent::recordAttributed('reporting.customer_segment_exported', $user, ['segment' => $segment, 'rows' => count($rows), 'customers' => count($members),
            'sha256' => $sha256, 'consent_filter' => $marketing ? 'verified_granted_only' : 'labelled_only', 'environment' => 'test_synthetic', 'contact_fields' => false], $user->id);

        return ['filename' => 'customer-segment-'.$segment.'.csv', 'csv' => $csv, 'rows' => count($rows), 'customers' => count($members), 'sha256' => $sha256];
    }

    /**
     * @return array{accounts: list<object>, purchases: array<int, array<string, array<string, int|string>>>, consent: array<int, string>, purchases_available: bool}
     */
    private function population(): array
    {
        $cap = (int) config('reporting.customer_segments.account_cap');
        ReportingException::require(DB::table('customer_accounts')->count() <= $cap, 'too_large');
        $accounts = DB::table('customer_accounts')->orderBy('id')->get(['id', 'public_id', 'user_id', 'active'])->all();
        $account = TestEnvironment::admitsTestCommerce() ? ReportingQueries::testAccount() : null;
        $purchases = [];
        if ($account !== null) {
            $rows = DB::table('customer_accounts as ca')->join('orders as o', 'o.owner_key', '=', 'ca.owner_key')
                ->join('order_finalizations as f', 'f.order_id', '=', 'o.id')->join('verified_payments as p', 'p.id', '=', 'f.verified_payment_id')
                ->leftJoin('refund_dispute_observations as r', ReportingQueries::latestObserved(...))
                ->where('f.mode', 'test')->where('f.outcome', 'paid')->where('p.mode', 'test')->where('p.account_id', $account)->whereColumn('p.order_id', 'o.id')
                ->selectRaw('ca.id AS account_id, p.currency AS currency, COUNT(*) AS orders, MIN(f.finalized_at) AS first_at, MAX(f.finalized_at) AS last_at,'
                    .' SUM(p.amount_minor) AS paid_minor, COALESCE(SUM(r.succeeded_refund_minor), 0) AS refunded_minor')
                ->groupBy('ca.id', 'p.currency')->orderBy('ca.id')->orderBy('p.currency')->get();
            foreach ($rows as $row) {
                $purchases[(int) $row->account_id][(string) $row->currency] = ['orders' => (int) $row->orders, 'first_at' => (string) $row->first_at,
                    'last_at' => (string) $row->last_at, 'paid_minor' => (int) $row->paid_minor, 'refunded_minor' => (int) $row->refunded_minor];
            }
        }
        $consent = (new CustomerConsentStatus)->forAccounts(array_column(array_map(fn (object $row): array => [(int) $row->id, (int) $row->user_id], $accounts), 1, 0));

        return ['accounts' => $accounts, 'purchases' => $purchases, 'consent' => $consent, 'purchases_available' => $account !== null];
    }

    /** @return list<object>|null null when the segment cannot be evaluated truthfully */
    private function members(array $population, string $segment): ?array
    {
        $purchaseSegment = in_array($segment, ['purchasers', 'repeat_purchasers', 'refunded_purchasers', 'no_purchase'], true);
        if ($purchaseSegment && ! $population['purchases_available']) {
            return null;
        }
        $members = [];
        foreach ($population['accounts'] as $account) {
            $purchases = $population['purchases'][$account->id] ?? [];
            $orders = array_sum(array_column($purchases, 'orders'));
            $included = match ($segment) {
                'purchasers' => $orders >= 1,
                'repeat_purchasers' => $orders >= 2,
                'refunded_purchasers' => array_sum(array_column($purchases, 'refunded_minor')) > 0,
                'no_purchase' => (bool) $account->active && $orders === 0,
                'marketing_email_eligible' => (bool) $account->active && $population['consent'][$account->id] === 'granted',
                default => false,
            };
            if ($included) {
                $members[] = $account;
            }
        }

        return $members;
    }

    private static function unavailableReason(): string
    {
        return 'The Stripe test account is not configured or test commerce is not admitted, so purchases cannot be read.';
    }
}
