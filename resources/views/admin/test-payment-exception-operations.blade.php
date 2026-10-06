<div class="space-y-4">
    <p>Fulfillment remains blocked. Observations of refunds and disputes do not resolve the payment exception, release resources, refund money or change access. Separate provider reads describe what was observed; they are not an atomic financial resolution.</p>
    <p>Current history sequence: {{ $review['sequence'] }}. Showing up to 20 most recent entries.</p>
    <ul class="space-y-2">
        @forelse ($review['history'] as $event)
            <li>
                <strong>#{{ $event['sequence'] }} — {{ str_replace('_', ' ', $event['status']) }}</strong>:
                {{ str_replace('_', ' ', $event['outcome']) }}.
                @if ($event['observedAt'])
                    Observed {{ $event['observedAt'] }}.
                @endif
                @if ($event['refundDisputeState'] === 'observed')
                    <p>Refunds observed: {{ $event['financialObservation']['refundCount'] }};
                        provider refunded amount: {{ $event['financialObservation']['refundedMinor'] }} USD minor units.
                        Refund statuses: {{ implode(', ', $event['financialObservation']['refundStatuses']) ?: 'none observed' }}.
                        Disputes observed: {{ $event['financialObservation']['disputeCount'] }};
                        statuses: {{ implode(', ', $event['financialObservation']['disputeStatuses']) ?: 'none observed' }}.</p>
                @elseif ($event['refundDisputeState'] === 'incomplete')
                    <p>Refund/dispute list is incomplete: more provider results exist. Financial state remains unresolved; no absence of refunds or disputes is established.</p>
                @elseif ($event['refundDisputeState'] === 'attention')
                    <p>Refund/dispute evidence needs attention. Conflicting or unsupported observations remain unresolved.</p>
                @elseif ($event['refundDisputeState'] === 'unavailable')
                    <p>Refund/dispute observation unavailable. No absence of refunds or disputes is established.</p>
                @else
                    <p>Refunds and disputes were not inspected for this entry.</p>
                @endif
            </li>
        @empty
            <li>No operational history recorded.</li>
        @endforelse
    </ul>
</div>
