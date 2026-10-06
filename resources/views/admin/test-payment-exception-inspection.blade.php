<div class="space-y-4">
    <p class="font-semibold">{{ $detail['inspectionStatus'] === 'verified' ? 'Stored graph verified' : 'Evidence needs attention' }}</p>
    <p>Finalization: {{ $detail['finalizationId'] }}</p>
    @if ($detail['inspectionStatus'] === 'verified')
        <dl class="space-y-2">
            <div><dt class="font-semibold">Order</dt><dd>{{ $detail['orderId'] }}</dd></div>
            <div><dt class="font-semibold">Recorded reason</dt><dd>{{ \App\Domain\Commerce\Operations\ReadTestCommerceOperations::EXCEPTION_REASONS[$detail['recordedReason']] }}</dd></div>
            <div><dt class="font-semibold">Confirmation observed (UTC)</dt><dd>{{ $detail['confirmedAt'] }}</dd></div>
            <div><dt class="font-semibold">Original eligibility cutoff (UTC)</dt><dd>{{ $detail['eligibilityCutoff'] }}</dd></div>
            <div><dt class="font-semibold">Finalization recorded (UTC)</dt><dd>{{ $detail['finalizedAt'] }}</dd></div>
            <div><dt class="font-semibold">Retained inventory</dt><dd>{{ $detail['inventoryState'] === 'released' ? 'Released' : 'Pending' }}</dd></div>
            <div><dt class="font-semibold">Retained promotion</dt><dd>{{ match ($detail['promotionState']) { 'pending' => 'Pending', 'released' => 'Released', default => 'No promotion' } }}</dd></div>
            <div><dt class="font-semibold">Retained rights effects</dt><dd>{{ $detail['grantCount'] }} grants · {{ $detail['exclusiveSaleCount'] }} exclusive sales</dd></div>
            <div><dt class="font-semibold">Retained exception event</dt><dd>{{ $detail['outboxCount'] }} pending event</dd></div>
        </dl>
    @else
        <p>The complete retained evidence could not be verified. No financial or fulfillment change was made.</p>
    @endif
    <p>Current provider, refund and dispute state: not inspected. Asset and contract file health: not inspected.</p>
    <p>This inspection checks historical test records and records an audit. It does not resolve the exception, release resources, issue rights, refund money or authorize delivery.</p>
</div>
