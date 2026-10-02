@php
    $track = $review['track'];
    $metadata = [
        'id' => 'Track ID', 'metadata_version' => 'Metadata revision', 'title' => 'Title', 'slug' => 'URL',
        'artist' => 'Artist', 'bpm' => 'BPM', 'musical_key' => 'Musical key', 'genre' => 'Genre', 'mood' => 'Mood',
    ];
@endphp
<div style="display: grid; gap: 1.5rem; min-width: 0; overflow-wrap: anywhere;">
    <p>Private staff review. This track keeps its current publication state.</p>
    <p>Status: {{ $track['status'] }}</p>

    <x-filament::section heading="Metadata" heading-tag="h3" role="group" aria-label="Metadata">
        <div style="display: grid; gap: 1rem; min-width: 0;">
            <dl style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr)); gap: 1rem;">
                @foreach ($metadata as $field => $label)
                    <div>
                        <dt style="font-weight: 600;">{{ $label }}</dt>
                        <dd>{{ $track[$field] ?? 'Not set' }}</dd>
                    </div>
                @endforeach
            </dl>
            <div>
                <h4 style="font-weight: 600;">Tags</h4>
                <ul aria-label="Track tags" style="list-style: disc; list-style-position: inside;">
                    @forelse ($track['tags'] ?? [] as $tag)
                        <li>{{ $tag }}</li>
                    @empty
                        <li>No tags</li>
                    @endforelse
                </ul>
            </div>
            <div>
                <h4 style="font-weight: 600;">Description</h4>
                <p style="white-space: pre-wrap;">{{ $track['description'] ?? 'Not set' }}</p>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Publication readiness" heading-tag="h3" role="group" aria-label="Publication readiness">
        @if ($review['readiness']['blockers'] === [])
            <p>Ready to publish</p>
        @else
            <ul aria-label="Publication blockers" style="list-style: disc; list-style-position: inside;">
                @foreach ($review['readiness']['blockers'] as $blocker)
                    <li>{{ $blocker }}</li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament::section heading="Artwork" heading-tag="h3" role="group" aria-label="Artwork">
        @if ($review['artwork']['state'] === 'available')
            <div x-data="{ unavailable: false }">
                <img src="{{ $review['artwork']['url'] }}" alt="Artwork for {{ $track['title'] }}"
                    x-show="!unavailable" x-on:error="unavailable = true"
                    style="display: block; max-width: 100%; height: auto;" />
                <p x-show="unavailable" x-cloak role="status">Artwork unavailable</p>
            </div>
        @else
            <p>Artwork unavailable</p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Tagged preview" heading-tag="h3" role="group" aria-label="Tagged preview">
        @if ($review['preview_tagged']['state'] === 'available')
            <div x-data="{ unavailable: false }">
                <audio src="{{ $review['preview_tagged']['url'] }}" controls preload="none"
                    aria-label="Tagged preview for {{ $track['title'] }}" x-show="!unavailable" x-on:error="unavailable = true"
                    style="display: block; width: 100%; max-width: 100%;"></audio>
                <p x-show="unavailable" x-cloak role="status">Tagged preview unavailable</p>
            </div>
        @else
            <p>Tagged preview unavailable</p>
        @endif
    </x-filament::section>
</div>
