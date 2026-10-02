@if ($review !== null)
    @php
        $changed = count(array_filter($review['tracks'], fn ($track) => $track['before_metadata'] !== $track['after_metadata']));
        $unchanged = count($review['tracks']) - $changed;
        $labels = \App\Filament\Resources\TrackResource::bulkMetadataLabels();
        $choices = ['keep' => 'Keep', 'set' => 'Set', 'clear' => 'Clear'];
    @endphp
    <div style="display: grid; gap: 1.5rem; min-width: 0; overflow-wrap: anywhere;">
        <p>{{ $changed }} tracks will change; {{ $unchanged }} already match these choices.</p>
        <p>Review each current and proposed value. Titles, URLs, tags, descriptions, media, publication, prices and licenses stay as they are. If a selected track changes before saving, review the whole selection again.</p>
        @foreach ($review['tracks'] as $track)
            <x-filament::section :heading="$track['title']" heading-tag="h3" role="group" aria-label="{{ $track['title'] }}">
                <div style="display: grid; gap: 1rem; min-width: 0;">
                    <p>Track ID: {{ $track['id'] }}</p>
                    <div role="region" aria-label="{{ $track['title'] }} metadata comparison" tabindex="0" style="max-width: 100%; overflow-x: auto;">
                        <table style="width: 100%; min-width: 28rem; border-collapse: collapse; text-align: left;">
                            <caption style="text-align: left; margin-bottom: 0.5rem;">Metadata comparison</caption>
                            <thead>
                                <tr>
                                    @foreach (['Field', 'Choice', 'Current metadata', 'Proposed metadata'] as $heading)
                                        <th scope="col" style="padding: 0.5rem;">{{ $heading }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($labels as $field => $label)
                                    <tr>
                                        <th scope="row" style="padding: 0.5rem;">{{ $label }}</th>
                                        <td style="padding: 0.5rem;">{{ $choices[$review['changes'][$field]['mode']] }}</td>
                                        <td style="padding: 0.5rem;">{{ $track['before_metadata'][$field] ?? 'No value' }}</td>
                                        <td style="padding: 0.5rem;">{{ $track['after_metadata'][$field] ?? 'No value' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>
@endif
