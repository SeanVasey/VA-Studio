<div class="space-y-4">
    <p>Order {{ $order['order'] }} ({{ str_replace('_', ' ', $order['outcome']) }}), paid {{ $order['paidMinor'] }} {{ $order['currency'] }} minor units, recorded {{ $order['finalizedAt'] }}. Original evidence: {{ $order['evidence'] }}. Test evidence only.</p>
    <h3 class="font-semibold">Lines</h3>
    <ul class="space-y-1">
        @foreach ($order['lines'] as $line)
            <li>Line {{ $line['position'] }}: {{ $line['title'] ?? 'untitled' }} — {{ $line['totalMinor'] ?? 'unknown' }} minor units{{ $line['exclusive'] ? ', exclusive' : '' }}; refunded by allocation {{ $line['allocatedMinor'] }}; downloads {{ $line['downloadsHeld'] ? 'held' : 'not held' }}.@if ($line['grant']) Grant {{ $line['grant'] }}.@endif</li>
        @endforeach
    </ul>
    <h3 class="font-semibold">Refunds and disputes</h3>
    @if ($order['latestObservation'])
        <p>Latest check #{{ $order['latestObservation']['sequence'] }} at {{ $order['latestObservation']['observedAt'] }}: {{ $order['latestObservation']['outcome'] }}@if ($order['latestObservation']['outcome'] === 'observed'); refund {{ $order['latestObservation']['refundState'] }} ({{ $order['latestObservation']['succeededRefundMinor'] }} succeeded, {{ $order['latestObservation']['pendingRefundMinor'] }} pending); dispute {{ $order['latestObservation']['disputeState'] }}@endif.</p>
    @else
        <p>No provider check recorded.</p>
    @endif
    <ul class="space-y-1">
        @forelse ($order['providerObjects'] as $object)
            <li>{{ ucfirst($object['kind']) }} {{ $object['reference'] }}: {{ $object['amountMinor'] }} {{ $object['currency'] }}; statuses observed {{ implode(' → ', $object['statuses']) }}.</li>
        @empty
            <li>No refunds or disputes observed.</li>
        @endforelse
    </ul>
    <h3 class="font-semibold">Download holds</h3>
    <ul class="space-y-1">
        @forelse ($order['holds'] as $hold)
            <li>{{ str_replace('_', ' ', $hold['reason']) }} ({{ $hold['grant'] === null ? 'whole order' : 'grant '.$hold['grant'] }}) from {{ $hold['createdAt'] }}{{ $hold['releasedAt'] ? ', released '.$hold['releasedAt'] : ', active' }}.</li>
        @empty
            <li>No holds.</li>
        @endforelse
    </ul>
    <h3 class="font-semibold">Recorded effects (latest 30)</h3>
    <ul class="space-y-1">
        @forelse ($order['effects'] as $effect)
            <li>#{{ $effect['sequence'] }} {{ str_replace('_', ' ', $effect['kind']) }}{{ $effect['staff'] ? ' (staff)' : ' (automatic)' }} at {{ $effect['createdAt'] }}.</li>
        @empty
            <li>No effects recorded.</li>
        @endforelse
    </ul>
    <p>Download evidence: {{ $order['downloads']['authorizations'] }} authorizations, {{ $order['downloads']['redemptions'] }} redemption attempts{{ $order['downloads']['lastIssuedAt'] ? ', last issued '.$order['downloads']['lastIssuedAt'] : '' }}.</p>
</div>
