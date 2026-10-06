@if ($review === null)
    <p role="status">This comparison is no longer available. Keep a copy of your entered source, then close and reopen the selected drafts to inspect their saved state and review again.</p>
@else
    @php
        $changed = count(array_filter($review['drafts'], fn ($draft) => $draft['before']['authored_source'] !== $draft['after']['authored_source']));
        $unchanged = count($review['drafts']) - $changed;
    @endphp
    <div style="display: grid; gap: 1.5rem; overflow-wrap: anywhere; min-width: 0;">
        <p>{{ $changed }} drafts will change; {{ $unchanged }} already match this source.</p>
        <p>Only authored source changes. Structured terms and availability dates remain exactly as shown. This does not approve, publish or change previously purchased terms.</p>
        @foreach ($review['drafts'] as $draft)
            <x-filament::section :heading="$draft['template']['name'].' — version '.$draft['version']" heading-tag="h3" role="group" aria-label="{{ $draft['template']['name'] }} version {{ $draft['version'] }}">
                <div style="display: grid; gap: 1rem; min-width: 0;">
                    <p>Template ID: {{ $draft['template_id'] }}. Draft ID: {{ $draft['version_id'] }}.</p>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr)); gap: 1rem; min-width: 0;">
                        @foreach (['before' => 'Current source', 'after' => 'Proposed source'] as $side => $label)
                            <div style="min-width: 0;">
                                <h4 style="font-weight: 600;">{{ $label }}</h4>
                                <pre aria-label="{{ $label }} for draft {{ $draft['version_id'] }}" style="white-space: pre-wrap; overflow-wrap: anywhere; font-family: inherit;">{{ $draft[$side]['authored_source'] }}</pre>
                            </div>
                        @endforeach
                    </div>
                    <details>
                        <summary>Retained terms and availability for draft {{ $draft['version_id'] }}</summary>
                        <p>Availability starts (UTC): {{ $draft['before']['effective_from'] ?? 'On publication' }}.</p>
                        <p>Availability ends (UTC): {{ $draft['before']['effective_until'] ?? 'No scheduled end' }}.</p>
                        <pre aria-label="Unchanged structured terms for draft {{ $draft['version_id'] }}" style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ json_encode($draft['before']['structured_terms'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}</pre>
                    </details>
                </div>
            </x-filament::section>
        @endforeach
    </div>
@endif
