<div class="space-y-4">
    <p>Order {{ $review['orderId'] }}. Current history sequence: {{ $review['sequence'] }}.</p>
    <p>{{ match ($review['status']) {
        'released' => 'A release is recorded.',
        'buyer_cancelled' => 'Cancelled by the buyer before it was sent to Stripe; its pending resources are already released.',
        'payment_recorded' => 'Payment is recorded; unpaid release is not available.',
        'unverified' => 'Eligible unpaid status has not been established.',
        default => 'The current release state needs attention.',
    } }}</p>
    @if (($review['checkout'] ?? null) === 'never_sent')
        <p>Never sent to Stripe: this order has no checkout request. Its checkout window closes at {{ $review['checkoutWindowClosesAt'] }} UTC.</p>
    @endif
    <p>History records test release checks. Expiry alone never establishes unpaid status. This page does not cancel or refund payments, issue rights or restore a released attempt.</p>
    @if ($review['buyerCancellation'] ?? null)
        <p>Cancelled by buyer: retained cancellation {{ $review['buyerCancellation']['publicId'] }}, recorded {{ $review['buyerCancellation']['cancelledAt'] }} UTC. The order can never be sent to Stripe or paid.</p>
    @endif
    @if ($review['release'])
        <p>Retained release {{ $review['release']['publicId'] }}, recorded {{ $review['release']['releasedAt'] }}.</p>
    @else
        <p>No release is recorded in this review.</p>
    @endif
    <p>Showing up to 20 most recent history entries.</p>
    <ul class="space-y-2">
        @forelse ($review['history'] as $event)
            <li>
                <strong>#{{ $event['sequence'] }} — {{ str_replace('_', ' ', $event['kind']) }}</strong>:
                {{ str_replace('_', ' ', $event['outcome']) }}.
                Recorded {{ $event['createdAt'] }}.
            </li>
        @empty
            <li>No release history recorded.</li>
        @endforelse
    </ul>
</div>
