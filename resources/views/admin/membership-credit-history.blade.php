<div style="display: grid; gap: 1rem; overflow-wrap: anywhere; min-width: 0;">
    <p>Test account #{{ $history['account_id'] }}. Synthetic bucket #{{ $history['bucket_id'] }}. Verified through event #{{ $history['last_event_id'] }} at {{ $history['checked_at'] }} UTC.</p>
    <p>Spendable {{ $history['unit'] }} at this captured read: {{ $history['spendable_credits'] }}. Close and reopen to check a newer state.</p>
    <p>Retained expiry (UTC): {{ $history['expires_at'] ?? 'No expiry' }}. This view does not confirm an active membership, an invoice or rights to any music.</p>
    <dl style="display: grid; gap: 0.5rem;">
        @foreach ($history['balance'] as $kind => $amount)
            <dt>{{ ucfirst($kind) }}</dt><dd>{{ $amount }}</dd>
        @endforeach
    </dl>
    <div style="overflow-x: auto;">
        <table aria-label="Retained synthetic credit events" style="width: 100%; text-align: left;">
            <thead><tr><th>Event</th><th>Movement</th><th>Credits</th><th>Recorded (UTC)</th></tr></thead>
            <tbody>
                @forelse ($history['events'] as $event)
                    <tr><td>{{ $event['id'] }}</td><td>{{ ucfirst($event['kind']) }}</td><td>{{ $event['amount'] }}</td><td>{{ $event['created_at'] }}</td></tr>
                @empty
                    <tr><td colspan="4">No retained credit movements are available.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
