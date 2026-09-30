{{-- Filament's stylesheet does not compile Tailwind utilities used in custom views, so this table carries its own spacing. --}}
@php($cell = 'padding: 0.5rem 0.75rem; vertical-align: top;')
<div style="display: grid; gap: 1rem;">
    <p>Every scheduled publication is retained. Times are UTC. A schedule publishes only through the same checks as Publish release.</p>
    @if ($rows === [])
        <p>No publication has been scheduled.</p>
    @else
        {{-- Focusable so keyboard users can scroll the table sideways in narrow windows. --}}
        <div style="overflow-x: auto;" tabindex="0" role="region" aria-label="Schedule history table">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.875rem; line-height: 1.25rem;">
                <thead>
                    <tr>
                        <th scope="col" style="{{ $cell }} font-weight: 600;">Schedule</th>
                        <th scope="col" style="{{ $cell }} font-weight: 600;">Release</th>
                        <th scope="col" style="{{ $cell }} font-weight: 600;">Publish at</th>
                        <th scope="col" style="{{ $cell }} font-weight: 600;">State</th>
                        <th scope="col" style="{{ $cell }} font-weight: 600;">Outcome</th>
                        <th scope="col" style="{{ $cell }} font-weight: 600;">Scheduled by</th>
                        <th scope="col" style="{{ $cell }} font-weight: 600;">Resolved</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr style="border-top: 1px solid color-mix(in srgb, currentColor 20%, transparent);">
                            <td style="{{ $cell }}">#{{ $row['id'] }}</td>
                            <td style="{{ $cell }}">{{ $row['release'] }}</td>
                            <td style="{{ $cell }} white-space: nowrap;">{{ $row['publish_at'] }}</td>
                            <td style="{{ $cell }}">{{ $row['state'] }}</td>
                            <td style="{{ $cell }}">{{ $row['reason'] }}@if ($row['revision'] !== null) (publication revision {{ $row['revision'] }})@endif</td>
                            <td style="{{ $cell }}">{{ $row['scheduled_by'] }}</td>
                            <td style="{{ $cell }}">{{ $row['resolved'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
