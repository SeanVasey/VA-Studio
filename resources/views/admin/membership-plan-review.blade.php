@if ($review === null || $before === null)
    <p role="status">This comparison is exhausted. Keep a copy of your entered policy, then use Back to policy or reopen to inspect saved versions and explicitly review again.</p>
@else
    <div style="display: grid; gap: 1rem; overflow-wrap: anywhere; min-width: 0;">
        <p>Private plan #{{ $review['plan_id'] }}. Captured version {{ $before['number'] }}.</p>
        <p>Applying this review retains earlier versions and every existing credit bucket's original policy. It does not enroll an account, bill an invoice or award credits.</p>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr)); gap: 1rem; min-width: 0;">
            @foreach (['Current captured policy' => ['title' => $before['title'], 'policy' => $before['policy']], 'Proposed policy' => $review['replacement']] as $label => $data)
                <x-filament::section :heading="$label" heading-tag="h3">
                    <dl style="display: grid; gap: 0.5rem;">
                        <dt>Title</dt><dd>{{ $data['title'] }}</dd>
                        <dt>Credit unit</dt><dd>{{ $data['policy']['unit'] }}</dd>
                        <dt>Credits per synthetic award</dt><dd>{{ $data['policy']['allowance'] }}</dd>
                        <dt>Validity</dt><dd>{{ $data['policy']['validity_seconds'] === null ? 'No expiry' : $data['policy']['validity_seconds'].' seconds' }}</dd>
                        <dt>Rollover</dt><dd>No rollover for this test bucket</dd>
                        <dt>Full reversal</dt><dd>{{ $data['policy']['reversal_allowed'] ? 'Allowed' : 'Denied' }}</dd>
                    </dl>
                </x-filament::section>
            @endforeach
        </div>
    </div>
@endif
