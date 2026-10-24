@php
    use App\Domain\Reporting\SalesReport;
    $pct = fn (?int $bps): string => $bps === null ? 'not defined (no orders prepared)' : intdiv($bps, 100).'.'.str_pad((string) ($bps % 100), 2, '0', STR_PAD_LEFT).'%';
@endphp
<x-filament-panels::page>
    <form wire:submit="$refresh" style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: end;">
        <div>
            <label for="report-from" style="display: block; font-weight: 600;">From (UTC date)</label>
            <input id="report-from" type="date" wire:model="from" required>
        </div>
        <div>
            <label for="report-to" style="display: block; font-weight: 600;">To (UTC date, inclusive)</label>
            <input id="report-to" type="date" wire:model="to" required>
        </div>
        <div>
            <label for="report-granularity" style="display: block; font-weight: 600;">Buckets</label>
            <select id="report-granularity" wire:model="granularity">
                <option value="day">Day</option>
                <option value="month">Month</option>
            </select>
        </div>
        <button type="submit" class="fi-btn fi-color fi-size-md fi-btn-color-primary" style="padding: .5rem 1rem; font-weight: 600;">Apply</button>
    </form>

    @if ($error)
        <p role="alert" style="font-weight: 600;">{{ $error }}</p>
    @else
        <ul>
            @foreach ($report['notes'] as $note)
                <li>{{ $note }}</li>
            @endforeach
        </ul>

        @php $test = $report['test']; @endphp
        <section aria-labelledby="sales-test">
            <h2 id="sales-test" style="font-size: 1.25rem; font-weight: 700;">{{ SalesReport::ENVIRONMENTS['test'] }}</h2>
            <p style="font-weight: 600;">Synthetic test orders. Not revenue.</p>
            @if (! $test['available'])
                <p data-unavailable="test">Unavailable: {{ $test['reason'] }}</p>
            @else
                @if ($test['currencies'] === [])
                    <p data-empty="test">No paid test orders were recorded in this period.</p>
                @else
                    <table>
                        <caption>Test totals per currency (minor units)</caption>
                        <thead><tr><th scope="col">Currency</th><th scope="col">Paid orders</th><th scope="col">Gross (minor)</th><th scope="col">Refunded (minor)</th><th scope="col">Net (minor)</th><th scope="col">Paid exceptions</th><th scope="col">Exception gross (minor)</th></tr></thead>
                        <tbody>
                        @foreach ($test['currencies'] as $currency => $total)
                            <tr><th scope="row">{{ $currency }}</th><td>{{ $total['orders'] }}</td><td>{{ $total['gross_minor'] }}</td><td>{{ $total['refunded_minor'] }}</td><td>{{ $total['net_minor'] }}</td><td>{{ $total['exception_orders'] }}</td><td>{{ $total['exception_gross_minor'] }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                    <table>
                        <caption>Test orders by {{ $report['period']['granularity'] }} (UTC)</caption>
                        <thead><tr><th scope="col">Period</th><th scope="col">Currency</th><th scope="col">Paid orders</th><th scope="col">Gross (minor)</th><th scope="col">Refunded (minor)</th><th scope="col">Net (minor)</th><th scope="col">Paid exceptions</th></tr></thead>
                        <tbody>
                        @foreach ($test['periods'] as $row)
                            <tr><th scope="row">{{ $row['period'] }}</th><td>{{ $row['currency'] }}</td><td>{{ $row['orders'] }}</td><td>{{ $row['gross_minor'] }}</td><td>{{ $row['refunded_minor'] }}</td><td>{{ $row['net_minor'] }}</td><td>{{ $row['exception_orders'] }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
                @if (! $test['tiers']['available'])
                    <p data-unavailable="tiers">License tier breakdown unavailable: {{ $test['tiers']['reason'] }}</p>
                @elseif ($test['tiers']['rows'] !== [])
                    <table>
                        <caption>Test lines by license tier (minor units; paid includes tax)</caption>
                        <thead><tr><th scope="col">Tier</th><th scope="col">Type</th><th scope="col">Currency</th><th scope="col">Lines</th><th scope="col">Base (minor)</th><th scope="col">Discount (minor)</th><th scope="col">Tax (minor)</th><th scope="col">Paid (minor)</th><th scope="col">Refunded (minor)</th><th scope="col">Net (minor)</th></tr></thead>
                        <tbody>
                        @foreach ($test['tiers']['rows'] as $row)
                            <tr><th scope="row">{{ $row['tier'] }}</th><td>{{ $row['tier_type'] }}</td><td>{{ $row['currency'] }}</td><td>{{ $row['lines'] }}</td><td>{{ $row['base_minor'] }}</td><td>{{ $row['discount_minor'] }}</td><td>{{ $row['tax_minor'] }}</td><td>{{ $row['paid_minor'] }}</td><td>{{ $row['refunded_minor'] }}</td><td>{{ $row['net_minor'] }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                    @foreach ($test['tiers']['unattributed_refund_minor'] as $currency => $amount)
                        <p>Succeeded refunds not attributed to a tier ({{ $currency }}): {{ $amount }} minor units (no line allocation recorded, or the order could not be read).</p>
                    @endforeach
                    @if ($test['tiers']['unverified_orders'] > 0)
                        <p role="status">{{ $test['tiers']['unverified_orders'] }} paid order(s) are in the totals above but not in the tier table because their pricing evidence did not verify.</p>
                    @endif
                    @if ($test['tiers']['unreadable_refund_orders'] > 0)
                        <p role="status">{{ $test['tiers']['unreadable_refund_orders'] }} refunded order(s) could not be read for tier refunds; their refunds are shown as not attributed.</p>
                    @endif
                @endif
                <table>
                    <caption>Test conversion funnel (events in the period, not a cohort)</caption>
                    <tbody>
                    @foreach ($test['funnel']['counts'] as $metric => $count)
                        <tr><th scope="row">{{ str_replace('_', ' ', $metric) }}</th><td>{{ $count }}</td></tr>
                    @endforeach
                    <tr><th scope="row">paid per order prepared</th><td>{{ $pct($test['funnel']['conversion_bps']) }}</td></tr>
                    </tbody>
                </table>
            @endif
        </section>

        @php $production = $report['production']; @endphp
        <section aria-labelledby="sales-production">
            <h2 id="sales-production" style="font-size: 1.25rem; font-weight: 700;">Production checkout</h2>
            @if (! $production['available'])
                <p data-unavailable="production">Unavailable: {{ $production['reason'] }}</p>
            @elseif ($production['modes'] === [])
                <p data-empty="production">No production checkout payments were recorded in this period.</p>
            @else
                <p>Refunds and disputes are not recorded for production orders: the refund ledger covers retained test orders only, so these figures are gross.</p>
                @foreach ($production['modes'] as $mode)
                    <h3 style="font-weight: 700;">{{ $mode['label'] }}</h3>
                    <table>
                        <caption>{{ $mode['label'] }} per period (USD minor units)</caption>
                        <thead><tr><th scope="col">Period</th><th scope="col">On-time paid orders</th><th scope="col">Gross (minor)</th><th scope="col">Paid after expiry</th><th scope="col">After-expiry gross (minor)</th></tr></thead>
                        <tbody>
                        @foreach ($mode['periods'] as $row)
                            <tr><th scope="row">{{ $row['period'] }}</th><td>{{ $row['orders'] }}</td><td>{{ $row['gross_minor'] }}</td><td>{{ $row['exception_orders'] }}</td><td>{{ $row['exception_gross_minor'] }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                    @if ($mode['tiers'] !== [])
                        <table>
                            <caption>{{ $mode['label'] }} lines by license tier (USD minor units)</caption>
                            <thead><tr><th scope="col">Tier</th><th scope="col">Type</th><th scope="col">Lines</th><th scope="col">Gross (minor)</th></tr></thead>
                            <tbody>
                            @foreach ($mode['tiers'] as $row)
                                <tr><th scope="row">{{ $row['tier'] }}</th><td>{{ $row['tier_type'] }}</td><td>{{ $row['lines'] }}</td><td>{{ $row['gross_minor'] }}</td></tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                @endforeach
                <table>
                    <caption>Production checkout funnel (events in the period)</caption>
                    <tbody>
                    @foreach ($production['funnel'] as $metric => $count)
                        <tr><th scope="row">{{ str_replace('_', ' ', $metric) }}</th><td>{{ $count }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endif
</x-filament-panels::page>
