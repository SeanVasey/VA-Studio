<div style="display: grid; gap: 1rem; overflow-wrap: anywhere; min-width: 0;">
    <p>Retained private versions for plan #{{ $history['plan_id'] }}. These synthetic drafts do not establish active membership, invoices or credit awards.</p>
    @forelse (array_reverse($history['versions']) as $version)
        <x-filament::section :heading="'Version '.$version['number'].' — '.$version['title']" heading-tag="h3">
            <p>Created (UTC): {{ $version['created_at'] }}.</p>
            <dl style="display: grid; gap: 0.5rem;">
                <dt>Credit unit</dt><dd>{{ $version['policy']['unit'] }}</dd>
                <dt>Credits per synthetic award</dt><dd>{{ $version['policy']['allowance'] }}</dd>
                <dt>Validity</dt><dd>{{ $version['policy']['validity_seconds'] === null ? 'No expiry' : $version['policy']['validity_seconds'].' seconds' }}</dd>
                <dt>Rollover</dt><dd>No rollover for this test bucket</dd>
                <dt>Full reversal</dt><dd>{{ $version['policy']['reversal_allowed'] ? 'Allowed' : 'Denied' }}</dd>
            </dl>
        </x-filament::section>
    @empty
        <p role="status">No retained private versions are available.</p>
    @endforelse
</div>
