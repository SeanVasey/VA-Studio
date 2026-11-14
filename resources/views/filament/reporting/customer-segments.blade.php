<x-filament-panels::page>
    @if ($error)
        <p role="alert" style="font-weight: 600;">{{ $error }}</p>
    @else
        <p style="font-weight: 600;">{{ $overview['environment'] }}</p>
        <ul>
            @foreach ($overview['notes'] as $note)
                <li>{{ $note }}</li>
            @endforeach
        </ul>
        <p data-flags>
            Segmentation: {{ $overview['flags']['enabled'] ? 'on' : 'off' }};
            export: {{ $overview['flags']['export_enabled'] ? 'on' : 'off' }};
            marketing segment: {{ $overview['flags']['marketing_enabled'] ? 'on' : 'off' }}.
        </p>
        @if (! $overview['available'])
            <p data-unavailable="segments">{{ $overview['reason'] }}</p>
        @else
            <p>{{ $overview['accounts'] }} customer account(s) in scope. Counts only; rows leave the application through the audited export.</p>
            <div>
                <label for="segment-select" style="display: block; font-weight: 600;">Segment to export</label>
                <select id="segment-select" wire:model.live="segment">
                    @foreach ($overview['segments'] as $key => $segment)
                        <option value="{{ $key }}">{{ $segment['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <table>
                <caption>Segments</caption>
                <thead><tr><th scope="col">Segment</th><th scope="col">Accounts</th><th scope="col">Note</th></tr></thead>
                <tbody>
                @foreach ($overview['segments'] as $key => $segment)
                    <tr data-segment="{{ $key }}"><th scope="row">{{ $segment['label'] }}</th><td>{{ $segment['count'] ?? 'Unavailable' }}</td><td>{{ $segment['reason'] ?? ($segment['marketing'] ? 'Verified granted consent only.' : '') }}</td></tr>
                @endforeach
                </tbody>
            </table>
            <table>
                <caption>Marketing email consent state (all accounts)</caption>
                <thead><tr><th scope="col">State</th><th scope="col">Accounts</th></tr></thead>
                <tbody>
                @foreach ($overview['consent'] as $state => $count)
                    <tr data-consent="{{ $state }}"><th scope="row">{{ $consentLabels[$state] }}</th><td>{{ $count }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endif
</x-filament-panels::page>
