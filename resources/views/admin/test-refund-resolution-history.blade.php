<div class="space-y-4">
    <p>Current history sequence: {{ $review['sequence'] }}.</p>
    <p>This history records test-payment observations and retained resource resolution. No refund is sent. Grants and original contracts are unchanged; fulfillment remains blocked.</p>
    @if (($review['testOnly'] ?? false) === true && $review['resolution'])
        <p>Retained resolution {{ $review['resolution']['publicId'] }}, recorded {{ $review['resolution']['releasedAt'] }}.</p>
        <p>The order’s still-pending inventory and promotion resources were released after a verified full refund.</p>
    @else
        <p>No verified resource resolution is recorded in this review.</p>
    @endif
    <p>Showing up to 20 most recent operational history entries. Observations alone do not establish a resource resolution.</p>
    <ul class="space-y-2">
        @forelse ($review['history'] as $event)
            <li>
                <strong>#{{ $event['sequence'] }} — {{ match ($event['status']) {
                    'disposition' => 'Operational review',
                    'reconciliation_requested' => 'Payment check requested',
                    'reconciliation_observed' => 'Payment observation',
                    default => 'History entry',
                } }}</strong>:
                {{ match ($event['outcome']) {
                    'acknowledged' => 'Acknowledged',
                    'needs_review' => 'Needs further review',
                    'pending' => 'Pending',
                    'confirmed' => 'Payment confirmed',
                    'authorized' => 'Payment authorized',
                    'expired' => 'Payment expired',
                    'canceled' => 'Payment canceled',
                    'unavailable' => 'Observation unavailable',
                    default => 'Evidence needs attention',
                } }}.
                @if ($event['observedAt'])
                    Observed {{ $event['observedAt'] }}.
                @endif
            </li>
        @empty
            <li>No operational history recorded.</li>
        @endforelse
    </ul>
</div>
