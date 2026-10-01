@if ($review !== null)
    @php
        $changed = count(array_filter($review['tracks'], fn ($track) => $track['before_tags'] !== $track['after_tags']));
        $unchanged = count($review['tracks']) - $changed;
    @endphp
    <div style="display: grid; gap: 1.5rem; overflow-wrap: anywhere;">
        <p>{{ $changed }} tracks will change; {{ $unchanged }} already contain these tags.</p>
        <p>Only tags will change. If any selected track changes before saving, review the whole selection again.</p>
        @foreach ($review['tracks'] as $track)
            <x-filament::section :heading="$track['title']" heading-tag="h3" role="group" aria-label="{{ $track['title'] }}">
                <div style="display: grid; gap: 1rem;">
                    <p>Track ID: {{ $track['id'] }}</p>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: 1rem;">
                        @foreach (['before_tags' => 'Current tags', 'after_tags' => 'Proposed tags'] as $key => $label)
                            <div>
                                <h4 style="font-weight: 600;">{{ $label }}</h4>
                                <ul aria-label="{{ $label }}" style="list-style: disc; list-style-position: inside;">
                                    @forelse ($track[$key] as $tag)
                                        <li>{{ $tag }}</li>
                                    @empty
                                        <li>No tags</li>
                                    @endforelse
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>
@endif
