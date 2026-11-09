@php
    $labels = ['in_progress' => 'In progress (processing queued or running)', 'failed_processing' => 'Failed processing',
        'attached' => 'Attached (a reference was found)', 'unattached' => 'Unattached (proven, bounded proof set only)',
        'unknown' => 'Unknown (no reference found, not proven)'];
    $bytes = fn (?int $value): string => $value === null ? 'unknown' : number_format($value);
@endphp
<x-filament-panels::page>
    <p>Read-only. Nothing on this page deletes, moves or changes media. Sizes are recorded metadata, not disk usage. A family is one uploaded source with its derived assets.</p>
    <form wire:submit="$refresh" style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: end;">
        <div>
            <label for="lifecycle-age" style="display: block; font-weight: 600;">Unattached proof: minimum age (days)</label>
            <input id="lifecycle-age" type="number" min="1" max="3650" wire:model="olderThanDays" required style="min-height: 44px;">
        </div>
        <button type="submit" class="fi-btn fi-color fi-size-md fi-btn-color-primary" style="padding: .5rem 1rem; font-weight: 600; min-height: 44px;">Apply</button>
    </form>

    @if ($error)
        <p role="alert" style="font-weight: 600;">{{ $error }}</p>
    @else
        <section aria-labelledby="lifecycle-states">
            <h2 id="lifecycle-states" style="font-size: 1.25rem; font-weight: 700;">Media families by state</h2>
            <table>
                <caption>Families and recorded bytes per state</caption>
                <thead><tr><th scope="col">State</th><th scope="col">Families</th><th scope="col">Recorded bytes</th></tr></thead>
                <tbody>
                @foreach ($lifecycle['states'] as $state => $total)
                    <tr data-state="{{ $state }}"><th scope="row">{{ $labels[$state] }}</th><td>{{ $total['families'] }}</td><td>{{ $bytes($total['recorded_bytes']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
            @if ($lifecycle['families_bounded'])
                <p>Only the first {{ \App\Domain\Media\Lifecycle\MediaLifecycleInventory::MAX_FAMILIES }} families were classified; the rest are not counted.</p>
            @endif
        </section>

        <section aria-labelledby="lifecycle-sessions">
            <h2 id="lifecycle-sessions" style="font-size: 1.25rem; font-weight: 700;">Upload sessions (not yet assets)</h2>
            <table>
                <caption>Resumable upload sessions and received transport bytes</caption>
                <thead><tr><th scope="col">Kind</th><th scope="col">Active</th><th scope="col">Expired, awaiting sweep</th><th scope="col">Cleanup pending</th></tr></thead>
                <tbody>
                @foreach ($lifecycle['sessions'] as $kind => $states)
                    <tr data-sessions="{{ $kind }}"><th scope="row">{{ $kind === 'media' ? 'Track media' : 'Sound kits' }}</th>
                        @foreach (['active', 'expired', 'cleanup_pending'] as $state)
                            <td>{{ $states[$state]['sessions'] }} ({{ $bytes($states[$state]['received_bytes']) }} bytes)</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p>Expired sessions are cleaned by <code>php artisan vasey:upload-sessions-sweep</code> (dry run unless <code>--execute</code>).</p>
        </section>

        @php $candidates = $lifecycle['candidates']; @endphp
        <section aria-labelledby="lifecycle-unattached">
            <h2 id="lifecycle-unattached" style="font-size: 1.25rem; font-weight: 700;">Unattached completed media older than {{ $candidates['older_than_days'] }} day(s): dry run</h2>
            <p>Coverage: {{ $candidates['coverage'] }}.@if ($candidates['blockers'] !== []) Not provable while these hold data: {{ implode(', ', $candidates['blockers']) }}.@endif</p>
            @if ($candidates['families'] === [])
                <p data-empty="unattached">No completed family without a structural reference was proven.</p>
            @else
                <table>
                    <caption>Bounded proof set ({{ \App\Domain\Media\Lifecycle\MediaReferenceProof::MAX_PROOF_FAMILIES }} families at most); deletion is not available</caption>
                    <thead><tr><th scope="col">Source asset</th><th scope="col">Result</th><th scope="col">Assets (roles)</th><th scope="col">Recorded bytes</th><th scope="col">Checks with zero references</th></tr></thead>
                    <tbody>
                    @foreach ($candidates['families'] as $family)
                        <tr data-family="{{ $family['id'] }}"><th scope="row">#{{ $family['id'] }}</th><td>{{ $family['state'] }}</td>
                            <td>{{ implode(', ', $family['members']) }} ({{ implode(', ', $family['roles']) }})</td><td>{{ $bytes($family['recorded_bytes']) }}</td>
                            <td>{{ count($family['zero_checks']) }}@if ($family['referencing_checks'] !== []); referenced by {{ implode(', ', array_keys($family['referencing_checks'])) }}@endif</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section aria-labelledby="lifecycle-page">
            <h2 id="lifecycle-page" style="font-size: 1.25rem; font-weight: 700;">Assets by role and status (this page of {{ count($page['assets']) }})</h2>
            <table>
                <caption>Recorded bytes by role and status</caption>
                <thead><tr><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Assets</th><th scope="col">Recorded bytes</th></tr></thead>
                <tbody>
                @foreach ($page['bytes_by_role_status'] as $group)
                    <tr><th scope="row">{{ $group['role'] }}</th><td>{{ $group['status'] }}</td><td>{{ $group['asset_count'] }}</td><td>{{ $bytes($group['recorded_bytes']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
            @if ($page['has_more'])
                <button type="button" wire:click="nextPage({{ $page['next_after_id'] }})" class="fi-btn fi-color fi-size-md" style="min-height: 44px;">Next page</button>
            @endif
        </section>
    @endif
</x-filament-panels::page>
