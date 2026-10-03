<div class="space-y-4">
    <p>Fulfillment remains blocked. This operational history does not resolve the payment exception, release resources or inspect refunds and disputes.</p>
    <p>Current history sequence: {{ $review['sequence'] }}. Showing up to 20 most recent entries.</p>
    <ul class="space-y-2">
        @forelse ($review['history'] as $event)
            <li>
                <strong>#{{ $event['sequence'] }} — {{ str_replace('_', ' ', $event['status']) }}</strong>:
                {{ str_replace('_', ' ', $event['outcome']) }}.
                @if ($event['observedAt'])
                    Observed {{ $event['observedAt'] }}.
                @endif
            </li>
        @empty
            <li>No operational history recorded.</li>
        @endforelse
    </ul>
</div>
