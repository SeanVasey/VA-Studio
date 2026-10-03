<div style="display: grid; gap: 1rem; overflow-wrap: anywhere;">
    @if ($review !== null)
        <h3 style="font-weight: 600;">Captured evidence for declaration #{{ $review['declaration_id'] }}</h3>
        <p>Track: {{ $review['display']['track_title'] }} (#{{ $review['track_id'] }})</p>
        @foreach (['provenance_reference' => 'Captured provenance reference', 'sample_disclosure' => 'Captured sample disclosure'] as $field => $label)
            <div>
                <h4 style="font-weight: 600;">{{ $label }}</h4>
                <p style="white-space: pre-wrap;">{{ $review['display'][$field] }}</p>
            </div>
        @endforeach
        <p>This review applies once. If the declaration or track changes before submitting, close and reopen it to review current evidence.</p>
    @else
        <p role="status">This review has been used or cleared. Close and reopen the declaration to review current evidence before trying again.</p>
    @endif
</div>
