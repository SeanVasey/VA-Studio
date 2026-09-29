<div class="space-y-4">
    <p class="font-semibold">{{ $detail['code'] }} · {{ $detail['managed'] ? ucfirst($detail['status']) : 'Configuration managed' }} · Test mode · USD</p>
    <p>{{ $detail['managed'] ? 'Managed here. These saved terms cannot be edited.' : 'Configured campaign history. Availability is controlled by its configuration and is read only here.' }}</p>
    @php($policy = $detail['policy'])
    <dl class="space-y-2">
        <div><dt class="font-semibold">Campaign identity</dt><dd>{{ $detail['key'] }} · version {{ $detail['version'] }}</dd></div>
        <div><dt class="font-semibold">Schedule (UTC)</dt><dd>{{ $policy['effective_from'] }} to {{ $policy['effective_until'] }} (end excluded)</dd></div>
        <div><dt class="font-semibold">Discount</dt><dd>
            @if ($policy['discount']['type'] === 'fixed')
                {{ $policy['discount']['amount_minor'] }} USD cents
            @else
                {{ $policy['discount']['rate_bps'] }} basis points (100 basis points = 1%), capped at {{ $policy['discount']['max_discount_minor'] }} USD cents
            @endif
        </dd></div>
        <div><dt class="font-semibold">Minimum eligible subtotal</dt><dd>{{ $policy['minimum_subtotal_minor'] }} USD cents</dd></div>
        <div><dt class="font-semibold">Eligibility</dt><dd>
            @if ($policy['eligibility']['mode'] === 'all_non_exclusive')
                All non-exclusive offers
            @else
                Exact offer revision IDs: {{ implode(', ', $policy['eligibility']['offer_revision_ids']) }}
            @endif
        </dd></div>
    </dl>
    <section class="space-y-2 rounded-lg border border-gray-300 p-4 dark:border-gray-700">
        <h3 class="font-semibold">Lifetime capacity: {{ $policy['max_uses'] }} uses</h3>
        <p>Current unstarted holds: {{ $detail['usage']['held'] }}</p>
        <p>Pending attempts: {{ $detail['usage']['pending'] }}</p>
        <p>Consumed uses: {{ $detail['usage']['consumed'] }}</p>
        <p>Expired unstarted holds: {{ $detail['usage']['expired'] }}</p>
        <p>Remaining capacity: {{ $detail['usage']['remaining'] }}</p>
        <p>Pending and consumed uses retain capacity. Expired unstarted holds are kept as history and do not count against the limit. These totals describe the current records; availability is checked again when a selection progresses.</p>
    </section>
    <p>One promotion per selection. Discounts are allocated by the existing server rules. Disabling a campaign preserves its historical calculations and does not release attempts, refund payments or change grants.</p>
</div>
