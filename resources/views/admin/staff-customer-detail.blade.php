<div class="space-y-6">
    @if ($detail['customer'] === null)
        <p>No customer is in scope for this record.</p>
    @else
        <p>Customer {{ $detail['customer']['reference'] }}: {{ $detail['customer']['account'] ? 'test account ('.($detail['customer']['active'] ? 'active' : 'inactive').')' : 'guest without an account' }}. Showing up to 20 most recent orders.</p>
        @foreach ($detail['orders'] as $order)
            <section class="border-t pt-4">
                @include('admin.staff-order-detail', ['order' => $order])
            </section>
        @endforeach
    @endif
</div>
